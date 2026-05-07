<?php

require '/var/www/hana/sakemaru-wms/vendor/autoload.php';

$app = require '/var/www/hana/sakemaru-wms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$ids = array_map('intval', array_slice($argv, 1));

foreach ($ids as $id) {
    $doc = DB::connection('sakemaru')->table('wms_order_jx_documents')->where('id', $id)->first();
    echo "DOC,{$id},{$doc->file_path},db_size={$doc->file_size},db_rec={$doc->record_count},db_order={$doc->order_count}\n";

    $content = Storage::disk('s3')->get($doc->file_path);
    $types = [];
    for ($offset = 0; $offset + 128 <= strlen($content); $offset += 128) {
        $record = substr($content, $offset, 128);
        $types[] = $record[0] ?? '?';
    }
    echo 'ACTUAL,size='.strlen($content).',types='.implode('', $types)."\n";

    $line = 0;
    for ($offset = 0; $offset + 128 <= strlen($content); $offset += 128) {
        $record = substr($content, $offset, 128);
        if (($record[0] ?? '') !== 'D') {
            continue;
        }

        $line++;
        echo 'D,'.$line
            .',line='.substr($record, 3, 2)
            .',order_cd='.trim(substr($record, 69, 13))
            .',item='.trim(substr($record, 82, 6))
            .',cap='.(int) substr($record, 88, 6)
            .',case='.(int) substr($record, 94, 7)
            .',piece='.(int) substr($record, 101, 7)
            ."\n";
    }

    $rows = DB::connection('sakemaru')->table('wms_order_candidates')
        ->select('id', 'item_code', 'ordering_code', 'order_quantity', 'quantity_type')
        ->where('wms_order_jx_document_id', $id)
        ->orderBy('id')
        ->get();

    foreach ($rows as $row) {
        echo "DB,{$row->id},{$row->item_code},{$row->ordering_code},{$row->quantity_type},{$row->order_quantity}\n";
    }
}
