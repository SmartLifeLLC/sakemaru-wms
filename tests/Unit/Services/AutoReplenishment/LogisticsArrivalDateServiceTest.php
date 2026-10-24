<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\ArrivalCalendar;
use App\Services\AutoReplenishment\LogisticsArrivalDateService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class LogisticsArrivalDateServiceTest extends TestCase
{
    public function test_unapproved_legacy_product_rule_does_not_override_existing_delivery_settings(): void
    {
        $policy = new WmsItemReorderPolicy(['warehouse_id' => 3, 'contractor_id' => 9012,
            'arrival_rule' => ['approved' => false, 'lead_days' => 30, 'weekdays' => [0]]]);
        $resolver = Mockery::mock(ArrivalCalendar::class);
        $resolver->shouldReceive('earliestArrivalDate')->once()
            ->withArgs(fn ($c, $w, $date) => $c === 9012 && $w === 3 && $date->toDateString() === '2026-10-18')
            ->andReturn(Carbon::parse('2026-10-20'));
        app()->instance(ArrivalCalendar::class, $resolver);

        self::assertSame('2026-10-20', (new LogisticsArrivalDateService)->resolve($policy, '2026-10-18')->toDateString());
    }

    public function test_product_rule_is_not_required(): void
    {
        $resolver = Mockery::mock(ArrivalCalendar::class);
        $resolver->shouldReceive('earliestArrivalDate')->once()->andReturn(Carbon::parse('2026-10-22'));
        app()->instance(ArrivalCalendar::class, $resolver);
        $policy = new WmsItemReorderPolicy(['warehouse_id' => 1, 'contractor_id' => 9012]);

        self::assertSame('2026-10-22', (new LogisticsArrivalDateService)->resolve($policy, '2026-10-20')->toDateString());
    }

    public function test_no_available_day_from_existing_settings_still_holds_the_order(): void
    {
        $resolver = Mockery::mock(ArrivalCalendar::class);
        $resolver->shouldReceive('earliestArrivalDate')->once()->andReturnNull();
        app()->instance(ArrivalCalendar::class, $resolver);
        $this->expectExceptionMessage('発注先・倉庫の納品曜日と休日設定を確認してください。');
        (new LogisticsArrivalDateService)->resolve(new WmsItemReorderPolicy(['warehouse_id' => 3, 'contractor_id' => 9012]), '2026-10-18');
    }
}
