<?php

require '/var/www/hana/sakemaru-wms/vendor/autoload.php';

$app = require '/var/www/hana/sakemaru-wms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$paths = array_slice($argv, 1);

foreach ($paths as $path) {
    $rows = DB::connection('sakemaru')->table('wms_order_jx_documents')
        ->select('id', 'contractor_id', 'warehouse_id', 'batch_code', 'status', 'file_size', 'record_count', 'order_count', 'created_at', 'transmitted_at')
        ->where('file_path', $path)
        ->orderBy('id')
        ->get();

    echo "PATH,{$path},count=".count($rows)."\n";
    foreach ($rows as $row) {
        echo "DOC,{$row->id},{$row->contractor_id},{$row->warehouse_id},{$row->batch_code},{$row->status},size={$row->file_size},rec={$row->record_count},order={$row->order_count},created={$row->created_at},tx={$row->transmitted_at}\n";
    }
}
