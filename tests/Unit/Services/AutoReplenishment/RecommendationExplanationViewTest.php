<?php

namespace Tests\Unit\Services\AutoReplenishment;

use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class RecommendationExplanationViewTest extends TestCase
{
    public function test_demand_period_and_receipt_history_period_are_displayed_separately(): void
    {
        $recommendation = $this->recommendation();
        $daily = [];
        for ($i = 0; $i < 28; $i++) {
            $daily[\Carbon\CarbonImmutable::parse('2026-09-26')->addDays($i)->toDateString()] = 1;
        }
        $recommendation['basis']['period'] = ['start' => '2026-08-01', 'end' => '2026-10-23'];
        $recommendation['basis']['demand_profile'] = app(\App\Services\AutoReplenishment\DemandProfile::class)->create($daily);
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->assertSee('2026-09-26〜2026-10-23')->assertSee('観測 28日')
            ->assertSee('平均 1.00バラ／日')->assertSee('発注実績期間：2026-08-01〜2026-10-23')
            ->assertSee('当日入荷予定は計算上の在庫に加算し、未入荷残から差し引きます。')
            ->assertDontSee('算定根拠・実績を確認 （2026-08-01');
    }

    private function recommendation(): array
    {
        $values = ['strategy_key' => 'arrival_adaptive_v1', 'reorder_point_pieces' => 6,
            'min_order_pieces' => 6, 'max_order_pieces' => 12, 'order_multiple_pieces' => 1, 'target_stock_pieces' => 10];

        return ['available' => true, 'values' => $values, 'basis' => [
            'scope' => ['packing_unit' => 1], 'mean_daily' => 1, 'batch_quantity_pieces' => 6,
            'batch_frequency' => 4, 'minimum_quantity_pieces' => 6, 'maximum_quantity_pieces' => 12,
            'maximum_frequency' => 2, 'own_receipt_count' => 6, 'own_receipt_days' => 6,
            'batch_rule' => 'observed-mode-with-historical-maximum',
            'suggested_values' => $values,
        ]];
    }

    public function test_manual_changes_update_current_explanation_without_rewriting_historical_evidence(): void
    {
        $recommendation = $this->recommendation();
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->set('form.max_order_pieces', 6)
            ->assertSee('最大 6・発注単位 1の範囲で 6バラ')
            ->assertSee('実績の最小 6 ／ 最大 12バラ（2回）')
            ->assertDontSee('最大 12・発注単位 1の範囲で');
    }

    public function test_reopened_saved_policy_uses_saved_values_instead_of_original_suggestions(): void
    {
        $recommendation = $this->recommendation();
        $form = array_replace($recommendation['values'], ['max_order_pieces' => 10, 'min_order_pieces' => 2,
            'order_multiple_pieces' => 4, 'target_stock_pieces' => 20]);
        unset($recommendation['values']);
        Livewire::test(RecommendationExplanationFixture::class, compact('form', 'recommendation'))
            ->assertSee('最大 10 ／ 単位 4 ／ 過剰在庫線 20バラ')->assertSee('約20.0日分')
            ->assertSee('最大 10・発注単位 4の範囲で 8バラ')
            ->assertSee('実績の最小 6 ／ 最大 12バラ（2回）');
    }

    public function test_invalid_or_missing_current_values_never_render_a_fabricated_quantity_example(): void
    {
        $recommendation = $this->recommendation();
        $test = Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation]);
        foreach (['', 0, -1, '1.2', 2147483648, 'invalid'] as $invalid) {
            $test->set('form.max_order_pieces', $invalid)
                ->assertSee('数量例は表示できません')
                ->assertDontSee('数量例（入数はおすすめ算定時）：');
        }
        $test->set('form.max_order_pieces', 12)->set('form.min_order_pieces', 13)
            ->assertSee('数量例は表示できません')->assertDontSee('数量例（入数はおすすめ算定時）：');
    }

    public function test_current_method_controls_explanation_and_empty_target_uses_current_point(): void
    {
        $recommendation = $this->recommendation();
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->set('form.strategy_key', 'arrival_batch_v1')
            ->set('form.min_order_pieces', 4)
            ->set('form.target_stock_pieces', '')
            ->set('form.reorder_point_pieces', 8)
            ->assertSee('過剰在庫線 8バラ')->assertSee('約8.0日分')
            ->assertSee('固定 4 ＝ 4バラ（目標 8以内）')
            ->assertDontSee('通常量をまとめて発注。')
            ->set('form.strategy_key', 'threshold_v1')
            ->assertSee('最大発注数を超える場合は全量を保留します。')
            ->assertDontSee('固定 4');
    }

    public function test_quantity_example_obeys_output_pack_and_never_rounds_above_target_or_below_minimum(): void
    {
        $recommendation = $this->recommendation();
        $recommendation['basis']['scope']['packing_unit'] = 4;
        $form = array_replace($recommendation['values'], ['order_multiple_pieces' => 3,
            'min_order_pieces' => 2, 'max_order_pieces' => 20, 'target_stock_pieces' => 30]);
        Livewire::test(RecommendationExplanationFixture::class, compact('form', 'recommendation'))
            ->assertSee('最大 20・発注単位 12の範囲で 12バラ')
            ->set('form.target_stock_pieces', 11)
            ->assertSee('過剰在庫線内で最小発注数・発注単位を満たせない設定です')
            ->assertDontSee('数量例（入数はおすすめ算定時）：');
    }

    public function test_recommendation_explains_schedule_gap_and_keeps_historical_case_capacity_explicit(): void
    {
        $recommendation = $this->recommendation();
        $recommendation['basis']['review_interval_days'] = 2;
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->assertSee('次の実行まで最大2日')
            ->assertSee('過剰在庫線は「通常量＋1日分」と「次の実行までの需要＋最小1回分」の大きい方です。')
            ->assertSee('数量例（入数はおすすめ算定時）');
    }

    public function test_sparse_sales_explanation_keeps_known_receipt_quantities_and_new_arrival_gap(): void
    {
        $recommendation = $this->recommendation();
        $recommendation['basis'] = array_replace($recommendation['basis'], ['basis_type' => 'sales',
            'review_days' => 7, 'own_receipt_count' => 4, 'own_receipt_days' => 4,
            'observed_quantity_counts' => [['pieces' => 6, 'count' => 2], ['pieces' => 12, 'count' => 2]],
            'observed_minimum_quantity_pieces' => 6, 'observed_maximum_quantity_pieces' => 12,
            'maximum_arrival_gap_days' => 3]);
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->assertSee('6バラ 2回')->assertSee('12バラ 2回')
            ->assertSee('実績の最小 6 ／ 最大 12バラ')
            ->assertSee('既存設定での入荷間隔：最大3日')
            ->assertDontSee('過去の最大量は未確認');
    }

    public function test_sales_explanation_distinguishes_insufficient_unit_matching_history(): void
    {
        $recommendation = $this->recommendation();
        $recommendation['basis'] = array_replace($recommendation['basis'], ['basis_type' => 'sales', 'own_receipt_days' => 8,
            'eligible_receipt_days' => 3, 'own_receipt_count' => 8]);
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->assertSee('選択した発注単位に合う実績が不足するため、売上基準です')
            ->assertDontSee('実績日数が少ないため');
    }

    public function test_candidate_cards_show_units_and_only_mark_current_unchanged_values_as_filled(): void
    {
        $recommendation = $this->recommendation();
        $recommendation['basis']['candidate_key'] = 'sales';
        $recommendation['candidates'] = ['sales' => $recommendation,
            'history' => ['available' => true, 'values' => array_replace($recommendation['values'], ['min_order_pieces' => 12]),
                'basis' => ['batch_quantity_pieces' => 12, 'own_receipt_count' => 4, 'own_receipt_days' => 4, 'mean_daily' => 1]]];
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->assertSee('売上基準')->assertSee('過去量を参考')->assertSee('入力済み')
            ->assertSee('参考値です')->assertSee('発注回数の減少を保証するものではありません')
            ->set('form.max_order_pieces', 8)->assertSee('手修正あり')->assertSee('この候補に戻す');
    }

    public function test_unavailable_sales_candidate_is_never_marked_as_filled_when_history_is_available(): void
    {
        $history = $this->recommendation();
        $sales = ['available' => false, 'values' => [], 'basis' => ['candidate_key' => 'sales',
            'reason' => '売上基準では週の需要を補えません。']];
        $recommendation = $sales + ['candidates' => ['sales' => $sales, 'history' => $history]];
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $history['values'], 'recommendation' => $recommendation])
            ->assertSee('算定不可')->assertSee('売上基準では週の需要を補えません。')
            ->assertSee('この候補を入力')->assertDontSee('入力済み')->assertDontSeeHtml('<span>入力中</span>')->assertDontSee('手修正あり');
    }

    public function test_sales_fallback_preserves_original_basis_and_separates_edited_limit(): void
    {
        $recommendation = $this->recommendation();
        $recommendation['basis'] = array_replace($recommendation['basis'], ['basis_type' => 'sales',
            'batch_frequency' => null, 'review_days' => 7, 'batch_quantity_pieces' => 7, 'unit_basis' => 'バラ主体',
            'batch_rule' => 'mean-demand-times-review-days', 'own_receipt_count' => 0, 'own_receipt_days' => 0]);
        Livewire::test(RecommendationExplanationFixture::class, ['form' => $recommendation['values'], 'recommendation' => $recommendation])
            ->set('form.max_order_pieces', 6)
            ->assertSee('最大 6')
            ->assertSee('通常量の目安：1日平均 1.00 × 7日分')
            ->assertSee('過去の最大量は未確認')
            ->assertDontSee('実績の最小');
    }
}

class RecommendationExplanationFixture extends Component
{
    public array $form = [];

    public array $recommendation = [];

    public function render()
    {
        return '<div>@include("filament.pages.auto-replenishment-candidates")@include("filament.pages.auto-replenishment-recommendation")</div>';
    }
}
