<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\OrderTiming;
use App\Services\AutoReplenishment\StrategyCalculator;
use Tests\TestCase;

class OrderTimingTest extends TestCase
{
    private function opportunities(): array
    {
        $rows = [];
        foreach (['2026-11-01' => '2026-11-03', '2026-11-02' => '2026-11-03', '2026-11-03' => '2026-11-05',
            '2026-11-04' => '2026-11-05', '2026-11-05' => '2026-11-06', '2026-11-06' => '2026-11-07'] as $order => $arrival) {
            $rows[] = ['generation_date' => $order, 'order_base_date' => $order, 'arrival_date' => $arrival];
        }

        return $rows;
    }

    public function test_obama_waits_until_monday_for_tuesday_and_wednesday_for_thursday(): void
    {
        $timing = new OrderTiming;
        $schedule = $timing->selectLatest($this->opportunities());
        self::assertSame(['2026-11-02', '2026-11-04', '2026-11-05', '2026-11-06'], array_column($schedule['opportunities'], 'generation_date'));
        $decision = $timing->decision('2026-11-03', '2026-11-05', $schedule, []);
        self::assertFalse($decision['eligible']);
        self::assertSame('2026-11-04', $decision['next_generation_date']);
        self::assertSame('2026-11-05', $decision['next_arrival_date']);
        self::assertTrue($timing->decision('2026-11-04', '2026-11-05', $schedule, [])['eligible']);
        $proposal = (new StrategyCalculator)->calculate([], ['order_timing' => $decision, 'stock' => 0]);
        self::assertSame('NO_ORDER', $proposal['phase']);
        self::assertSame(0, $proposal['quantity']);
        self::assertSame('WAIT_ORDER_DAY', $proposal['reason_code']);
        (new \App\Services\AutoReplenishment\ProposalSafety)->validate([], $proposal, 1);
    }

    public function test_different_weekday_leads_and_late_cutoff_never_generate_an_earlier_unnecessary_order(): void
    {
        $timing = new OrderTiming;
        $schedule = $timing->selectLatest([
            ['generation_date' => '2026-11-02', 'arrival_date' => '2026-11-06'],
            ['generation_date' => '2026-11-03', 'arrival_date' => '2026-11-04'],
            ['generation_date' => '2026-11-04', 'arrival_date' => '2026-11-05'],
        ]);
        self::assertCount(2, $schedule['opportunities']);
        self::assertFalse($timing->decision('2026-11-02', '2026-11-06', $schedule, [])['eligible']);
        self::assertTrue($timing->decision('2026-11-03', '2026-11-04', $schedule, [])['eligible']);
        self::assertFalse($timing->decision('2026-11-03', '2026-11-05', $schedule, [])['eligible']);
    }

    public function test_latest_day_rechecks_current_stock_and_same_day_receipts(): void
    {
        $timing = new OrderTiming;
        $schedule = $timing->selectLatest($this->opportunities());
        $policy = PositionNormalBatchStrategyTest::policy();
        $snapshot = PositionNormalBatchStrategyTest::snapshot([
            'business_date' => '2026-11-03', 'arrival_date' => '2026-11-05',
            'order_timing' => $timing->decision('2026-11-03', '2026-11-05', $schedule, []),
        ]);
        self::assertSame('WAIT_ORDER_DAY', (new StrategyCalculator)->calculate($policy, $snapshot)['reason_code']);
        $snapshot['business_date'] = '2026-11-04';
        $snapshot['order_timing'] = $timing->decision('2026-11-04', '2026-11-05', $schedule, []);
        $snapshot['next_generation_date'] = $snapshot['order_timing']['next_generation_date'];
        $snapshot['stock'] = 150;
        self::assertSame(0, (new StrategyCalculator)->calculate($policy, $snapshot)['quantity']);
        $snapshot['stock'] = 100;
        self::assertSame(96, (new StrategyCalculator)->calculate($policy, $snapshot)['quantity']);
        $snapshot['incoming'] = 60;
        $snapshot['incoming_today'] = 60;
        $snapshot['supply_events'] = [['date' => '2026-11-04', 'pieces' => 60]];
        $result = (new StrategyCalculator)->calculate($policy, $snapshot);
        self::assertSame(160, $result['decision_inventory']['position']);
        self::assertSame(0, $result['quantity']);
    }

    public function test_replay_uses_the_same_recommendation_schedule_metadata(): void
    {
        $expected = ['nominal_lead_days' => 1, 'review_interval_days' => 9,
            'review_weekdays' => [1, 3, 4, 5], 'opportunities' => $this->opportunities()];
        $calendar = \App\Services\AutoReplenishment\Replay\ReplayCalendar::scheduled(
            fn ($date) => $expected['opportunities'], 2, fn ($date, $days) => $expected);
        self::assertSame($expected + ['weekdays' => [1, 3, 4, 5]], $calendar->schedule('2026-11-01'));
    }

    public function test_graph_waits_and_receives_existing_supply_on_its_original_date(): void
    {
        $policy = PositionNormalBatchStrategyTest::policy();
        $snapshot = PositionNormalBatchStrategyTest::snapshot([
            'business_date' => '2026-11-03', 'arrival_date' => '2026-11-05',
            'incoming' => 24, 'supply_events' => [['date' => '2026-11-03', 'pieces' => 24]],
            'capacity_case' => 24, 'quantity_type' => 'CASE',
            'order_timing' => ['eligible' => false, 'reason' => 'stale initial timing'],
        ]);
        $calendar = [
            'arrivals' => ['2026-11-04' => '2026-11-05', '2026-11-05' => '2026-11-06'],
            'deferred' => ['2026-11-03' => ['generation_date' => '2026-11-04', 'arrival_date' => '2026-11-05']],
        ];
        $graph = (new \App\Services\AutoReplenishment\InventoryProjection)->calculate($policy, [
            'snapshot' => $snapshot, 'calendar' => $calendar, 'observed_at' => '2026-11-03 01:00:00',
        ]);
        self::assertSame('NO_ORDER', $graph['days'][0]['phase']);
        self::assertSame('WAIT_ORDER_DAY', $graph['days'][0]['reason_code']);
        self::assertSame(24, $graph['days'][0]['existing_receipt']);
        self::assertSame(124, $graph['days'][0]['after_receipt']);
        self::assertSame(0, $graph['days'][0]['ordered']);
        self::assertSame(96, $graph['days'][1]['ordered']);
        self::assertSame('2026-11-05', $graph['days'][1]['arrival_date']);
    }
}
