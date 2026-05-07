<?php

require '/var/www/hana/sakemaru-wms/vendor/autoload.php';

$app = require '/var/www/hana/sakemaru-wms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$itemCode = $argv[1] ?? '';
$docIds = array_map('intval', array_slice($argv, 2));

foreach ($docIds as $docId) {
    $doc = DB::connection('sakemaru')->table('wms_order_jx_documents')->where('id', $docId)->first();
    echo "DOC,{$docId},{$doc->file_path},status={$doc->status},order_count={$doc->order_count}\n";

    $content = Storage::disk('s3')->get($doc->file_path);
    for ($offset = 0; $offset + 128 <= strlen($content); $offset += 128) {
        $record = substr($content, $offset, 128);
        if (($record[0] ?? '') !== 'D') {
            continue;
        }

        $recordItemCode = trim(substr($record, 82, 6));
        if ($itemCode !== '' && $recordItemCode !== $itemCode) {
            continue;
        }

        echo 'D'
            .',line='.substr($record, 3, 2)
            .',order_cd='.trim(substr($record, 69, 13))
            .',item='.$recordItemCode
            .',capacity='.(int) substr($record, 88, 6)
            .',case='.(int) substr($record, 94, 7)
            .',piece='.(int) substr($record, 101, 7)
            .',unit_price_raw='.(int) substr($record, 108, 10)
            .',unit_price='.number_format(((int) substr($record, 108, 10)) / 100, 2, '.', '')
            ."\n";
    }
}
