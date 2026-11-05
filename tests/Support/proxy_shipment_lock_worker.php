<?php

// Separate connection/process for the opt-in MySQL concurrency regression tests.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$prefix = $argv[1] ?? '';
$database = (string) config('database.connections.sakemaru.database');
if (! app()->environment('testing') || ! str_contains(strtolower($database), 'test')
    || ! in_array(config('database.connections.sakemaru.host'), ['127.0.0.1', 'localhost', '::1'], true)
    || ! preg_match('/^codex_proxy_lock_[a-f0-9]{12}_$/', $prefix)) {
    throw new RuntimeException('Dedicated local test database and owned prefix required.');
}
config(['database.connections.sakemaru.prefix' => $prefix]);
\Illuminate\Support\Facades\DB::purge('sakemaru');
\Illuminate\Support\Facades\DB::connection('sakemaru')->beforeExecuting(function (string $sql): void {
    if (str_contains(strtolower($sql), 'for update')) {
        echo "locking-business-row\n";
        flush();
    }
});
$allocation = app(\App\Services\Shortage\ProxyShipmentService::class)->createProxyShipment(
    \App\Models\WmsShortage::findOrFail(1), (int) $argv[2], 4, 'PIECE', 9
);
echo 'result:'.$allocation->id."\n";
