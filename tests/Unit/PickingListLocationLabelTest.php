<?php

namespace Tests\Unit;

use App\Services\PickingList\PickingListLocationLabel;
use PHPUnit\Framework\TestCase;

class PickingListLocationLabelTest extends TestCase
{
    public function test_missing_and_default_locations_use_no_location_label(): void
    {
        foreach ([null, '', ' ', '　', '未設定', 'Z-0-0', 'Z00', 'z 0 0', 'Z_0_0', 'フリーロケ', 'ノーロケ'] as $code) {
            self::assertSame('ノーロケ', PickingListLocationLabel::format($code));
        }
    }

    public function test_real_shelf_codes_are_preserved_including_z_and_zero_segments(): void
    {
        foreach (['YX-0-000', 'Z-00-105', 'A-01-01', 'Z-01', '0', 'Z-0-001'] as $code) {
            self::assertSame($code, PickingListLocationLabel::format($code));
        }
    }
}
