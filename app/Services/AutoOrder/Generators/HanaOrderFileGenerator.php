<?php

namespace App\Services\AutoOrder\Generators;

use App\Contracts\OrderFileGeneratorInterface;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ハナ様向け発注ファイル生成クラス
 *
 * 発注伝送仕様に基づいた固定長ファイルを生成する。
 * - Aレコード: ファイルヘッダ（1ファイルに1件）
 * - Bレコード: 伝票ヘッダ（発注先×倉庫単位）
 * - Dレコード: 伝票明細（Bレコード配下）
 *
 * 各レコードは128バイト固定長、改行なしで連結、末尾にCRLF。
 */
class HanaOrderFileGenerator implements OrderFileGeneratorInterface
{
    /**
     * JX送信対象発注先コード
     */
    private const JX_CONTRACTOR_CODES = [1106, 1017, 1202, 1330];

    /**
     * 送信先集約マッピング（発注先コード => 送信先発注先コード）
     */
    private const TRANSMISSION_MAPPING = [
        1021 => 1106,  // カナカン酒類福井 → カナカン食品
        1029 => 1106,  // カナカンフローズン → カナカン食品
        1068 => 1106,  // カナカン酒類金沢 → カナカン食品
        1126 => 1106,  // カナカン日配 → カナカン食品
        1127 => 1106,  // カナカン菓子 → カナカン食品
    ];

    /**
     * 送信元コード（リカーワールド ハナ）
     */
    private const SENDER_CODE = '01451019';

    /**
     * 社名
     */
    private const COMPANY_NAME = 'ﾘｶｰﾜｰﾙﾄﾞ ﾊﾅ';

    private const ENCODING = 'SJIS';

    private const LINE_ENDING = "\r\n";

    private const FILE_EXTENSION = 'dat';

    private const RECORD_LENGTH = 128;

    /**
     * 発注先コード => 発注先ID のキャッシュ
     */
    private array $contractorIdCache = [];

    /**
     * 商品ID => JANコード のキャッシュ
     */
    private array $janCodeCache = [];

    /**
     * 発注ファイルを生成
     */
    public function generate(Collection $orderCandidates): array
    {
        $results = [];

        // 送信先別にグルーピング
        $grouped = $this->groupByTransmissionContractor($orderCandidates);

        foreach ($grouped as $transmissionContractorCode => $candidates) {
            $jxSetting = $this->getJxSettingByContractorCode($transmissionContractorCode);

            $content = $this->generateFileContent($transmissionContractorCode, $candidates, $jxSetting);
            $filename = $this->generateFilename($transmissionContractorCode);

            $results[] = [
                'contractor_id' => $this->getContractorIdByCode($transmissionContractorCode),
                'contractor_code' => $transmissionContractorCode,
                'jx_setting_id' => $jxSetting?->id,
                'content' => $content,
                'filename' => $filename,
                'encoding' => self::ENCODING,
                'record_count' => $this->countRecords($candidates),
                'order_count' => $candidates->count(),
            ];
        }

        return $results;
    }

    /**
     * 送信先発注先コードでグルーピング
     */
    private function groupByTransmissionContractor(Collection $candidates): Collection
    {
        return $candidates->groupBy(function ($candidate) {
            $contractorCode = $candidate->contractor?->code;

            // transmission_contractor_idがあればその発注先コードを使用
            $setting = WmsContractorSetting::where('contractor_id', $candidate->contractor_id)->first();
            if ($setting?->transmission_contractor_id) {
                $transmissionContractor = $setting->transmissionContractor;
                if ($transmissionContractor) {
                    return $transmissionContractor->code;
                }
            }

            // マッピングに存在すれば送信先コードを返す
            if (isset(self::TRANSMISSION_MAPPING[$contractorCode])) {
                return self::TRANSMISSION_MAPPING[$contractorCode];
            }

            return $contractorCode;
        });
    }

    /**
     * 1つのBレコードに含められる最大D行数
     */
    private const MAX_D_RECORDS_PER_B = 6;

    /**
     * ファイル内容を生成
     *
     * 仕様: レコード間に改行なし、ファイル末尾にのみCRLF
     * 1つのBレコードには最大6行のDレコードまで。超える場合は新しいBレコードを作成。
     */
    private function generateFileContent(int $transmissionContractorCode, Collection $candidates, ?WmsOrderJxSetting $jxSetting = null): string
    {
        $records = [];

        // 発注先×倉庫でグルーピングしてB/Dレコードを生成
        $groupedByContractorWarehouse = $candidates->groupBy(function ($candidate) {
            return "{$candidate->contractor_id}_{$candidate->warehouse_id}";
        });

        // 6行制限を考慮してBレコード数とDレコード数を計算
        $bCount = 0;
        $dCount = $candidates->count();
        foreach ($groupedByContractorWarehouse as $groupCandidates) {
            // 各グループを6件ずつに分割した場合のBレコード数
            $bCount += (int) ceil($groupCandidates->count() / self::MAX_D_RECORDS_PER_B);
        }

        $totalRecordCount = 1 + $bCount + $dCount; // レコード件数（A + B + D）
        $records[] = $this->generateARecord($transmissionContractorCode, $totalRecordCount, $bCount, $jxSetting);

        $bRecordSeq = 1;
        foreach ($groupedByContractorWarehouse as $groupKey => $groupCandidates) {
            // 6件ずつに分割
            $chunks = $groupCandidates->chunk(self::MAX_D_RECORDS_PER_B);

            foreach ($chunks as $chunk) {
                $firstCandidate = $chunk->first();

                // Bレコード（伝票ヘッダ）
                $records[] = $this->generateBRecord($firstCandidate, $bRecordSeq);

                // Dレコード（伝票明細）- 各Bレコード内で1から開始
                $dRecordSeq = 1;
                foreach ($chunk as $candidate) {
                    $records[] = $this->generateDRecord($candidate, $dRecordSeq);
                    $dRecordSeq++;
                }

                $bRecordSeq++;
            }
        }

        // レコードを連結（改行なし）+ 末尾にCRLF
        $content = implode('', $records) . self::LINE_ENDING;

        // Shift_JISに変換
        return mb_convert_encoding($content, self::ENCODING, 'UTF-8');
    }

    /**
     * Aレコード（ファイルヘッダー）を生成 - 128バイト
     *
     * 仕様:
     * 1: レコード区分 X(01) - "A"
     * 2-3: データ種別 9(02) - "01"
     * 4-11: データ処理日付 9(08) - YYYYMMDD
     * 12-17: データ処理時刻 9(06) - HHMMSS
     * 18-25: データ送信元 9(08) - JX設定のsender_station_code（0埋め8桁）
     * 26-33: データ送信先 9(08) - JX設定のreceiver_station_code（0埋め8桁）
     * 34-39: レコード件数 9(06) - ファイル内の全行数（A+B+D合計）
     * 40-45: 帳票枚数 9(06) - Bレコード（B01）の出現回数
     * 46-60: 社名 X(15)
     * 61-128: FILLER X(68)
     *
     * @param int $contractorCode 発注先コード（フォールバック用）
     * @param int $totalRecordCount レコード件数（A+B+D合計）
     * @param int $slipCount 伝票枚数（Bレコード数）
     * @param WmsOrderJxSetting|null $jxSetting JX設定
     */
    private function generateARecord(int $contractorCode, int $totalRecordCount, int $slipCount, ?WmsOrderJxSetting $jxSetting = null): string
    {
        $now = Carbon::now();

        // JX設定からステーションコードを取得（なければ従来のフォールバック）
        $senderStationCode = $jxSetting?->sender_station_code ?? self::SENDER_CODE;
        $receiverStationCode = $jxSetting?->receiver_station_code ?? (string) $contractorCode;

        $record = '';
        $record .= 'A';                                              // 1: レコード区分
        $record .= '01';                                             // 2-3: データ種別
        $record .= $now->format('Ymd');                              // 4-11: データ処理日付
        $record .= $now->format('His');                              // 12-17: データ処理時刻
        $record .= str_pad($senderStationCode, 8, '0', STR_PAD_LEFT);    // 18-25: データ送信元（0埋め8桁）
        $record .= str_pad($receiverStationCode, 8, '0', STR_PAD_LEFT);  // 26-33: データ送信先（0埋め8桁）
        $record .= $this->padNumber($totalRecordCount, 6);           // 34-39: レコード件数（A+B+D合計）
        $record .= $this->padNumber($slipCount, 6);                  // 40-45: 帳票枚数（Bレコード数）
        $record .= $this->padToByteLength(self::COMPANY_NAME, 15);   // 46-60: 社名
        $record .= str_pad('', 68);                                  // 61-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * Bレコード（伝票ヘッダー）を生成 - 128バイト
     *
     * 仕様:
     * 1: レコード区分 X(01) - "B"
     * 2-3: データ種別 9(02) - "01"
     * 4-14: 伝票番号 X(11) - YYYYMMDD + 連番3桁（日付ベースでユニーク）
     * 15-18: 社・店コード X(04) - 入庫倉庫コード（0埋め4桁）
     * 19-21: 分類コード X(03) - "999" 固定
     * 22-23: 伝票区分 X(02) - "01" 固定
     * 24-29: 発注日 9(06) - YYMMDD
     * 30-35: 納品日 9(06) - YYMMDD（入庫予定日）
     * 36-38: 便 X(03) - 空白
     * 39-42: 取引先コード X(04) - 仕入先コード
     * 43-57: 店名 X(15) - 発注倉庫名（半角カナ、右寄せ）
     * 58-67: 納品場所 X(10) - 入庫倉庫名（半角カナ、右寄せ）
     * 68-92: G（備考） X(25) - 空白
     * 93-94: 直送区分 X(02) - "00" 固定
     * 95-128: FILLER X(34)
     */
    private function generateBRecord($candidate, int $seq): string
    {
        $warehouse = $candidate->warehouse;
        $contractor = $candidate->contractor;
        $orderDate = Carbon::now();
        $deliveryDate = $candidate->expected_arrival_date ?? $orderDate->copy()->addDays(2);

        // 伝票番号を生成（YYYYMMDD + 連番3桁）
        // 例: 20260125001, 20260125002, ...
        $slipNumber = $orderDate->format('Ymd') . str_pad($seq, 3, '0', STR_PAD_LEFT);

        // 入庫倉庫コード（0埋め4桁）
        $warehouseCode = str_pad((string) ($warehouse?->code ?? ''), 4, '0', STR_PAD_LEFT);

        $record = '';
        $record .= 'B';                                              // 1: レコード区分
        $record .= '01';                                             // 2-3: データ種別
        $record .= $slipNumber;                                      // 4-14: 伝票番号（11桁）
        $record .= $warehouseCode;                                   // 15-18: 社・店コード（入庫倉庫コード）
        $record .= '999';                                            // 19-21: 分類コード（固定）
        $record .= '01';                                             // 22-23: 伝票区分
        $record .= $orderDate->format('ymd');                        // 24-29: 発注日 (YYMMDD)
        $record .= $deliveryDate->format('ymd');                     // 30-35: 納品日 (YYMMDD)
        $record .= str_pad('', 3);                                   // 36-38: 便（空白）
        $record .= str_pad(substr($contractor?->code ?? '', 0, 4), 4); // 39-42: 取引先コード
        $record .= $this->padToByteLengthRight($warehouse?->kana_name ?? '', 15); // 43-57: 店名（半角カナ、右寄せ）
        $record .= $this->padToByteLengthRight($warehouse?->kana_name ?? '', 10); // 58-67: 納品場所（半角カナ、右寄せ）
        $record .= str_pad('', 25);                                  // 68-92: G（備考）
        $record .= '00';                                             // 93-94: 直送区分（00固定）
        $record .= str_pad('', 34);                                  // 95-128: FILLER

        return $this->ensureRecordLength($record);
    }

    /**
     * Dレコード（伝票明細）を生成 - 128バイト
     *
     * 仕様:
     * 1: レコード区分 X(01) - "D"
     * 2-3: データ種別 9(02) - "01"
     * 4-5: 伝票行番号 9(02) - Bレコード内の行番号（1-6）
     * 6-69: 品名 X(64) - name_main、62バイト+2バイト空白、半角カナ変換なし
     * 70-82: JANコード X(13)
     * 83-88: 自社コード X(06)
     * 89-94: 仕入入数 9(06)
     * 95-101: ケース数 9(07) - capacity_case=1の場合は0
     * 102-108: バラ数量 9(07) - capacity_case=1の場合のみ数量設定
     * 109-118: 原単価 9(10) - 小数点2桁（160.00→000016000）
     * 119-128: FILLER X(10)
     */
    private function generateDRecord($candidate, int $seq): string
    {
        $item = $candidate->item;
        $capacityCase = $item?->capacity_case ?? 1;
        $totalQty = $candidate->order_quantity;

        // ケース数とバラ数を計算
        // capacity_case=1の場合はバラ販売商品なので、バラ数量で注文
        if ($capacityCase <= 1) {
            $caseQty = 0;
            $pieceQty = $totalQty;
        } else {
            $caseQty = intdiv($totalQty, $capacityCase);
            $pieceQty = 0; // 基本的に0
        }

        // 発注コード: 候補に保存されているordering_codeを優先、なければ動的取得（後方互換性）
        $orderingCode = $candidate->ordering_code ?? $this->getJanCode($item?->id);

        // 原単価を取得（小数点2桁、160.00→000016000）
        $costPrice = $this->getCurrentCostPrice($item?->id, $capacityCase);
        $priceFormatted = (int) round($costPrice * 100); // 小数点2桁を整数に変換

        $record = '';
        $record .= 'D';                                              // 1: レコード区分
        $record .= '01';                                             // 2-3: データ種別
        $record .= $this->padNumber($seq, 2);                        // 4-5: 伝票行番号
        $record .= $this->padProductName($item?->name_main ?? '', 62) . '  '; // 6-69: 品名（62バイト+2空白）
        $record .= str_pad($orderingCode, 13);                       // 70-82: 発注コード
        $record .= str_pad(substr($item?->code ?? '', 0, 6), 6);     // 83-88: 自社コード
        $record .= $this->padNumber($capacityCase, 6);               // 89-94: 仕入入数
        $record .= $this->padNumber($caseQty, 7);                    // 95-101: ケース数
        $record .= $this->padNumber($pieceQty, 7);                   // 102-108: バラ数量
        $record .= $this->padNumber($priceFormatted, 10);            // 109-118: 原単価
        $record .= str_pad('', 10);                                  // 119-128: FILLER

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
     * ファイル名を生成
     */
    private function generateFilename(int $contractorCode): string
    {
        $timestamp = Carbon::now()->format('YmdHis');

        return "{$contractorCode}_order_{$timestamp}." . self::FILE_EXTENSION;
    }

    /**
     * レコード数をカウント
     */
    private function countRecords(Collection $candidates): int
    {
        // Aレコード(1) + Bレコード(グループ数) + Dレコード(候補数)
        $groupCount = $candidates->groupBy(function ($c) {
            return "{$c->contractor_id}_{$c->warehouse_id}";
        })->count();

        return 1 + $groupCount + $candidates->count();
    }

    /**
     * 発注先コードからJX設定を取得
     */
    private function getJxSettingByContractorCode(int $contractorCode): ?WmsOrderJxSetting
    {
        $contractorId = $this->getContractorIdByCode($contractorCode);

        if (! $contractorId) {
            return null;
        }

        // wms_contractor_settingsからJX設定IDを取得
        $setting = WmsContractorSetting::where('contractor_id', $contractorId)->first();

        if ($setting?->wms_order_jx_setting_id) {
            return WmsOrderJxSetting::find($setting->wms_order_jx_setting_id);
        }

        return null;
    }

    /**
     * 発注先コードから発注先IDを取得
     */
    private function getContractorIdByCode(int $code): ?int
    {
        if (isset($this->contractorIdCache[$code])) {
            return $this->contractorIdCache[$code];
        }

        $contractor = \App\Models\Sakemaru\Contractor::where('code', $code)->first();
        $this->contractorIdCache[$code] = $contractor?->id;

        return $this->contractorIdCache[$code];
    }

    /**
     * 商品IDから発注コードを取得（フォールバック用）
     *
     * 通常は WmsOrderCandidate.ordering_code を使用する。
     * このメソッドは ordering_code が設定されていない既存データ用の後方互換性のため。
     *
     * item_search_information テーブルから取得する。
     * 優先順位:
     * 1. is_used_for_ordering=true のコード
     * 2. code_type='JAN' かつ quantity_type='PIECE'
     * 3. code_type='JAN' かつ quantity_type='CASE'
     * 4. code_type='OTHER' かつ quantity_type='PIECE'（数字のみ7桁以上）
     *
     * 取得したコードは13桁にゼロパディングして返す。
     */
    private function getJanCode(?int $itemId): string
    {
        if (! $itemId) {
            return '';
        }

        if (isset($this->janCodeCache[$itemId])) {
            return $this->janCodeCache[$itemId];
        }

        // まずis_used_for_ordering=trueのコードを検索
        $codeInfo = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->first();

        // なければJANコードを検索（PIECE優先）
        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'JAN')
                ->where('is_active', true)
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        // それでもなければOTHERコードを検索（数字のみ7桁以上）
        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'OTHER')
                ->where('is_active', true)
                ->whereRaw("search_string REGEXP '^[0-9]{7,}$'")
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        $code = $codeInfo->search_string ?? '';

        // 13桁にゼロパディング
        if ($code !== '') {
            $code = str_pad($code, 13, '0', STR_PAD_LEFT);
        }

        $this->janCodeCache[$itemId] = $code;

        return $code;
    }

    /**
     * 全角カナを半角カナに変換
     */
    private function toHalfWidthKana(string $str): string
    {
        return mb_convert_kana($str, 'askh', 'UTF-8');
    }

    /**
     * 固定長フィールド用のパディング（Shift_JISバイト長基準）
     *
     * UTF-8文字列を受け取り、Shift_JISでのバイト長が指定長になるようにパディングした
     * UTF-8文字列を返す。最終的にファイル全体がSJISに変換されるため。
     *
     * @param  string  $str  元の文字列（UTF-8）
     * @param  int  $length  目標バイト長（SJIS換算）
     * @param  string  $pad  パディング文字
     * @param  int  $padType  STR_PAD_RIGHT or STR_PAD_LEFT
     */
    private function padToByteLength(string $str, int $length, string $pad = ' ', int $padType = STR_PAD_RIGHT): string
    {
        // 半角カナに変換
        $str = $this->toHalfWidthKana($str);

        // 文字単位で処理し、SJISバイト長が目標を超えないようにする
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

        // パディングを追加
        $padLength = $length - $currentByteLength;
        $padding = str_repeat($pad, $padLength);

        if ($padType === STR_PAD_LEFT) {
            return $padding . $result;
        }

        return $result . $padding;
    }

    /**
     * 数値を固定長でゼロパディング
     */
    private function padNumber($value, int $length): string
    {
        return str_pad((string) ($value ?? 0), $length, '0', STR_PAD_LEFT);
    }

    /**
     * 固定長フィールド用の右寄せパディング（半角カナ変換付き、Shift_JISバイト長基準）
     *
     * UTF-8文字列を受け取り、Shift_JISでのバイト長が指定長になるように
     * 左側に空白を追加してUTF-8文字列を返す。
     *
     * @param  string  $str  元の文字列（UTF-8）
     * @param  int  $length  目標バイト長（SJIS換算）
     */
    private function padToByteLengthRight(string $str, int $length): string
    {
        // 半角カナに変換
        $str = $this->toHalfWidthKana($str);

        // 文字単位で処理し、SJISバイト長が目標を超えないようにする
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

        // 左側に空白パディングを追加（右寄せ）
        $padLength = $length - $currentByteLength;

        return str_repeat(' ', $padLength) . $result;
    }

    /**
     * 品名を固定バイト長にパディング（半角カナ変換なし）
     *
     * UTF-8文字列を受け取り、Shift_JISでのバイト長が指定長になるようにパディング。
     * マルチバイト文字が途中で切れないように注意。
     *
     * @param  string  $str  元の文字列（UTF-8）
     * @param  int  $length  目標バイト長（SJIS換算）
     */
    private function padProductName(string $str, int $length): string
    {
        // 文字単位で処理し、SJISバイト長が目標を超えないようにする
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

        // 残りを空白でパディング
        $padLength = $length - $currentByteLength;

        return $result . str_repeat(' ', $padLength);
    }

    /**
     * 商品の現在有効な仕入単価を取得
     *
     * item_pricesテーブルからstart_dateが現在日以前で最も新しいレコードを取得。
     * capacity_case=1の場合はcost_unit_price、それ以外はcost_case_priceを返す。
     *
     * @param  int|null  $itemId  商品ID
     * @param  int  $capacityCase  ケース入り数
     * @return float 仕入単価
     */
    private function getCurrentCostPrice(?int $itemId, int $capacityCase): float
    {
        if (! $itemId) {
            return 0.0;
        }

        $price = DB::connection('sakemaru')
            ->table('item_prices')
            ->where('item_id', $itemId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->orderBy('start_date', 'desc')
            ->first();

        if (! $price) {
            return 0.0;
        }

        // capacity_case=1の場合はバラ単価、それ以外はケース単価
        if ($capacityCase <= 1) {
            return (float) ($price->cost_unit_price ?? 0);
        }

        return (float) ($price->cost_case_price ?? 0);
    }

    // ========================================
    // Interface実装
    // ========================================

    public function getJxTransmissionContractorIds(): array
    {
        $ids = [];
        foreach (self::JX_CONTRACTOR_CODES as $code) {
            $id = $this->getContractorIdByCode($code);
            if ($id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function getTransmissionContractorMapping(): array
    {
        $mapping = [];
        foreach (self::TRANSMISSION_MAPPING as $fromCode => $toCode) {
            $fromId = $this->getContractorIdByCode($fromCode);
            $toId = $this->getContractorIdByCode($toCode);
            if ($fromId && $toId) {
                $mapping[$fromId] = $toId;
            }
        }

        return $mapping;
    }

    public function getEncoding(): string
    {
        return self::ENCODING;
    }

    public function getLineEnding(): string
    {
        return self::LINE_ENDING;
    }

    public function getFileExtension(): string
    {
        return self::FILE_EXTENSION;
    }
}
