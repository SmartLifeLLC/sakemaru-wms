<?php

namespace App\Services\Statistics;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WarehouseOrderReviewService
{
    /** @return array<array<string, mixed>> */
    public function rows(int $clientId, string $date, int $warehouseCode): array
    {
        if ($clientId <= 0 || $warehouseCode <= 0) {
            throw new InvalidArgumentException('対象の会社と倉庫を確認してください。');
        }

        $sql = str_replace('__CLIENT_ID__', (string) $clientId, file_get_contents(resource_path('sql/warehouse-order-review.sql')));

        return array_map(fn (object $row): array => (array) $row,
            DB::connection('sakemaru')->select($sql, [$date, $warehouseCode]));
    }
}
