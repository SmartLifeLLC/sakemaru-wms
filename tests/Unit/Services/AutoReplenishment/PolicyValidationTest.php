<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PolicyValidationTest extends TestCase
{
    public function test_required_fields_use_the_same_japanese_labels_as_the_screen(): void
    {
        app()->setLocale('en');
        try {
            (new PolicyService(new ReplenishmentGuard))->validate([]);
            self::fail('Empty product settings must not be accepted.');
        } catch (ValidationException $exception) {
            self::assertSame(['商品を選択してください。'], $exception->errors()['item_id']);
            self::assertSame(['最大発注数を入力してください。'], $exception->errors()['max_order_pieces']);
            self::assertSame(['発注先を選択してください。'], $exception->errors()['contractor_id']);
            self::assertDoesNotMatchRegularExpression('/[a-z_]/i', implode(' ', $exception->validator->errors()->all()));
        }
    }

    public function test_minimum_over_maximum_tells_the_operator_which_values_to_correct(): void
    {
        try {
            (new PolicyService(new ReplenishmentGuard))->validate($this->product() + ['min_order_pieces' => 25]);
            self::fail('Minimum above maximum must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['最小発注数は最大発注数以下にしてください。'], $exception->errors()['min_order_pieces']);
        }
    }

    public function test_warehouse_schedule_errors_do_not_expose_internal_field_names(): void
    {
        try {
            (new PolicyService(new ReplenishmentGuard))->saveWarehouse(6, 3, [
                'version' => 0, 'generation' => 1, 'execution_time' => '',
                'sunday_execution_time' => '25:00', 'weekdays' => [],
            ], false, 1);
            self::fail('Invalid schedule must be rejected before saving.');
        } catch (ValidationException $exception) {
            self::assertSame(['月〜土の生成時刻を入力してください。'], $exception->errors()['execution_time']);
            self::assertSame(['日曜日の生成時刻は「10:00」のように24時間表記で入力してください。'], $exception->errors()['sunday_execution_time']);
            self::assertSame(['実行曜日を1つ以上選択してください。'], $exception->errors()['weekdays']);
            self::assertArrayNotHasKey('data_verified', $exception->errors());
            self::assertDoesNotMatchRegularExpression('/[a-z_]/i', implode(' ', $exception->validator->errors()->all()));
        }
    }

    public function test_product_arrival_rule_and_approval_are_no_longer_input_fields(): void
    {
        $values = (new PolicyService(new ReplenishmentGuard))->validate($this->product() + [
            'min_order_pieces' => 1, 'arrival_rule' => ['approved' => false, 'lead_days' => 0, 'weekdays' => []],
        ]);
        self::assertArrayNotHasKey('arrival_rule', $values);
    }

    public function test_quantity_input_cannot_implicitly_enable_a_policy(): void
    {
        $values = (new PolicyService(new ReplenishmentGuard))->validate($this->product() + [
            'min_order_pieces' => 1, 'is_enabled' => true,
        ]);
        self::assertArrayNotHasKey('is_enabled', $values);
    }

    public function test_arrival_target_can_be_lower_than_the_long_lead_threshold_but_legacy_rules_stay_the_same(): void
    {
        $service = new PolicyService(new ReplenishmentGuard);
        $data = array_replace($this->product(), ['min_order_pieces' => 1, 'target_stock_pieces' => 5, 'strategy_key' => 'arrival_batch_v1']);
        self::assertSame(5, $service->validate($data)['target_stock_pieces']);
        $data['strategy_key'] = 'arrival_adaptive_v1';
        self::assertSame(5, $service->validate($data)['target_stock_pieces']);
        $data['strategy_key'] = 'threshold_v1';
        $this->expectException(ValidationException::class);
        $service->validate($data);
    }

    public function test_switching_from_legacy_median_to_adaptive_requires_new_recommendation(): void
    {
        $data = array_replace($this->product(), ['min_order_pieces' => 1, 'strategy_key' => 'arrival_adaptive_v1']);
        $basis = ['scope' => ['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 1, 'channel' => 'EOS',
            'contractor_id' => 1], 'batch_rule' => 'median', 'mean_daily' => 1, 'batch_quantity_pieces' => 12];
        $this->expectExceptionMessage('「おすすめ設定」で実績を再計算');
        (new PolicyService(new ReplenishmentGuard))->save(6, 1, $data, 1, $basis);
    }

    private function product(): array
    {
        return ['item_id' => 1, 'reorder_point_pieces' => 10, 'max_order_pieces' => 24,
            'order_multiple_pieces' => 1, 'channel' => 'EOS', 'contractor_id' => 1, 'strategy_key' => 'threshold_v1'];
    }

    public function test_order_unit_selection_accepts_only_supported_single_output_units(): void
    {
        $service = new PolicyService(new ReplenishmentGuard);
        foreach (['AUTO', 'CASE', 'PIECE'] as $mode) {
            self::assertSame($mode, $service->validate($this->product() + ['min_order_pieces' => 1, 'order_unit_mode' => $mode])['order_unit_mode']);
        }
        try {
            $service->validate($this->product() + ['min_order_pieces' => 1, 'order_unit_mode' => 'CARTON']);
            self::fail('Unsupported unit was accepted.');
        } catch (ValidationException $e) {
            self::assertSame(['発注単位を選び直してください。'], $e->errors()['order_unit_mode']);
        }
    }
}
