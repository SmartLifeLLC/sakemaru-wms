<?php

namespace App\Services\AutoOrder\IncomingParsers;

use App\Contracts\IncomingFormatParserInterface;
use App\Models\WmsIncomingImportError;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * アクト中食（code 1497）CSVパーサー
 *
 * CSV仕様: Shift_JIS, カンマ区切り, ヘッダーあり, 65カラム
 * 伝票No でグルーピングし、伝票→明細の3層構造に保存
 */
class ActCsvIncomingParser implements IncomingFormatParserInterface
{
    private const ENCODING = 'Shift_JIS';

    private const PARTNER_CODE = '1497';

    /** 送料JANコード */
    private const SHIPPING_JAN_CODE = '9999999999996';

    /** CSVカラムインデックス（0-indexed） */
    private const COL_SHOP_CODE = 0;       // 得意先コード

    private const COL_SLIP_DATE = 1;       // 伝票日付

    private const COL_SLIP_NUMBER = 2;     // 伝票No

    private const COL_LINE_NUMBER = 4;     // 行No

    private const COL_PRODUCT_CODE = 6;    // 商品コード

    private const COL_PRODUCT_NAME = 7;    // 商品名１

    private const COL_PACK_QUANTITY = 11;  // 入数１

    private const COL_CASE_QUANTITY = 13;  // ケース数

    private const COL_TOTAL_QUANTITY = 15; // 数量

    private const COL_SELL_PRICE = 19;     // 売価単価

    private const COL_JAN_CODE = 29;       // JANコード

    private const COL_SUPPLIER_CODE = 57;  // 発注仕入先コード

    /** partner_item_code → item_id のマッピングキャッシュ */
    private array $itemMapping = [];

    public function parse(string $content, string $filename, ?int $contractorId = null): WmsIncomingReceivedFile
    {
        // Shift_JIS → UTF-8
        $utf8Content = mb_convert_encoding($content, 'UTF-8', self::ENCODING);
        if ($utf8Content === false) {
            $utf8Content = $content;
        }

        // 商品マッピングをプリロード
        $this->preloadItemMapping();

        // CSV行をパース
        $lines = $this->parseCsvLines($utf8Content);

        // 伝票Noでグルーピング
        $slipGroups = [];
        foreach ($lines as $row) {
            $slipNumber = trim($row[self::COL_SLIP_NUMBER] ?? '');
            if ($slipNumber === '') {
                continue;
            }
            $slipGroups[$slipNumber][] = $row;
        }

        // ファイルレコード作成
        $fileRecord = WmsIncomingReceivedFile::create([
            'contractor_id' => $contractorId,
            'filename' => $filename,
            'format_type' => 'CSV',
            'status' => 'PENDING',
            'parsed_slip_count' => count($slipGroups),
            'parsed_detail_count' => count($lines),
        ]);

        // 各グループを保存
        foreach ($slipGroups as $slipNumber => $rows) {
            $this->saveSlipGroup($fileRecord, (string) $slipNumber, $rows);
        }

        Log::info('[ActCsvIncomingParser] パース完了', [
            'file_id' => $fileRecord->id,
            'filename' => $filename,
            'slip_count' => count($slipGroups),
            'detail_count' => count($lines),
        ]);

        return $fileRecord;
    }

    /**
     * CSV行をパース（ヘッダースキップ）
     */
    private function parseCsvLines(string $content): array
    {
        $lines = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $isHeader = true;
        while (($row = fgetcsv($stream)) !== false) {
            if ($isHeader) {
                $isHeader = false;

                continue;
            }
            if (empty(array_filter($row))) {
                continue;
            }
            $lines[] = $row;
        }

        fclose($stream);

        return $lines;
    }

    /**
     * partner_item_code → item_id のマッピングをプリロード
     */
    private function preloadItemMapping(): void
    {
        // パートナーIDを取得
        $partnerId = DB::connection('sakemaru')
            ->table('partners')
            ->where('code', self::PARTNER_CODE)
            ->where('is_supplier', true)
            ->value('id');

        if (! $partnerId) {
            Log::warning('[ActCsvIncomingParser] パートナーコード 1497 が見つかりません');

            return;
        }

        // item_connections からマッピング取得
        $connections = DB::connection('sakemaru')
            ->table('item_connections')
            ->where('partner_id', $partnerId)
            ->whereNotNull('partner_item_code')
            ->get(['partner_item_code', 'item_id']);

        foreach ($connections as $conn) {
            $this->itemMapping[$conn->partner_item_code] = $conn->item_id;
        }

        Log::info('[ActCsvIncomingParser] 商品マッピングロード完了', [
            'partner_id' => $partnerId,
            'count' => count($this->itemMapping),
        ]);
    }

    /**
     * 伝票グループを保存
     */
    private function saveSlipGroup(WmsIncomingReceivedFile $file, string $slipNumber, array $rows): void
    {
        $firstRow = $rows[0];

        // 日付パース（YYYY/MM/DD形式）
        $slipDate = $firstRow[self::COL_SLIP_DATE] ?? null;
        $shopCode = trim($firstRow[self::COL_SHOP_CODE] ?? '');

        $contractorCode = trim($firstRow[28] ?? ''); // 仕入先コード（col 28）

        $slip = WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id,
            'slip_number' => $slipNumber,
            'match_status' => 'PENDING',
            'b_shop_code' => $shopCode,
            'b_contractor_code' => $contractorCode,
            'b_delivery_date' => $slipDate,
            'b_order_date' => $slipDate,
            'detail_count' => 0,
            'shortage_count' => 0,
        ]);

        foreach ($rows as $row) {
            $this->saveDetail($file, $slip, $row);
        }
    }

    /**
     * 明細行を保存
     */
    private function saveDetail(WmsIncomingReceivedFile $file, WmsIncomingReceivedSlip $slip, array $row): void
    {
        $productCode = trim($row[self::COL_PRODUCT_CODE] ?? '');
        $janCode = trim($row[self::COL_JAN_CODE] ?? '');
        $supplierCode = trim($row[self::COL_SUPPLIER_CODE] ?? '');
        $productName = trim($row[self::COL_PRODUCT_NAME] ?? '');
        $lineNumber = (int) ($row[self::COL_LINE_NUMBER] ?? 0);
        $packQty = (int) ($row[self::COL_PACK_QUANTITY] ?? 0);
        $caseQty = (int) ($row[self::COL_CASE_QUANTITY] ?? 0);
        $totalQty = (int) ($row[self::COL_TOTAL_QUANTITY] ?? 0);
        $sellPrice = (float) ($row[self::COL_SELL_PRICE] ?? 0);

        // JANコード: 優先順位 col 29(JANコード) → col 6(商品コード)
        $janCodeFinal = $janCode ?: $productCode;

        // 商品マッピング: 商品コード → item_connections.partner_item_code
        $itemId = $this->itemMapping[$productCode] ?? null;

        // item_connections でマッチしない場合は item_search_information で再試行
        if (! $itemId && $janCodeFinal) {
            $itemId = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('search_string', $janCodeFinal)
                ->value('item_id');
        }

        $isShortage = $totalQty === 0;

        $detail = WmsIncomingReceivedDetail::create([
            'received_slip_id' => $slip->id,
            'received_file_id' => $file->id,
            'd_line_number' => $lineNumber,
            'd_product_name' => $productName,
            'd_jan_code' => $janCodeFinal,
            'd_item_code' => $supplierCode,
            'd_pack_quantity' => $packQty,
            'd_case_quantity' => $caseQty,
            'd_piece_quantity' => $totalQty,
            'd_unit_price' => (int) ($sellPrice * 100), // JXと同じく下2桁小数の整数表現
            'total_quantity' => $totalQty,
            'is_shortage' => $isShortage,
            'matched_item_id' => $itemId,
            'match_status' => $itemId ? 'PENDING' : 'NOT_FOUND',
        ]);

        // 伝票カウント更新
        $slip->increment('detail_count');
        if ($isShortage) {
            $slip->increment('shortage_count');
        }

        // 商品不明 → エラー記録（送料は除外）
        if (! $itemId && $janCodeFinal !== self::SHIPPING_JAN_CODE) {
            WmsIncomingImportError::create([
                'received_file_id' => $file->id,
                'received_slip_id' => $slip->id,
                'received_detail_id' => $detail->id,
                'error_type' => 'ERROR',
                'error_code' => 'ITEM_NOT_FOUND',
                'error_message' => "商品を特定できません: 商品CD={$productCode}, JAN={$janCodeFinal}",
                'item_code' => $janCodeFinal,
                'raw_data' => array_map('trim', $row),
            ]);
        }
    }
}
