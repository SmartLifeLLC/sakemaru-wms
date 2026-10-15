<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountLocationResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryCountLocationResolverTest extends TestCase
{
    #[DataProvider('noLocationLabels')]
    public function test_no_location_labels_are_normalized(?string $input): void
    {
        $this->assertSame('ノーロケ', InventoryCountLocationResolver::normalizeLabel($input));
    }

    public function test_regular_location_is_unchanged(): void
    {
        $this->assertSame('G06303', InventoryCountLocationResolver::normalizeLabel('G06303'));
    }

    public function test_resolved_default_location_has_priority_over_snapshot_location(): void
    {
        $item = new WmsInventoryCountItem([
            'location_no' => 'A01001',
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '001',
        ]);
        $item->setAttribute('report_location_no', 'Q00000');

        $this->assertSame('Q00000', InventoryCountLocationResolver::locationNo($item));
    }

    public static function noLocationLabels(): array
    {
        return [
            'null' => [null],
            'blank' => [''],
            'z00' => ['Z00'],
            'separated z00' => ['Z-0-0'],
            'free location' => ['フリーロケ'],
            'canonical label' => ['ノーロケ'],
        ];
    }
}
