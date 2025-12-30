<?php

namespace App\Services\JX;

use App\Models\WmsOrderJxSetting;
use Carbon\Carbon;

/**
 * JX発注データにヘッダー・フッターを付与するサービス
 *
 * ヘッダー仕様: 128バイト固定長
 * フッター仕様: "8" + 127スペース = 128バイト
 */
class JxDataWrapper
{
    protected WmsOrderJxSetting $setting;

    public function __construct(WmsOrderJxSetting $setting)
    {
        $this->setting = $setting;
    }

    /**
     * データにヘッダーとフッターを追加
     *
     * @param string $data 元のデータ（ヘッダー・フッターなし）
     * @return string ヘッダー + データ + フッター
     */
    public function wrap(string $data): string
    {
        // データの行数をカウント（ヘッダー + データ行 + フッターを含む）
        $dataLines = $this->countLines($data);
        $totalRecords = $dataLines + 2; // ヘッダー1行 + データ行 + フッター1行

        $header = $this->generateHeader($totalRecords);
        $footer = $this->generateFooter();

        return $header . $data . $footer;
    }

    /**
     * ヘッダー行を生成（128バイト固定長）
     */
    protected function generateHeader(int $recordCount): string
    {
        $now = Carbon::now();

        // 各フィールドを準備
        $fields = [
            $this->padRight('1', 1),                                           // 1: レコード区分
            $this->padLeft('0000001', 7, '0'),                                  // 2: データシリアルNo.
            $this->padRight($this->setting->send_document_type ?? '91', 2),        // 3: データ種別
            $now->format('ymd'),                                                // 4: データ作成日
            $now->format('His'),                                                // 5: データ作成時刻
            $this->padRight('00', 2),                                           // 6: ファイルNo.
            $now->format('ymd'),                                                // 7: データ処理日
            $this->padRight($this->setting->receiver_trading_code ?? '', 12),   // 8: 利用者企業コード（受注）
            $this->padRight($this->setting->sender_station_code ?? '', 6),      // 9: データ送信元センターコード
            $this->padRight('', 2),                                             // 10: 予備
            $this->padRight($this->setting->receiver_station_code ?? '', 6),    // 11: 最終送信先コード
            $this->padRight('', 2),                                             // 12: 最終送信先ステーションアドレス
            $this->padRight('810501', 6),                                       // 13: 直接送信先企業コード（ファイネット固定）
            $this->padRight('', 2),                                             // 14: 直接送信先企業ステーションアドレス
            $this->padRight($this->setting->sender_trading_code ?? '', 12),     // 15: 提供企業コード
            $this->padRight($this->setting->sender_trading_code ?? '', 12),     // 16: 提供企業事業所コード
            $this->padRight($this->setting->sender_name ?? '', 15),             // 17: 提供企業名
            $this->padRight($this->setting->sender_office_name ?? '', 10),      // 18: 提供企業事業所名
            $this->padLeft((string) $recordCount, 6, '0'),                      // 19: 送信データ件数
            $this->padLeft('128', 3, '0'),                                      // 20: レコードサイズ
            $this->padRight(' ', 1),                                            // 21: データ有無サイン
            $this->padRight('', 1),                                             // 22: フォーマットバージョンNo.
            $this->padRight('', 2),                                             // 23: 余白
        ];

        $header = implode('', $fields);

        // 128バイトになるようにパディング（念のため）
        return $this->padRight($header, 128);
    }

    /**
     * フッター行を生成（128バイト固定長）
     * "8" + 127スペース
     */
    protected function generateFooter(): string
    {
        return '8' . str_repeat(' ', 127);
    }

    /**
     * データのレコード数をカウント
     *
     * 以下の順序で判定:
     * 1. 改行がある場合は改行でカウント
     * 2. 改行がなく128バイトで割り切れる場合は固定長レコードとしてカウント
     */
    protected function countLines(string $data): int
    {
        if (empty($data)) {
            return 0;
        }

        // 改行コードを統一
        $normalized = str_replace(["\r\n", "\r"], "\n", $data);

        // 改行がある場合は改行でカウント
        if (str_contains($normalized, "\n")) {
            $lines = explode("\n", $normalized);
            // 空行を除外してカウント
            return count(array_filter($lines, fn ($line) => $line !== ''));
        }

        // 改行がない場合は128バイト固定長レコードとしてカウント
        $length = strlen($data);
        if ($length > 0 && $length % 128 === 0) {
            return (int) ($length / 128);
        }

        // それ以外は1レコードとしてカウント
        return 1;
    }

    /**
     * 右パディング（半角スペース埋め）
     */
    protected function padRight(string $value, int $length, string $pad = ' '): string
    {
        // マルチバイト対応
        $currentLength = strlen($value);
        if ($currentLength >= $length) {
            return substr($value, 0, $length);
        }

        return $value . str_repeat($pad, $length - $currentLength);
    }

    /**
     * 左パディング（指定文字埋め）
     */
    protected function padLeft(string $value, int $length, string $pad = '0'): string
    {
        $currentLength = strlen($value);
        if ($currentLength >= $length) {
            return substr($value, 0, $length);
        }

        return str_repeat($pad, $length - $currentLength) . $value;
    }

    /**
     * ヘッダーが既に存在するかチェック
     */
    public static function hasHeader(string $data): bool
    {
        // 最初の文字が "1" で始まる場合はヘッダーあり
        return strlen($data) > 0 && $data[0] === '1';
    }

    /**
     * フッターが既に存在するかチェック
     */
    public static function hasFooter(string $data): bool
    {
        // 最後の非空白行が "8" で始まる場合はフッターあり
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $data));
        $lastLine = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) !== '') {
                $lastLine = $lines[$i];
                break;
            }
        }

        return strlen($lastLine) > 0 && $lastLine[0] === '8';
    }
}
