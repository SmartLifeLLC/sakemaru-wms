<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\DistributionProductController;
use App\Models\WmsDistributionRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class DistributionRowsDestinationFilterTest extends TestCase
{
    public function test_destination_filter_keeps_listed_zero_quantity_rows_and_excludes_other_destinations(): void
    {
        try {
            $hasDistributionRowsTable = Schema::connection('sakemaru')->hasTable('wms_distribution_rows');
        } catch (\Throwable $exception) {
            $this->skipLocallyOrFailInCi('The dedicated Sakemaru test database is unavailable: '.$exception->getMessage());
        }

        if (! $hasDistributionRowsTable) {
            $this->skipLocallyOrFailInCi('wms_distribution_rows is not migrated on the test database.');
        }

        $connection = DB::connection('sakemaru');
        $connection->beginTransaction();

        try {
            $this->assertDestinationFilterResults();
            $this->assertKeywordFilterNormalizesWidthAndPunctuation();
        } finally {
            $connection->rollBack();
        }
    }

    private function assertKeywordFilterNormalizesWidthAndPunctuation(): void
    {
        $suffix = (string) Str::uuid();
        $matchingRowId = 'keyword-filter-match-'.$suffix;
        $otherRowId = 'keyword-filter-other-'.$suffix;
        $scope = [
            'client_id' => (int) config('app.client_id'),
            'mode' => 'direct',
            'warehouse_id' => 919192,
        ];

        WmsDistributionRow::query()->create($scope + [
            'row_id' => $matchingRowId,
            'sort_order' => 1,
            'row_data' => [
                'id' => $matchingRowId,
                'name' => 'ＡＢＣ商品',
                'orderTo' => '[1263] 吉田 酒造',
            ],
        ]);
        WmsDistributionRow::query()->create($scope + [
            'row_id' => $otherRowId,
            'sort_order' => 2,
            'row_data' => [
                'id' => $otherRowId,
                'name' => '別商品',
                'orderTo' => '[9999] 別会社',
            ],
        ]);

        $controller = app(DistributionProductController::class);
        $filter = new ReflectionMethod($controller, 'applyDistributionRowKeywordFilter');
        $query = WmsDistributionRow::query()
            ->where($scope)
            ->whereIn('row_id', [$matchingRowId, $otherRowId]);

        $filter->invoke($controller, $query, 'abc', [
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.name'))",
        ]);
        $filter->invoke($controller, $query, '1263吉田', [
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderTo'))",
        ]);

        $this->assertSame([$matchingRowId], $query->pluck('row_id')->all());
    }

    private function skipLocallyOrFailInCi(string $message): never
    {
        if (filter_var(env('REQUIRE_DISTRIBUTION_DB_TESTS', env('CI', false)), FILTER_VALIDATE_BOOL)) {
            $this->fail($message);
        }

        $this->markTestSkipped($message);
    }

    private function assertDestinationFilterResults(): void
    {
        $suffix = (string) Str::uuid();
        $zeroRowId = 'destination-filter-zero-'.$suffix;
        $quantityRowId = 'destination-filter-quantity-'.$suffix;
        $otherRowId = 'destination-filter-other-'.$suffix;
        $scope = [
            'client_id' => (int) config('app.client_id'),
            'mode' => 'allocation',
            'warehouse_id' => 919191,
        ];

        WmsDistributionRow::query()->create($scope + [
            'row_id' => $zeroRowId,
            'sort_order' => 1,
            'row_data' => [
                'id' => $zeroRowId,
                'visibleDestinationKeys' => ['01'],
                'wish_01' => 0,
                'alloc_01' => 0,
            ],
        ]);
        WmsDistributionRow::query()->create($scope + [
            'row_id' => $quantityRowId,
            'sort_order' => 2,
            'row_data' => [
                'id' => $quantityRowId,
                'wish_01' => 2,
                'alloc_01' => 0,
            ],
        ]);
        WmsDistributionRow::query()->create($scope + [
            'row_id' => $otherRowId,
            'sort_order' => 3,
            'row_data' => [
                'id' => $otherRowId,
                'visibleDestinationKeys' => ['02'],
                'wish_02' => 3,
                'alloc_02' => 3,
            ],
        ]);

        $query = WmsDistributionRow::query()
            ->where($scope)
            ->orderBy('sort_order');
        $method = new ReflectionMethod(DistributionProductController::class, 'applyStoreDistributionDestinationFilter');
        $method->invoke(app(DistributionProductController::class), $query, '01');

        $this->assertSame(
            [$zeroRowId, $quantityRowId],
            $query->pluck('row_id')->all()
        );
    }
}
