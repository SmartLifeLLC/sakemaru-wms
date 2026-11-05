<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\HqOrderRegistrationReferenceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HqOrderRegistrationReferenceServiceTest extends TestCase
{
    #[DataProvider('branchSubtractionCases')]
    public function test_branch_wholesale_quantity_is_subtracted_from_total_sales(int $total, int $branch, int $expected): void
    {
        $this->assertSame($expected, HqOrderRegistrationReferenceService::subtractBranchQuantity($total, $branch));
    }

    public static function branchSubtractionCases(): array
    {
        return [
            '店舗卸なし' => [24, 0, 24],
            '一部が店舗卸' => [24, 6, 18],
            '全量が店舗卸' => [24, 24, 0],
            '集計の時点差で店舗卸の方が多い場合は0で止める' => [5, 8, 0],
            '店舗卸の返品はその分を戻す' => [-24, -24, 0],
            '本部側の返品はそのまま残す' => [-3, 0, -3],
            '本部の返品と店舗卸が混在' => [-3, 2, -5],
        ];
    }

    public function test_latest_rows_by_item_keeps_only_the_newest_date_per_item(): void
    {
        $result = HqOrderRegistrationReferenceService::latestRowsByItem([
            ['item_id' => 10, 'date' => '2026-09-01', 'case_qty' => 5, 'piece_qty' => 0],
            ['item_id' => 10, 'date' => '2026-09-20', 'case_qty' => 2, 'piece_qty' => 3],
            ['item_id' => 10, 'date' => '2026-09-10', 'case_qty' => 9, 'piece_qty' => 9],
            ['item_id' => 20, 'date' => '2026-08-15', 'case_qty' => 0, 'piece_qty' => 12],
        ]);

        $this->assertSame([
            10 => ['date' => '2026-09-20', 'case_qty' => 2, 'piece_qty' => 3],
            20 => ['date' => '2026-08-15', 'case_qty' => 0, 'piece_qty' => 12],
        ], $result);
    }

    public function test_latest_rows_by_item_sums_rows_of_the_same_latest_date(): void
    {
        $result = HqOrderRegistrationReferenceService::latestRowsByItem([
            ['item_id' => 10, 'date' => '2026-09-20', 'case_qty' => 2, 'piece_qty' => 0],
            ['item_id' => 10, 'date' => '2026-09-20', 'case_qty' => 1, 'piece_qty' => 6],
            ['item_id' => 10, 'date' => '2026-09-19', 'case_qty' => 50, 'piece_qty' => 50],
        ]);

        $this->assertSame([
            10 => ['date' => '2026-09-20', 'case_qty' => 3, 'piece_qty' => 6],
        ], $result);
    }

    #[DataProvider('volumeLabelCases')]
    public function test_volume_label_is_built_from_volume_and_unit(mixed $volume, mixed $unit, ?string $expected): void
    {
        $this->assertSame($expected, HqOrderRegistrationReferenceService::volumeLabel($volume, $unit));
    }

    public static function volumeLabelCases(): array
    {
        return [
            'ml' => [750, 'MILLILITER', '750ml'],
            '一升瓶' => [1800, 'MILLILITER', '1800ml'],
            'g' => ['500', 'GRAM', '500g'],
            '個' => [12, 'PIECE', '12個'],
            '容量0は未登録扱い' => [0, 'MILLILITER', null],
            '容量なし' => [null, 'MILLILITER', null],
            '単位が不明でも数値は出す' => [330, 'UNKNOWN', '330'],
        ];
    }

    public function test_latest_rows_by_item_returns_empty_for_no_rows(): void
    {
        $this->assertSame([], HqOrderRegistrationReferenceService::latestRowsByItem([]));
    }
}
