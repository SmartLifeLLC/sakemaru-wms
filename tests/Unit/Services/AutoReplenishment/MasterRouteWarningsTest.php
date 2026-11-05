<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Contracts\OrderFileGeneratorInterface;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoReplenishment\MasterRouteWarnings;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use Illuminate\Support\Facades\DB;
use Mockery;

/** Inherits the isolated SQLite route fixtures; never touches a business database. */
class MasterRouteWarningsTest extends ReadonlyOrderRoutePageTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useGenerator(null);
    }

    private function useGenerator(?OrderFileGeneratorInterface $generator): void
    {
        $transmission = Mockery::mock(OrderTransmissionService::class);
        $transmission->shouldReceive('getGenerator')->andReturn($generator);
        $this->app->instance(OrderTransmissionService::class, $transmission);
    }

    private function route(): array
    {
        return ['item_id' => 200, 'channel' => 'LOGISTICS', 'contractor_id' => 9012,
            'supplier_id' => 8007, 'source_warehouse_id' => 91, 'delivery_course_id' => 919210];
    }

    public function test_unchanged_eos_and_logistics_routes_have_no_warning(): void
    {
        $service = new MasterRouteWarnings;
        self::assertSame([], $service->forRoute(6, 10, $this->route()));
        self::assertSame([], $service->forRoute(6, 10, ['item_id' => 100, 'channel' => 'EOS', 'contractor_id' => 1021, 'supplier_id' => 1021]));
        self::assertSame([], $service->forRoute(6, 10, []));
    }

    public function test_source_change_is_advisory_and_never_rewrites_the_saved_route(): void
    {
        $db = DB::connection('sakemaru');
        $db->table('wms_item_reorder_policies')->insert($this->route() + ['id' => 7, 'client_id' => 6, 'warehouse_id' => 10, 'is_enabled' => 0]);
        $db->table('wms_contractor_settings')->where('contractor_id', 9012)->update(['supply_warehouse_id' => 92]);
        $db->enableQueryLog();
        $page = new ReadonlyOrderRoutePageFixture;
        $page->warehouseId = 10;
        $page->editPolicy(7);
        $warnings = (new MasterRouteWarnings)->forRoute(6, 10, $page->form);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('移動元倉庫', $warnings[0]);
        $page->calculateSettings(); // Warning does not reject the operation.
        self::assertSame(91, $page->form['source_warehouse_id']);
        self::assertFalse($page->form['is_enabled']);
        self::assertSame(91, $db->table('wms_item_reorder_policies')->value('source_warehouse_id'));
        foreach ($db->getQueryLog() as $query) {
            self::assertStringStartsWith('select', strtolower($query['query']));
        }
    }

    public function test_relation_transmission_and_course_changes_are_identified(): void
    {
        $service = new MasterRouteWarnings;
        $context = $service->context(6, 10, [200]);
        self::assertStringContainsString('発注先・仕入先', $service->compare(array_replace($this->route(), ['supplier_id' => 123]), $context)[0]);
        $context['relations'][0]['transmission_type'] = 'MANUAL_CSV';
        self::assertStringContainsString('送信方式', $service->compare($this->route(), $context)[0]);
        $context['relations'][0]['transmission_type'] = 'INTERNAL';
        $context['courses'][0]['delivery_course_id'] = 999;
        self::assertStringContainsString('配送コース', $service->compare($this->route(), $context)[0]);
        $context['courses'] = [];
        self::assertStringContainsString('配送コース', $service->compare($this->route(), $context)[0]);
    }

    public function test_bulk_context_is_two_selects_and_is_scoped_to_client_warehouse_and_items(): void
    {
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        $service = new MasterRouteWarnings;
        $context = $service->context(6, 10, [100, 200]);
        for ($i = 0; $i < 100; $i++) {
            self::assertSame([], $service->compare($this->route(), $context));
        }
        self::assertCount(2, $db->getQueryLog());
        self::assertSame([], $service->context(7, 10, [200])['relations']);
        self::assertSame([], $service->context(6, 11, [200])['relations']);
        self::assertSame([], $service->context(6, 10, [999])['relations']);
    }

    public function test_recommendation_carries_same_advisory_without_changing_quantity_candidates(): void
    {
        DB::connection('sakemaru')->table('wms_contractor_settings')->where('contractor_id', 9012)->update(['supply_warehouse_id' => 92]);
        $history = Mockery::mock(SettingsRecommendationHistory::class);
        $data = ['scope' => ['channel' => 'LOGISTICS', 'warehouse_id' => 10, 'capacity_case' => 24, 'packing_unit' => 24],
            'period' => ['start' => '2026-10-01', 'end' => '2026-10-20'], 'nominal_lead_days' => 1,
            'daily' => array_combine(array_map(fn ($day) => sprintf('2026-10-%02d', $day), range(1, 20)), array_fill(0, 20, 4)),
            'receipts' => array_map(fn ($day) => ['warehouse_id' => 10, 'pieces' => 24, 'date' => sprintf('2026-10-%02d', $day), 'lead_days' => 1], [1, 8, 15])];
        $this->freezeTime();
        $history->shouldReceive('read')->once()->andReturn($data);
        $calculator = new SettingsRecommendationCalculator;
        $expected = $calculator->calculate($data);
        $result = (new SettingsRecommendationService($history, $calculator))->recommend(6, 10, $this->route());
        self::assertSame((new MasterRouteWarnings)->forRoute(6, 10, $this->route()), $result['basis']['master_warnings']);
        self::assertTrue($result['available']);
        self::assertCount(2, $result['candidates']);
        foreach ($result['candidates'] as $key => $candidate) {
            self::assertSame($result['basis']['master_warnings'], $candidate['basis']['master_warnings']);
            unset($result['candidates'][$key]['basis']['master_warnings']);
        }
        unset($result['basis']['master_warnings']);
        self::assertSame($expected, $result);
    }

    public function test_warning_view_is_nonblocking_and_escapes_display_values(): void
    {
        $view = 'filament.pages.auto-replenishment-route-warnings';
        self::assertSame('', trim(strip_tags(view($view, ['masterWarnings' => []])->render())));
        $html = view($view, ['masterWarnings' => ['移動元倉庫が異なります。<script>test</script>']])->render();
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('この警告だけで発注を停止', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<input', $html);
        self::assertStringNotContainsString('<button', $html);
    }

    public function test_master_edit_notice_counts_only_related_policies_and_includes_off(): void
    {
        $db = DB::connection('sakemaru');
        $db->table('wms_item_reorder_policies')->insert($this->route() + ['id' => 7, 'client_id' => 6, 'warehouse_id' => 10, 'is_enabled' => 0]);
        $db->table('wms_item_reorder_policies')->insert($this->route() + ['id' => 8, 'client_id' => 7, 'warehouse_id' => 10, 'is_enabled' => 1]);
        $db->enableQueryLog();
        $service = new MasterRouteWarnings;
        foreach (['item' => ['warehouse_id' => 10, 'item_id' => 200], 'contractor' => ['contractor_id' => 9012], 'delivery' => ['from_warehouse_id' => 91, 'to_warehouse_id' => 10]] as $kind => $master) {
            $notice = $service->masterEditNotice(6, $kind, $master);
            self::assertSame(1, $notice['affectedCount']);
            $html = view('filament.pages.auto-replenishment-master-edit-warning', $notice)->render();
            self::assertStringContainsString('OFFを含む', $html);
            self::assertStringContainsString('保存の制限はありません', $html);
            self::assertStringNotContainsString('<input', $html);
            self::assertStringNotContainsString('<button', $html);
        }
        self::assertSame(0, $service->masterEditNotice(6, 'item', ['warehouse_id' => 11, 'item_id' => 200])['affectedCount']);
        self::assertSame(0, $service->masterEditNotice(6, 'delivery', ['from_warehouse_id' => 92, 'to_warehouse_id' => 10])['affectedCount']);
        self::assertSame(0, $service->masterEditNotice(6, 'contractor', [])['affectedCount']);
        self::assertSame(0, $service->masterEditNotice(0, 'contractor', ['contractor_id' => 9012])['affectedCount']);
        foreach ($db->getQueryLog() as $query) {
            self::assertStringStartsWith('select', strtolower($query['query']));
        }
    }

    public function test_jx_parent_counts_off_child_policies_even_without_own_policies(): void
    {
        $generator = Mockery::mock(OrderFileGeneratorInterface::class);
        $generator->shouldReceive('getTransmissionContractorMapping')->andReturn([1021 => 1106, 1127 => 1106, 1106 => 1106, 1330 => 1202]);
        $this->useGenerator($generator);
        $db = DB::connection('sakemaru');
        foreach ([[6, 1021, 'EOS'], [6, 1127, 'EOS'], [7, 1021, 'EOS'], [6, 1021, 'LOGISTICS'], [6, 1330, 'EOS']] as $id => [$client, $contractor, $channel]) {
            $db->table('wms_item_reorder_policies')->insert(['id' => $id + 1, 'client_id' => $client, 'warehouse_id' => 10, 'item_id' => 100, 'channel' => $channel, 'contractor_id' => $contractor, 'is_enabled' => 0]);
        }
        $db->enableQueryLog();
        $service = new MasterRouteWarnings;
        $notice = $service->masterEditNotice(6, 'contractor', ['contractor_id' => 1106]);
        self::assertSame(2, $notice['affectedCount']);
        self::assertSame(0, $notice['directCount']);
        self::assertSame(2, $notice['aggregationSourceCount']);
        self::assertCount(1, $db->getQueryLog());
        self::assertStringStartsWith('select', strtolower($db->getQueryLog()[0]['query']));
        $html = view('filament.pages.auto-replenishment-master-edit-warning', $notice)->render();
        self::assertStringContainsString('JX集約元 2件', $html);
        self::assertStringContainsString('JX集約元は送信設定の影響対象です', $html);
        self::assertStringContainsString('保存の制限はありません', $html);
        $db->table('wms_item_reorder_policies')->insert(['id' => 6, 'client_id' => 6, 'warehouse_id' => 10, 'item_id' => 200, 'channel' => 'EOS', 'contractor_id' => 1106, 'is_enabled' => 0]);
        $notice = $service->masterEditNotice(6, 'contractor', ['contractor_id' => 1106]);
        self::assertSame(3, $notice['affectedCount']); // Self-mapping must not double count.
        self::assertSame(1, $notice['directCount']);
        self::assertSame(2, $notice['aggregationSourceCount']);
        self::assertSame(0, $db->table('wms_item_reorder_policies')->where('is_enabled', 1)->count());
    }

    public function test_jx_count_uses_generator_fallback_and_explicit_mapping_override(): void
    {
        $db = DB::connection('sakemaru');
        $db->statement('ALTER TABLE contractors ADD COLUMN code INTEGER');
        $db->statement('ALTER TABLE wms_contractor_settings ADD COLUMN transmission_contractor_id INTEGER');
        $db->table('contractors')->where('id', 1021)->update(['code' => 1021]);
        $db->table('contractors')->insert(['id' => 1106, 'client_id' => 6, 'code' => 1106, 'is_active' => 1]);
        $db->table('wms_item_reorder_policies')->insert(['id' => 1, 'client_id' => 6, 'warehouse_id' => 10, 'item_id' => 100, 'channel' => 'EOS', 'contractor_id' => 1021, 'is_enabled' => 0]);
        $this->useGenerator(new HanaOrderJXFileGenerator);
        $service = new MasterRouteWarnings;
        self::assertSame(1, $service->masterEditNotice(6, 'contractor', ['contractor_id' => 1106])['affectedCount']);
        $db->table('wms_contractor_settings')->where('contractor_id', 1021)->update(['transmission_contractor_id' => 1202]);
        self::assertSame(0, $service->masterEditNotice(6, 'contractor', ['contractor_id' => 1106])['affectedCount']);
        self::assertSame(1, $service->masterEditNotice(6, 'contractor', ['contractor_id' => 1202])['affectedCount']);
    }
}
