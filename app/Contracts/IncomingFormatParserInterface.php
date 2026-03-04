<?php

namespace App\Contracts;

use App\Models\WmsIncomingReceivedFile;

interface IncomingFormatParserInterface
{
    /**
     * 受信データをパースして3層テーブルに保存
     *
     * @param  string  $content  ファイル内容（バイナリ）
     * @param  string  $filename  ファイル名
     * @param  int|null  $contractorId  発注先ID
     * @return WmsIncomingReceivedFile 作成されたファイルレコード
     */
    public function parse(string $content, string $filename, ?int $contractorId = null): WmsIncomingReceivedFile;
}
