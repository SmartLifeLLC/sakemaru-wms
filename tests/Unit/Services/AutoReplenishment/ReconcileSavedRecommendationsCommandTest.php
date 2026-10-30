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
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, source_warehouse_id INTEGER, delivery_course_id INTEGER, order_unit_mode TEXT, channel TEXT, strategy_key TEXT, is_configured INTEGER, is_enabled INTEGER, reorder_point_pieces INTEGER, target_stock_pieces INTEGER, min_order_pieces INTEGER, max_order_pieces INTEGER, order_multiple_pieces INTEGER, calculation_basis TEXT)');
        $db->table('warehouses')->insert([['id' => 2, 'client_id' => 6, 'code' => 2, 'name' => '二の宮店']]);
        $db->table('items')->insert([['id' => 1, 'client_id' => 6, 'code' => 1001, 'name' => 'A'], ['id' => 2, 'client_id' => 6, 'code' => 1002, 'name' => 'B'], ['id' => 3, 'client_id' => 6, 'code' => 1003, 'name' => 'C'], ['id' => 4, 'client_id' => 6, 'code' => 1004, 'name' => 'D'], ['id' => 5, 'client_id' => 6, 'code' => 1005, 'name' => 'E'], ['id' => 6, 'client_id' => 6, 'code' => 1006, 'name' => 'F']]);
        $db->table('client_settings')->insert(['client_id' => 6, 'system_date' => '2026-10-14']);
        $db->table('ret_pos_stock_applications')->insert(['warehouse_id' => 2, 'item_id' => 1, 'business_date' => '2026-10-13']);
        $values = ['reorder_point_pieces' => 100, 'target_stock_pieces' => 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'strategy_key' => 'arrival_adaptive_v1'];
        $basis = fn (array $extra = []) => json_encode(array_replace(['method' => 'receipts-or-sales-adaptive-batch-v8', 'period' => ['start' => '2026-08-01', 'end' => '2026-10-12'], 'unit_mode' => 'CASE', 'review_days' => 7, 'safety_stock_days' => 5,
            'suggested_values' => $values, 'manually_adjusted_fields' => [], 'calculated_at' => '2026-10-13T10:00:00+09:00', 'basis_type' => 'sales', 'batch_quantity_pieces' => 24], $extra));
        $policy = fn (int $id, int $item, ?string $basis, array $override = []) => array_replace(['id' => $id, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $item, 'contractor_id' => 10, 'supplier_id' => 20, 'order_unit_mode' => 'AUTO', 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1', 'is_configured' => 1, 'is_enabled' => 0,
            'reorder_point_pieces' => 100, 'target_stock_pieces' => 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'calculation_basis' => $basis], $override);
        $db->table('wms_item_reorder_policies')->insert([
            $policy(1, 1, $basis()),                                                                    // 一致
            $policy(2, 2, $basis(), ['reorder_point_pieces' => 120]),                                   // 対象外: saved differs from the suggestion (edited after)
            $policy(3, 3, null),                                                                        // 対象外: no recommendation record
            $policy(4, 4, $basis(['manually_adjusted_fields' => ['max_order_pieces']])),               // 対象外: hand-adjusted
            $policy(5, 5, $basis(['period' => ['start' => '2026-08-01', 'end' => '2026-10-28']])),     // 再現不能: local sales end 10/13
            $policy(6, 6, $basis()),                                                                    // 不一致 (the mock returns another target)
        ]);
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
        $service->shouldReceive('recommend')->twice()->withArgs(function (int $client, int $warehouse, array $form, array $options) {
            self::assertSame('2026-10-13', $options['as_of']); // the day after the saved window end
            self::assertSame(['unit_mode' => 'CASE', 'review_days' => 7, 'safety_stock_days' => 5, 'existing_reorder_point' => null], array_diff_key($options, ['as_of' => 1]));
            self::assertSame('STANDARD', $form['shipping_unit_mode']);

            return $client === 6 && $warehouse === 2 && in_array($form['item_id'], [1, 6], true);
        })->andReturnUsing(fn ($c, $w, $form) => ['available' => true,
            'values' => ['reorder_point_pieces' => 100, 'target_stock_pieces' => $form['item_id'] === 6 ? 240 : 200, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'strategy_key' => 'arrival_adaptive_v1'],
            'basis' => ['basis_type' => 'sales', 'batch_quantity_pieces' => 24]]);
        $this->app->instance(SettingsRecommendationService::class, $service);
        $code = Artisan::call('wms:reconcile-saved-recommendations', ['client' => 6, '--out' => $this->out]);
        self::assertSame(0, $code, Artisan::output());
        $summary = json_decode(file_get_contents($this->out.'/summary.json'), true);
        self::assertSame(['一致' => 1, '不一致' => 1, '再現不能' => 1, '対象外' => 3], $summary['summary']);
        self::assertTrue($summary['environment']['read_only_verified']);
        self::assertSame(6, $summary['environment']['policies']);
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
        self::assertSame('再現不能', $byId[5]['verdict']);
        self::assertStringContainsString('2026-10-28', $byId[5]['reason']);
        self::assertSame('不一致', $byId[6]['verdict']);
        self::assertSame('{"target_stock_pieces":[200,240]}', $byId[6]['diff']);
        self::assertSame('240', $byId[6]['recalc_target']);
    }
}
