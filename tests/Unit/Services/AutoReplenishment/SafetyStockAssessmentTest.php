<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\InventoryProjection;
use App\Services\AutoReplenishment\SafetyStockAssessment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafetyStockAssessmentTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_assessment_boundaries(int $minimum, int $shortage, ?int $pieces, ?int $days, ?float $mean, string $status): void
    {
        $result = (new SafetyStockAssessment)->assess($minimum, $shortage, $pieces, $days, $mean);
        self::assertSame($status, $result['status']);
        self::assertSame($status === 'REVIEW', $result['requires_review']);
    }

    public static function cases(): array
    {
        return [
            'above safety' => [60, 0, 50, 5, 10, 'ACCEPTABLE'],
            'exactly one day' => [40, 0, 50, 5, 10, 'ACCEPTABLE'],
            'over one day' => [39, 0, 50, 5, 10, 'REVIEW'],
            'unrounded boundary' => [40, 0, 50, 5, 9.9999, 'REVIEW'],
            'three safety days' => [20, 0, 30, 3, 10, 'ACCEPTABLE'],
            'two safety days even with plenty of stock' => [100, 0, 20, 2, 10, 'ACCEPTABLE'],
            'two safety days with one day shortfall' => [10, 0, 20, 2, 10, 'ACCEPTABLE'],
            'two safety days with excess shortfall' => [9, 0, 20, 2, 10, 'REVIEW'],
            'one safety day even with plenty of stock' => [100, 0, 10, 1, 10, 'REVIEW'],
            'shortage always takes priority' => [40, 1, 50, 5, 10, 'REVIEW'],
            'exact zero without unmet demand' => [0, 0, 1, 3, 1, 'REVIEW'],
            'negative stock' => [-1, 1, 30, 3, 10, 'REVIEW'],
            'no mean' => [40, 0, 50, 5, null, 'UNASSESSED'],
            'no safety days' => [40, 0, 50, null, 10, 'UNASSESSED'],
            'no safety quantity' => [40, 0, null, 5, 10, 'UNASSESSED'],
            'zero mean with deficit' => [1, 0, 2, 3, 0, 'REVIEW'],
            'zero mean without deficit' => [2, 0, 2, 3, 0, 'ACCEPTABLE'],
            'slow mover' => [2, 0, 3, 5, 0.6, 'REVIEW'],
            'Tsuruga Wilkinson' => [168, 0, 228, 5, 45.5714, 'REVIEW'],
            'Obama Kurokirishima' => [14, 0, 18, 5, 3.5357, 'REVIEW'],
            'Echizen Super Dry' => [1308, 0, 1584, 5, 313.6364, 'ACCEPTABLE'],
        ];
    }

    public function test_chart_and_help_use_the_same_assessment_without_changing_orders(): void
    {
        $input = InventoryProjectionTest::inputs();
        $input['calendar']['arrivals'] = [];
        $input['snapshot']['stock'] = 180; // 140 demand over 14 days leaves 40.
        $policy = InventoryProjectionTest::policy();
        $baseline = (new InventoryProjection)->calculate($policy, $input);
        $policy['calculation_basis'] += ['safety_stock_pieces' => 50, 'safety_stock_days' => 5];
        $chart = (new InventoryProjection)->calculate($policy, $input);
        self::assertSame(40, $chart['minimum_stock']);
        self::assertSame('ACCEPTABLE', $chart['safety_stock_assessment']['status']);
        self::assertFalse($chart['low_stock_buffer']);
        self::assertEquals(1, $chart['safety_stock_assessment']['shortfall_days']);
        self::assertSame($baseline['days'], $chart['days']);
        self::assertSame($baseline['orders'], $chart['orders']);
        \Livewire\Livewire::test(SafetyToleranceHelpFixture::class, ['chart' => $chart])
            ->assertSee('許容範囲')->assertSee('平均消費 1.00日分');

        $input['snapshot']['stock'] = 179;
        $chart = (new InventoryProjection)->calculate($policy, $input);
        self::assertTrue($chart['low_stock_buffer']);
        \Livewire\Livewire::test(SafetyToleranceHelpFixture::class, ['chart' => $chart])
            ->assertSee('要見直し')->assertSee('平均消費 1.10日分');
    }
}

class SafetyToleranceHelpFixture extends \Livewire\Component
{
    public array $form = ['channel' => 'EOS'];

    public array $chart = [];

    public function render()
    {
        return '<div>@include("filament.pages.auto-replenishment-projection-help", ["chart" => $this->chart])</div>';
    }
}
