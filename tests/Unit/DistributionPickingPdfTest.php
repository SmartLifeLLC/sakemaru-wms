<?php

namespace Tests\Unit;

use App\Services\Distribution\DistributionPickingPdfService;
use PHPUnit\Framework\TestCase;

class DistributionPickingPdfTest extends TestCase
{
    private function rows(): \Illuminate\Support\Collection
    {
        $base = ['item_code' => '100567', 'item_name' => '検証商品', 'packaging' => '720ml', 'capacity_case' => 6,
            'total_pieces' => 7, 'location_code' => 'A-01-01', 'code1' => 'A', 'floor_id' => 1, 'floor_name' => '1F',
            'warehouse_name' => '倉庫', 'course_id' => 1, 'course_code' => '01', 'course_name' => 'コース1',
            'buyer_code' => '01', 'buyer_name' => '本店'];

        return collect([$base, array_replace($base, ['buyer_code' => '02', 'buyer_name' => '二の宮店', 'total_pieces' => 5]),
            array_replace($base, ['course_id' => 2, 'course_code' => '02', 'course_name' => 'コース2', 'buyer_code' => '03']),
            array_replace($base, ['code1' => 'YX', 'location_code' => 'YX-01-01', 'total_pieces' => 2]),
            array_replace($base, ['floor_id' => 2, 'floor_name' => '2F', 'location_code' => 'B-01-01', 'total_pieces' => 3])]);
    }

    public function test_all_formats_preserve_piece_totals_and_store_quantities(): void
    {
        $service = new DistributionPickingPdfService;
        foreach ($service::TYPES as $type) {
            $docs = $service->documents($this->rows(), $type, '数量検証');
            $items = collect($docs)->pluck('items')->flatten(1);
            self::assertSame(24, (int) $items->sum('total_pieces'), $type);
            foreach ($items as $item) {
                self::assertSame($item['total_pieces'], $item['case_qty'] * 6 + $item['piece_qty']);
            }
            self::assertSame('数量検証', $docs[0]['header']['distribution_name']);
        }
        $tertiary = collect($service->documents($this->rows(), 'tertiary', '検証'))->pluck('items')->flatten(1);
        self::assertSame(5, (int) $tertiary->where('buyer_code', '02')->sum('total_pieces'));
        self::assertSame(7, (int) $tertiary->where('buyer_code', '03')->sum('total_pieces'));
    }

    public function test_primary_total_aggregates_courses_and_v2_puts_yx_last(): void
    {
        $service = new DistributionPickingPdfService;
        $docs = $service->documents($this->rows(), 'primary_total', '検証');
        self::assertCount(2, $docs);
        self::assertSame(19, $docs[0]['items'][0]['total_pieces']);
        $docs = $service->documents($this->rows(), 'secondary_v2', '検証');
        self::assertSame(['1F', '1F', '2F', 'YX'], array_column(array_column($docs, 'header'), 'floor_name'));
        self::assertSame([], $service->documents(collect(), 'tertiary', '空'));
    }
}
