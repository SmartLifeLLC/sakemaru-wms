<?php

namespace App\Services\JX;

use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * JX送信テスト用ファイル生成サービス
 *
 * テストパターン:
 * 1. 空ファイル: 発注データなしでJX送信テスト
 * 2. 全商品ファイル: 発注先の全商品を含むファイル
 * 3. 集約テスト: 複数発注先のデータを1ファイルに集約
 */
class JxTestFileGenerator
{
    private const ENCODING = 'SJIS';

    private const RECORD_LENGTH = 128;

    /**
     * 送信先集約マッピング（発注先コード => 発注データ集約先コード）
     * カナカン系は1106に集約
     */
    private const AGGREGATION_MAPPING = [
        1021 => 1106,  // カナカン酒類福井 → カナカン食品
        1029 => 1106,  // カナカンフローズン → カナカン食品
        1068 => 1106,  // カナカン酒類金沢 → カナカン食品
        1126 => 1106,  // カナカン日配 → カナカン食品
        1127 => 1106,  // カナカン菓子 → カナカン食品
        1680 => 1106,  // カナカン金沢酒類 → カナカン食品
    ];

    /**
     * 社名（固定）
     */
    private const COMPANY_NAME = 'ﾘｶｰﾜｰﾙﾄﾞ ﾊﾅ';

    private array $costPriceCache = [];

    private array $janCodeCache = [];

    /**
     * パターン1: 空のJXファイル生成
     */
    public function generateEmptyFile(int $jxSettingId): array
    {
        $jxSetting = WmsOrderJxSetting::findOrFail($jxSettingId);

        Log::info('[JxTestFileGenerator] 空ファイル生成開始', [
            'jx_setting_id' => $jxSettingId,
            'add_zero_record' => $jxSetting->add_zero_record,
        ]);

        // add_zero_record=true: Aレコードのみ、add_zero_record=false: レコードなし（JXラッパーのみ）
        $content = $jxSetting->add_zero_record
            ? $this->generateARecord($jxSetting, 1, 0)
            : '';

        // JXラッパー（開始行"1" + 終了行"8"）を追加（UTF-8のまま）
        $wrapper = new JxDataWrapper($jxSetting);
        $content = $wrapper->wrap($content);

        // 最後にShift_JIS変換
        $sjisContent = mb_convert_encoding($content, self::ENCODING, 'UTF-8');

        $filename = $this->generateFilename($jxSetting, 'empty');
        $savePath = $this->saveFile($filename, $sjisContent);

        return [
            'pattern' => 'empty',
            'jx_setting_id' => $jxSettingId,
            'jx_setting_name' => $jxSetting->name,
            'filename' => $filename,
            'file_path' => $savePath,
            'file_size' => strlen($sjisContent),
            'record_count' => 1,
            'order_count' => 0,
            'content' => $sjisContent,
        ];
    }

    /**
     * パターン2: 全商品発注ファイル生成
     *
     * @param  int  $jxSettingId  JX設定ID
     * @param  int|null  $warehouseId  倉庫ID（null時はデフォルト倉庫）
     * @param  int|null  $maxItems  最大商品数（nullで全商品）
     */
    public function generateFullOrderFile(int $jxSettingId, ?int $warehouseId = null, ?int $maxItems = null): array
    {
        $jxSetting = WmsOrderJxSetting::findOrFail($jxSettingId);

        Log::info('[JxTestFileGenerator] 全商品ファイル生成開始', [
            'jx_setting_id' => $jxSettingId,
            'warehouse_id' => $warehouseId,
            'max_items' => $maxItems ?? 'all',
        ]);

        // JX設定に紐づく発注先を取得
        $contractorIds = $this->getContractorIdsForJxSetting($jxSettingId);

        if (empty($contractorIds)) {
            throw new \RuntimeException("JX設定 {$jxSettingId} に紐づく発注先がありません");
        }

        // 倉庫を取得（指定がなければデフォルトの倉庫）
        $warehouse = $warehouseId
            ? Warehouse::findOrFail($warehouseId)
            : Warehouse::where('is_active', true)->first();

        // 発注先の全商品を取得（倉庫でフィルタ）
        $query = ItemContractor::whereIn('contractor_id', $contractorIds)
            ->where('warehouse_id', $warehouse->id)
            ->with(['item', 'contractor']);

        if ($maxItems !== null) {
            $query->limit($maxItems);
        }

        $itemContractors = $query->get();

        if ($itemContractors->isEmpty()) {
            // 商品がない場合は空ファイルを生成
            return $this->generateEmptyFile($jxSettingId);
        }

        // 仮の発注データを作成
        $testOrders = $this->createTestOrders($itemContractors, $warehouse);

        // ファイル内容を生成
        $content = $this->generateFileContent($jxSetting, $testOrders);

        $filename = $this->generateFilename($jxSetting, 'full');
        $savePath = $this->saveFile($filename, $content);

        return [
            'pattern' => 'full',
            'jx_setting_id' => $jxSettingId,
            'jx_setting_name' => $jxSetting->name,
            'filename' => $filename,
            'file_path' => $savePath,
            'file_size' => strlen($content),
            'record_count' => $this->countRecords($testOrders),
            'order_count' => $testOrders->count(),
            'contractors' => $testOrders->pluck('contractor_code')->unique()->values()->toArray(),
            'content' => $content,
        ];
    }

    /**
     * パターン3: 送信先集約テストファイル生成（カナカンケース）
     *
     * @param  int  $jxSettingId  JX設定ID
     * @param  int|null  $warehouseId  倉庫ID（null時はデフォルト倉庫）
     * @param  int|null  $maxItemsPerContractor  発注先ごとの最大商品数（nullで全商品）
     */
    public function generateAggregatedFile(int $jxSettingId, ?int $warehouseId = null, ?int $maxItemsPerContractor = null): array
    {
        $jxSetting = WmsOrderJxSetting::findOrFail($jxSettingId);

        Log::info('[JxTestFileGenerator] 集約テストファイル生成開始', [
            'jx_setting_id' => $jxSettingId,
            'warehouse_id' => $warehouseId,
            'max_items_per_contractor' => $maxItemsPerContractor ?? 'all',
        ]);

        // JX設定に紐づく全発注先（集約元も含む）を取得
        $allContractorIds = $this->getAllContractorIdsForJxSetting($jxSettingId);

        if (empty($allContractorIds)) {
            throw new \RuntimeException("JX設定 {$jxSettingId} に紐づく発注先がありません");
        }

        // 倉庫を取得
        $warehouse = $warehouseId
            ? Warehouse::findOrFail($warehouseId)
            : Warehouse::where('is_active', true)->first();

        // 各発注先から商品を取得（倉庫でフィルタ）
        $allTestOrders = collect();
        foreach ($allContractorIds as $contractorId) {
            $query = ItemContractor::where('contractor_id', $contractorId)
                ->where('warehouse_id', $warehouse->id)
                ->with(['item', 'contractor']);

            if ($maxItemsPerContractor !== null) {
                $query->limit($maxItemsPerContractor);
            }

            $itemContractors = $query->get();

            if ($itemContractors->isNotEmpty()) {
                $testOrders = $this->createTestOrders($itemContractors, $warehouse);
                $allTestOrders = $allTestOrders->merge($testOrders);
            }
        }

        if ($allTestOrders->isEmpty()) {
            return $this->generateEmptyFile($jxSettingId);
        }

        // ファイル内容を生成
        $content = $this->generateFileContent($jxSetting, $allTestOrders);

        $filename = $this->generateFilename($jxSetting, 'aggregated');
        $savePath = $this->saveFile($filename, $content);

        // 発注先コード一覧
        $contractorCodes = $allTestOrders->pluck('contractor_code')->unique()->values()->toArray();

        return [
            'pattern' => 'aggregated',
            'jx_setting_id' => $jxSettingId,
            'jx_setting_name' => $jxSetting->name,
            'filename' => $filename,
            'file_path' => $savePath,
            'file_size' => strlen($content),
            'record_count' => $this->countRecords($allTestOrders),
            'order_count' => $allTestOrders->count(),
            'contractors' => $contractorCodes,
            'aggregated_from' => count($contractorCodes),
            'content' => $content,
        ];
    }

    /**
     * JX設定に紐づく発注先ID一覧を取得
     */
    private function getContractorIdsForJxSetting(int $jxSettingId): array
    {
        // wms_contractor_settingsからJX設定IDに紐づく発注先を取得
        $settings = WmsContractorSetting::where('wms_order_jx_setting_id', $jxSettingId)->get();

        // 発注データ集約先（transmission_contractor_id = null）のみ
        return $settings
            ->filter(fn ($s) => $s->transmission_contractor_id === null)
            ->pluck('contractor_id')
            ->toArray();
    }

    /**
     * JX設定に紐づく全発注先ID一覧（集約元も含む）を取得
     */
    private function getAllContractorIdsForJxSetting(int $jxSettingId): array
    {
        // まず発注データ集約先を取得
        $mainContractorIds = $this->getContractorIdsForJxSetting($jxSettingId);

        // 送信先に集約される発注先も取得
        $aggregatedSettings = WmsContractorSetting::whereIn('transmission_contractor_id', $mainContractorIds)->get();
        $aggregatedContractorIds = $aggregatedSettings->pluck('contractor_id')->toArray();

        return array_unique(array_merge($mainContractorIds, $aggregatedContractorIds));
    }

    /**
     * テスト用発注データを作成
     */
    private function createTestOrders(Collection $itemContractors, Warehouse $warehouse): Collection
    {
        $orders = collect();
        $orderDate = Carbon::now();
        $deliveryDate = $orderDate->copy()->addDays(2);

        foreach ($itemContractors as $itemContractor) {
            $item = $itemContractor->item;
            $contractor = $itemContractor->contractor;

            if (! $item || ! $contractor) {
                continue;
            }

            $orders->push((object) [
                'item_id' => $item->id,
                'item' => $item,
                'contractor_id' => $contractor->id,
                'contractor' => $contractor,
                'contractor_code' => $contractor->code,
                'warehouse_id' => $warehouse->id,
                'warehouse' => $warehouse,
                'order_quantity' => rand(1, 10),  // テスト用にランダム数量
                'expected_arrival_date' => $deliveryDate,
                'ordering_code' => $this->getOrderingCode($item->id),
            ]);
        }

        return $orders;
    }

    /**
     * ファイル内容を生成
     */
    private function generateFileContent(WmsOrderJxSetting $jxSetting, Collection $orders): string
    {
        $records = [];

        // 発注先×倉庫でグルーピング
        $grouped = $orders->groupBy(fn ($o) => "{$o->contractor_id}_{$o->warehouse_id}");

        // Bレコード数を計算（6件ずつ分割）
        $bCount = 0;
        foreach ($grouped as $groupOrders) {
            $bCount += (int) ceil($groupOrders->count() / 6);
        }

        $totalRecordCount = 1 + $bCount + $orders->count();

        // Aレコード
        $records[] = $this->generateARecord($jxSetting, $totalRecordCount, $bCount);

        // B/Dレコード
        $bRecordSeq = 1;
        foreach ($grouped as $groupOrders) {
            $chunks = $groupOrders->chunk(6);

            foreach ($chunks as $chunk) {
                $firstOrder = $chunk->first();
                $records[] = $this->generateBRecord($firstOrder, $bRecordSeq);

                $dRecordSeq = 1;
                foreach ($chunk as $order) {
                    $records[] = $this->generateDRecord($order, $dRecordSeq);
                    $dRecordSeq++;
                }
                $bRecordSeq++;
            }
        }

        // レコード連結（改行なし・128バイト固定長）
        $content = implode('', $records);

        // JXラッパー（開始行"1" + 終了行"8"）を追加（UTF-8のまま）
        $wrapper = new JxDataWrapper($jxSetting);
        $content = $wrapper->wrap($content);

        // 最後にShift_JIS変換
        return mb_convert_encoding($content, self::ENCODING, 'UTF-8');
    }

    /**
     * Aレコード（ファイルヘッダー）を生成 - 128バイト
     */
    private function generateARecord(WmsOrderJxSetting $jxSetting, int $totalRecordCount, int $slipCount): string
    {
        $now = Carbon::now();

        $senderStationCode = $jxSetting->sender_station_code ?? '01451019';
        $receiverStationCode = $jxSetting->receiver_station_code ?? '';

        $record = '';
        $record .= 'A';                                                      // 1: レコード区分
        $record .= '01';                                                     // 2-3: データ種別
        $record .= $now->format('Ymd');                                      // 4-11: データ処理日付
        $record .= $now->format('His');                                      // 12-17: データ処理時刻
        $record .= str_pad($senderStationCode, 8, '0', STR_PAD_LEFT);        // 18-25: データ送信元
        $record .= str_pad($receiverStationCode, 8, '0', STR_PAD_LEFT);      // 26-33: データ送信先
        $record .= str_pad((string) $totalRecordCount, 6, '0', STR_PAD_LEFT); // 34-39: レコード件数
        $record .= str_pad((string) $slipCount, 6, '0', STR_PAD_LEFT);       // 40-45: 帳票枚数
        $record .= $this->padToByteLength(self::COMPANY_NAME, 15);           // 46-60: 社名
        $record .= str_pad('', 68);                                          // 61-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * Bレコード（伝票ヘッダー）を生成 - 128バイト
     */
    private function generateBRecord(object $order, int $seq): string
    {
        $warehouse = $order->warehouse;
        $contractor = $order->contractor;
        $orderDate = Carbon::now();
        $deliveryDate = $order->expected_arrival_date ?? $orderDate->copy()->addDays(2);

        $slipNumber = $orderDate->format('Ymd').str_pad($seq, 3, '0', STR_PAD_LEFT);
        $warehouseCode = str_pad((string) ($warehouse?->code ?? ''), 4, '0', STR_PAD_LEFT);

        $record = '';
        $record .= 'B';                                                      // 1: レコード区分
        $record .= '01';                                                     // 2-3: データ種別
        $record .= $slipNumber;                                              // 4-14: 伝票番号（11桁）
        $record .= $warehouseCode;                                           // 15-18: 社・店コード
        $record .= '999';                                                    // 19-21: 分類コード
        $record .= '01';                                                     // 22-23: 伝票区分
        $record .= $orderDate->format('ymd');                                // 24-29: 発注日
        $record .= $deliveryDate->format('ymd');                             // 30-35: 納品日
        $record .= str_pad('', 3);                                           // 36-38: 便
        $record .= str_pad(substr($contractor?->code ?? '', 0, 4), 4);       // 39-42: 取引先コード
        $record .= $this->padToByteLengthRight($warehouse?->kana_name ?? '', 15); // 43-57: 店名
        $record .= $this->padToByteLengthRight($warehouse?->kana_name ?? '', 10); // 58-67: 納品場所
        $record .= str_pad('', 25);                                          // 68-92: 備考
        $record .= '00';                                                     // 93-94: 直送区分
        $record .= str_pad('', 34);                                          // 95-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * Dレコード（伝票明細）を生成 - 128バイト
     */
    private function generateDRecord(object $order, int $seq): string
    {
        $item = $order->item;
        $capacityCase = $item?->capacity_case ?? 1;
        $totalQty = $order->order_quantity;

        // ケース/バラ計算
        if ($capacityCase <= 1) {
            $caseQty = 0;
            $pieceQty = $totalQty;
        } else {
            $caseQty = intdiv($totalQty, $capacityCase);
            $pieceQty = 0;
        }

        $orderingCode = $order->ordering_code ?? '';
        $costPrice = $this->getCurrentCostPrice($item?->id, $capacityCase);
        $priceFormatted = (int) round($costPrice * 100);

        $record = '';
        $record .= 'D';                                                      // 1: レコード区分
        $record .= '01';                                                     // 2-3: データ種別
        $record .= str_pad((string) $seq, 2, '0', STR_PAD_LEFT);             // 4-5: 伝票行番号
        $record .= $this->padProductName($item?->name_main ?? '', 62).'  ';  // 6-69: 品名
        $record .= str_pad($orderingCode, 13);                               // 70-82: 発注コード
        $record .= str_pad(substr($item?->code ?? '', 0, 6), 6);             // 83-88: 自社コード
        $record .= str_pad((string) $capacityCase, 6, '0', STR_PAD_LEFT);    // 89-94: 仕入入数
        $record .= str_pad((string) $caseQty, 7, '0', STR_PAD_LEFT);         // 95-101: ケース数
        $record .= str_pad((string) $pieceQty, 7, '0', STR_PAD_LEFT);        // 102-108: バラ数量
        $record .= str_pad((string) $priceFormatted, 10, '0', STR_PAD_LEFT); // 109-118: 原単価
        $record .= str_pad('', 10);                                          // 119-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * レコードが128バイトになるようにパディング/切り詰め
     */
    private function ensureRecordLength(string $record): string
    {
        $sjisRecord = mb_convert_encoding($record, self::ENCODING, 'UTF-8');
        $length = strlen($sjisRecord);

        if ($length < self::RECORD_LENGTH) {
            $sjisRecord .= str_repeat(' ', self::RECORD_LENGTH - $length);
        } elseif ($length > self::RECORD_LENGTH) {
            $sjisRecord = substr($sjisRecord, 0, self::RECORD_LENGTH);
        }

        return mb_convert_encoding($sjisRecord, 'UTF-8', self::ENCODING);
    }

    /**
     * 固定長フィールド用のパディング（Shift_JISバイト長基準）
     */
    private function padToByteLength(string $str, int $length): string
    {
        $str = mb_convert_kana($str, 'askh', 'UTF-8');

        $result = '';
        $currentByteLength = 0;

        for ($i = 0; $i < mb_strlen($str, 'UTF-8'); $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charSjis = mb_convert_encoding($char, 'SJIS', 'UTF-8');
            $charByteLength = strlen($charSjis);

            if ($currentByteLength + $charByteLength > $length) {
                break;
            }

            $result .= $char;
            $currentByteLength += $charByteLength;
        }

        return $result.str_repeat(' ', $length - $currentByteLength);
    }

    /**
     * 固定長フィールド用の右寄せパディング
     */
    private function padToByteLengthRight(string $str, int $length): string
    {
        $str = mb_convert_kana($str, 'askh', 'UTF-8');

        $result = '';
        $currentByteLength = 0;

        for ($i = 0; $i < mb_strlen($str, 'UTF-8'); $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charSjis = mb_convert_encoding($char, 'SJIS', 'UTF-8');
            $charByteLength = strlen($charSjis);

            if ($currentByteLength + $charByteLength > $length) {
                break;
            }

            $result .= $char;
            $currentByteLength += $charByteLength;
        }

        return str_repeat(' ', $length - $currentByteLength).$result;
    }

    /**
     * 品名を固定バイト長にパディング
     */
    private function padProductName(string $str, int $length): string
    {
        $result = '';
        $currentByteLength = 0;

        for ($i = 0; $i < mb_strlen($str, 'UTF-8'); $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charSjis = mb_convert_encoding($char, 'SJIS', 'UTF-8');
            $charByteLength = strlen($charSjis);

            if ($currentByteLength + $charByteLength > $length) {
                break;
            }

            $result .= $char;
            $currentByteLength += $charByteLength;
        }

        return $result.str_repeat(' ', $length - $currentByteLength);
    }

    /**
     * レコード数をカウント
     *
     * JXラッパー(1,8) + Aレコード(1) + Bレコード + Dレコード
     */
    private function countRecords(Collection $orders): int
    {
        $grouped = $orders->groupBy(fn ($o) => "{$o->contractor_id}_{$o->warehouse_id}");
        $bCount = 0;
        foreach ($grouped as $groupOrders) {
            $bCount += (int) ceil($groupOrders->count() / 6);
        }

        // JXラッパー開始(1) + Aレコード(1) + Bレコード + Dレコード + JXラッパー終了(1)
        return 1 + 1 + $bCount + $orders->count() + 1;
    }

    /**
     * ファイル名を生成
     */
    private function generateFilename(WmsOrderJxSetting $jxSetting, string $pattern): string
    {
        $timestamp = Carbon::now()->format('YmdHis');
        $settingId = $jxSetting->id;

        return "jx_test_{$settingId}_{$pattern}_{$timestamp}.dat";
    }

    /**
     * ファイルを保存（S3）
     */
    private function saveFile(string $filename, string $content): string
    {
        $path = "jx-test/{$filename}";
        Storage::disk('s3')->put($path, $content);

        return $path;
    }

    /**
     * 発注コードを取得
     */
    private function getOrderingCode(?int $itemId): string
    {
        if (! $itemId) {
            return '';
        }

        if (isset($this->janCodeCache[$itemId])) {
            return $this->janCodeCache[$itemId];
        }

        // is_used_for_ordering=true のコードを優先
        $codeInfo = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->first();

        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'JAN')
                ->where('is_active', true)
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        $code = $codeInfo->search_string ?? '';

        if ($code !== '') {
            $code = str_pad($code, 13, '0', STR_PAD_LEFT);
        }

        $this->janCodeCache[$itemId] = $code;

        return $code;
    }

    /**
     * 現在有効な仕入単価を取得
     */
    private function getCurrentCostPrice(?int $itemId, int $capacityCase): float
    {
        if (! $itemId) {
            return 0.0;
        }

        if (isset($this->costPriceCache[$itemId])) {
            $price = $this->costPriceCache[$itemId];
        } else {
            $price = DB::connection('sakemaru')
                ->table('item_prices')
                ->where('item_id', $itemId)
                ->where('is_active', true)
                ->where('start_date', '<=', now()->toDateString())
                ->orderBy('start_date', 'desc')
                ->first();

            $this->costPriceCache[$itemId] = $price;
        }

        if (! $price) {
            return 0.0;
        }

        if ($capacityCase <= 1) {
            return (float) ($price->cost_unit_price ?? 0);
        }

        return (float) ($price->cost_case_price ?? 0);
    }

    /**
     * JX送信を実行
     */
    public function transmitFile(int $jxSettingId, string $fileContent): JxClientResult
    {
        $jxSetting = WmsOrderJxSetting::findOrFail($jxSettingId);
        $client = new JxClient($jxSetting);

        // documentType: JX設定のsend_document_type（デフォルト '91' = 発注）
        // formatType: 'SecondGenEDI'（固定）
        return $client->putDocumentWithWrapper($fileContent, $jxSetting->send_document_type ?? '91');
    }
}
