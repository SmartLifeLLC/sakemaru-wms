<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/** Classification of saved recommendations against a recalculation at the saved calculation date (read-only). */
class ReconcileSavedRecommendationsCommandTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.client_id' => 6, 'wms.client' => 'hana']);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code INTEGER, name TEXT)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT)');
        $db->statement('CREATE TABLE client_settings (client_id INTEGER, system_date TEXT)');
        $db->statement('CREATE TABLE ret_pos_stock_applications (warehouse_id INTEGER, item_id INTEGER, business_date TEXT)');
        $db->statement('CREATE TABLE wms_pallet_units (id INTEGER, client_id INTEGER, source_warehouse_id INTEGER, item_id INTEGER, pieces_per_pallet INTEGER, capacity_case INTEGER, version INTEGER)');
        $db->statement('CREATE TABLE wms_policy_pallet_units (policy_id INTEGER, pallet_unit_id INTEGER, pallet_version INTEGER, pieces_per_pallet INTEGER)');
        $db->statement('CREATE TABLE wms_shipping_quantity_profiles (id INTEGER, client_id INTEGER, source_warehouse_id INTEGER, item_id INTEGER, quantities TEXT, capacity_case INTEGER, version INTEGER)');
        $db->statement('CREATE TABLE wms_policy_shipping_quantities (policy_id INTEGER, profile_id INTEGER, profile_version INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, source_warehouse_id INTEGER, delivery_course_id INTEGER, order_unit_mode TEXT, channel TEXT, strategy_key TEXT, is_configured INTEGER, is_enabled INTEGER, reorder_point_pieces INTEGER, target_stock_pieces INTEGER, min_order_pieces INTEGER, max_order_pieces INTEGER, order_multiple_pieces INTEGER, calculation_basis TEXT)');
        $db->table('warehouses')->insert([['id' => 2, 'client_id' => 6, 'code' => 2, 'name' => '二の宮店']]);
        $db->table('items')->insert([['id' => 1, 'client_id' => 6, 'code' => 1001, 'name' => 'A'], ['id' => 2, 'client_id' => 6, 'code' => 1002, 'name' => 'B'], ['id' => 3, 'client_id' => 6, 'code' => 1003, 'name' => 'C'], ['id' => 4, 'client_id' => 6, 'code' => 1004, 'name' => 'D'], ['id' => 5, 'client_id' => 6, 'code' => 1005, 'name' => 'E'], ['id' => 6, 'client_id' => 6, 'code' => 1006, 'name' => 'F'], ['id' => 7, 'client_id' => 6, 'code' => 1007, 'name' => 'G']]);
        $db->table('client_settings')->insert(['client_id' => 6, 'system_date' => '2026-10-14']);
        $db->table('ret_pos_stock_applications')->insert(['warehouse_id' => 2, 'item_id' => 1, 'business_date' => '2026-10-13']);
        $values = ['reorder_point_pieces' => 100, 'target_stock_pieces' => 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'strategy_key' => 'arrival_adaptive_v1'];
        $basis = fn (array $extra = []) => json_encode(array_replace(['method' => 'receipts-or-sales-adaptive-batch-v8', 'period' => ['start' => '2026-08-01', 'end' => '2026-10-12'], 'unit_mode' => 'CASE', 'review_days' => 7, 'safety_stock_days' => 5,
            'suggested_values' => $values, 'manually_adjusted_fields' => [], 'calculated_at' => '2026-10-13T10:00:00+09:00', 'basis_type' => 'sales', 'batch_quantity_pieces' => 24], $extra));
        $policy = fn (int $id, int $item, ?string $basis, array $override = []) => array_replace(['id' => $id, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $item, 'contractor_id' => 10, 'supplier_id' => 20, 'order_unit_mode' => 'AUTO', 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1', 'is_configured' => 1, 'is_enabled' => 0,
            'reorder_point_pieces' => 100, 'target_stock_pieces' => 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'calculation_basis' => $basis], $override);
        $db->table('wms_item_reorder_policies')->insert([
            $policy(1, 1, $basis(['pallet_unit' => ['id' => 50]])),                                     // 一致 (saved with the pallet binding it still has)
            $policy(2, 2, $basis(), ['reorder_point_pieces' => 120]),                                   // 対象外: saved differs from the suggestion (edited after)
            $policy(3, 3, null),                                                                        // 対象外: no recommendation record
            $policy(4, 4, $basis(['manually_adjusted_fields' => ['max_order_pieces']])),               // 対象外: hand-adjusted
            $policy(5, 5, $basis(['period' => ['start' => '2026-08-01', 'end' => '2026-10-28']])),     // 再現不能: local sales end 10/13
            $policy(6, 6, $basis()),                                                                    // 不一致 (the mock returns another target)
            $policy(7, 7, $basis(['demand_profile' => ['event_forecast' => ['model_version' => 'event-factors-v2', 'events' => [['type' => 'YEAR_END', 'name' => '年末年始', 'start' => '2026-12-20', 'end' => '2027-01-07', 'pre_days' => 0, 'post_days' => 0, 'factor_source' => 'item',
                'factors' => ['item' => ['YEAR_END' => ['trained_through' => '2026-01-08', 'instance_count' => 3, 'positions_digest' => 'digest-of-2x']]]]]]]])), // 再現不能: same event, factors re-learned (2× → 3×), numbers equal
        ]);
        $db->table('items')->insert(['id' => 8, 'client_id' => 6, 'code' => 1008, 'name' => 'H']);
        $db->table('wms_item_reorder_policies')->insert($policy(8, 8, $basis(['demand_profile' => ['event_forecast' => ['events' => [['factors' => ['item' => ['YEAR_END' => ['positions_digest' => 'digest-of-3x', 'pieces' => 120.5, 'instance_count' => 3, 'trained_through' => '2026-10-20']]],
            'factor_source' => 'item', 'post_days' => 0, 'pre_days' => 0, 'end' => '2027-01-07', 'start' => '2026-12-20', 'name' => '年末年始', 'type' => 'YEAR_END']], 'model_version' => 'event-factors-v2']]]))); // 一致: same factors, another key order at every level
        // Local policy #1 (warehouse 2 × item 1) is bound to a pallet; a foreign export may reuse the id 1 for another product.
        $db->table('wms_pallet_units')->insert(['id' => 50, 'client_id' => 6, 'source_warehouse_id' => 91, 'item_id' => 1, 'pieces_per_pallet' => 144, 'capacity_case' => 24, 'version' => 1]);
        $db->table('wms_policy_pallet_units')->insert(['policy_id' => 1, 'pallet_unit_id' => 50, 'pallet_version' => 1, 'pieces_per_pallet' => 144]);
        $this->out = sys_get_temp_dir().'/reconcile-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (isset($this->out) && is_dir($this->out)) {
            array_map('unlink', glob($this->out.'/*') ?: []);
            rmdir($this->out);
        }
        parent::tearDown();
    }

    public function test_every_saved_policy_is_classified_and_nothing_is_written(): void
    {
        $service = Mockery::mock(SettingsRecommendationService::class);
        $service->shouldReceive('recommend')->times(5)->withArgs(function (int $client, int $warehouse, array $form, array $options) {
            self::assertSame($form['item_id'] === 5 ? '2026-10-29' : '2026-10-13', $options['as_of'], 'item '.$form['item_id'].' as_of'); // the day after the saved window end
            self::assertSame(['unit_mode' => 'CASE', 'review_days' => 7, 'safety_stock_days' => 5, 'existing_reorder_point' => null], array_diff_key($options, ['as_of' => 1]));
            self::assertSame($form['item_id'] === 1 ? 'PALLET' : 'STANDARD', $form['shipping_unit_mode'], 'item '.$form['item_id'].' mode'); // the binding of the local policy of the same warehouse × product

            return $client === 6 && $warehouse === 2 && in_array($form['item_id'], [1, 5, 6, 7, 8], true);
        })->andReturnUsing(fn ($c, $w, $form) => ['available' => true,
            'values' => ['reorder_point_pieces' => 100, 'target_stock_pieces' => $form['item_id'] === 6 ? 240 : 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'strategy_key' => 'arrival_adaptive_v1'],
            'basis' => ['basis_type' => 'sales', 'batch_quantity_pieces' => 24] + ($form['item_id'] === 1 ? ['pallet_unit' => ['id' => 50]] : [])
                + (in_array($form['item_id'], [7, 8], true) ? ['demand_profile' => ['event_forecast' => ['model_version' => 'event-factors-v2', 'events' => [['type' => 'YEAR_END', 'name' => '年末年始', 'start' => '2026-12-20', 'end' => '2027-01-07', 'pre_days' => 0, 'post_days' => 0, 'factor_source' => 'item',
                    'factors' => ['item' => ['YEAR_END' => ['trained_through' => '2026-10-20', 'instance_count' => 3, 'pieces' => 120.5, 'positions_digest' => 'digest-of-3x']]]]]]]] : [])]);
        $this->app->instance(SettingsRecommendationService::class, $service);
        $code = Artisan::call('wms:reconcile-saved-recommendations', ['client' => 6, '--out' => $this->out]);
        self::assertSame(0, $code, Artisan::output());
        $summary = json_decode(file_get_contents($this->out.'/summary.json'), true);
        self::assertSame(['一致' => 2, '不一致' => 1, '再現不能' => 2, '対象外' => 3], $summary['summary']);
        self::assertTrue($summary['environment']['read_only_verified']);
        self::assertSame(8, $summary['environment']['policies']);
        self::assertSame(0, $summary['environment']['enabled_policies']);
        $rows = array_map('str_getcsv', file($this->out.'/reconcile.csv'));
        $header = array_shift($rows);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row[0]] = array_combine($header, $row);
        }
        self::assertSame('一致', $byId[1]['verdict']);
        self::assertSame('対象外', $byId[2]['verdict']);
        self::assertStringContainsString('提案値と異なる', $byId[2]['reason']);
        self::assertSame('対象外', $byId[3]['verdict']);
        self::assertSame('対象外', $byId[4]['verdict']);
        self::assertStringContainsString('手動調整済み', $byId[4]['reason']);
        self::assertSame('再現不能', $byId[5]['verdict']); // equal numbers do not make a 一致 when the demand window cannot be the same
        self::assertStringContainsString('2026-10-28', $byId[5]['reason']);
        self::assertStringContainsString('数値は同じ', $byId[5]['reason']);
        self::assertSame('不一致', $byId[6]['verdict']);
        self::assertSame('{"target_stock_pieces":[200,240]}', $byId[6]['diff']);
        self::assertSame('240', $byId[6]['recalc_target']);
        self::assertSame('再現不能', $byId[7]['verdict']); // event conditions differ: not 一致 even though every value agrees
        self::assertStringContainsString('イベント条件', $byId[7]['reason']);
        self::assertStringContainsString('数値は同じ', $byId[7]['reason']);
        self::assertStringContainsString('接続先ポリシー #1', $byId[1]['conditions_source']);
        self::assertSame('一致', $byId[8]['verdict']); // identical factors stored in another key order are the same condition
    }

    public function test_old_quantity_model_is_a_condition_change_even_when_quantities_happen_to_match(): void
    {
        $policy = (array) DB::connection('sakemaru')->table('wms_item_reorder_policies')->where('id', 1)->first();
        $policy['calculation_basis'] = json_decode($policy['calculation_basis'], true);
        $service = Mockery::mock(SettingsRecommendationService::class);
        $service->shouldReceive('recommend')->times(3)->andReturn(['available' => true,
            'values' => $policy['calculation_basis']['suggested_values'],
            'basis' => ['quantity_model' => 'safety-stock-days-v2', 'basis_type' => 'sales', 'batch_quantity_pieces' => 24, 'pallet_unit' => ['id' => 50]]]);
        $command = app(\App\Console\Commands\AutoOrder\ReconcileSavedRecommendationsCommand::class);
        $method = new \ReflectionMethod($command, 'reconcile');
        foreach ([null, 'safety-stock-days-v1', 'safety-stock-days-v2'] as $version) {
            $policy['calculation_basis']['quantity_model'] = $version;
            $r = $method->invoke($command, 6, $policy, $service, [2 => '2026-10-13']);
            self::assertSame($version === 'safety-stock-days-v2' ? '一致' : '再現不能', $r['verdict']);
            if ($version !== 'safety-stock-days-v2') {
                self::assertStringContainsString('数量計算版', $r['reason']);
            }
        }
    }

    /** A JSON export of another environment carries that environment's policy ids: the binding is resolved by warehouse × product, never by id. */
    public function test_foreign_policy_ids_in_a_json_export_never_borrow_another_products_binding(): void
    {
        $values = ['reorder_point_pieces' => 100, 'target_stock_pieces' => 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'strategy_key' => 'arrival_adaptive_v1'];
        $basis = ['method' => 'receipts-or-sales-adaptive-batch-v8', 'period' => ['start' => '2026-08-01', 'end' => '2026-10-12'], 'unit_mode' => 'CASE', 'review_days' => 7, 'safety_stock_days' => 5,
            'suggested_values' => $values, 'manually_adjusted_fields' => [], 'calculated_at' => '2026-10-13T10:00:00+09:00', 'basis_type' => 'sales', 'batch_quantity_pieces' => 24];
        $row = fn (int $id, int $item, array $extra = []) => $values + ['id' => $id, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $item, 'contractor_id' => 10, 'supplier_id' => 20, 'order_unit_mode' => 'AUTO', 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1',
            'is_configured' => 1, 'is_enabled' => 0, 'calculation_basis' => json_encode($basis)] + $extra;
        // id 1 in the export is item 6 (local #1 is item 1 with a pallet); id 999 is item 1 (local #1); id 2 is item 9 that has no local policy at all; id 3 states its mode explicitly.
        $json = sys_get_temp_dir().'/reconcile-foreign-'.uniqid().'.json';
        file_put_contents($json, json_encode([$row(1, 6), $row(999, 1), $row(2, 9), $row(3, 6, ['shipping_unit_mode' => 'SHIPMENT'])]));
        $seen = [];
        $service = Mockery::mock(SettingsRecommendationService::class);
        $service->shouldReceive('recommend')->times(4)->withArgs(function ($c, $w, array $form) use (&$seen) {
            $seen[$form['item_id'].':'.($form['shipping_unit_mode'])] = true;

            return true;
        })->andReturnUsing(fn ($c, $w, array $form) => ['available' => true, 'values' => $values,
            'basis' => ['basis_type' => 'sales', 'batch_quantity_pieces' => 24] + ($form['shipping_unit_mode'] === 'PALLET' ? ['pallet_unit' => ['id' => 50]] : [])]);
        $this->app->instance(SettingsRecommendationService::class, $service);
        $code = Artisan::call('wms:reconcile-saved-recommendations', ['client' => 6, '--json' => $json, '--out' => $this->out]);
        unlink($json);
        self::assertSame(0, $code, Artisan::output());
        self::assertSame(['6:STANDARD', '1:PALLET', '9:STANDARD', '6:SHIPMENT'], array_keys($seen));
        $rows = array_map('str_getcsv', file($this->out.'/reconcile.csv'));
        $header = array_shift($rows);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r[0]] = array_combine($header, $r);
        }
        self::assertStringContainsString('接続先に同じ倉庫×商品のポリシーが無い', $byId[2]['conditions_source']);
        self::assertStringContainsString('入力行の shipping_unit_mode', $byId[3]['conditions_source']);
        // The local policy #1 was bound to a pallet, so recalculating it without the pallet basis is a condition gap, not a match.
        self::assertSame('再現不能', $byId[999]['verdict']);
        self::assertStringContainsString('数量制約', $byId[999]['reason']);
        self::assertSame('一致', $byId[1]['verdict']);
    }
}
