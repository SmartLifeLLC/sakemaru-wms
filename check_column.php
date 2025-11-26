<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

$hasFloorId = Schema::connection('sakemaru')->hasColumn('wms_picking_areas', 'floor_id');
echo "Has floor_id: " . ($hasFloorId ? 'YES' : 'NO') . "\n";
