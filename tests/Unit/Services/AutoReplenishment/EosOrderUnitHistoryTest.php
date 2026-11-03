<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\Sakemaru\Item;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoReplenishment\EosOrderUnitHistory;
use App\Services\AutoReplenishment\OrderUnitService;
use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EosOrderUnitHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        CarbonImmutable::setTestNow('2026-11-04 02:00:00');
        $db = DB::connection('sakemaru');
        $tables = [
            'items' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,capacity_case INTEGER DEFAULT 24,capacity_carton INTEGER DEFAULT 6',
            'warehouses' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1',
            'client_settings' => 'client_id INTEGER,system_date TEXT',
            'item_quantity_information' => 'item_id INTEGER,quantity INTEGER,can_order INTEGER',
            'wms_order_candidates' => "id INTEGER PRIMARY KEY,warehouse_id INTEGER DEFAULT 8,item_id INTEGER DEFAULT 211264,contractor_id INTEGER DEFAULT 1106,ordering_code TEXT DEFAULT '4514603325812',status TEXT DEFAULT 'EXECUTED',order_channel TEXT DEFAULT 'EOS',order_quantity INTEGER DEFAULT 4,quantity_type TEXT DEFAULT 'CASE',wms_order_jx_document_id INTEGER",
            'wms_order_jx_documents' => "id INTEGER PRIMARY KEY,contractor_id INTEGER DEFAULT 1106,status TEXT DEFAULT 'TRANSMITTED',order_date TEXT DEFAULT '2026-11-03',transmitted_at TEXT DEFAULT '2026-11-03 13:40:00'",
        ];
        foreach ($tables as $table => $columns) {
            $db->statement("CREATE TABLE $table ($columns)");
        }
        $db->table('items')->insert([['id' => 211264, 'client_id' => 6], ['id' => 211265, 'client_id' => 6], ['id' => 211266, 'client_id' => 7]]);
        $db->table('warehouses')->insert([['id' => 8, 'client_id' => 6], ['id' => 10, 'client_id' => 6], ['id' => 11, 'client_id' => 7]]);
        $db->table('client_settings')->insert(['client_id' => 6, 'system_date' => '2026-11-04']);
        $db->table('item_quantity_information')->insert(['item_id' => 211264, 'quantity' => 1, 'can_order' => 1]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function order(int $id, array $line = [], array $doc = []): void
    {
        $db = DB::connection('sakemaru');
        $db->table('wms_order_jx_documents')->insert($doc + ['id' => $id]);
        $db->table('wms_order_candidates')->insert($line + ['id' => $id, 'wms_order_jx_document_id' => $id]);
    }

    private function history(?string $date = null): array
    {
        return (new EosOrderUnitHistory)->read(6, 8, 211264, 1106, '4514603325812', $date);
    }

    public function test_only_sent_matching_store_item_contractor_code_orders_count_and_piece_multiples_are_not_cases(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->order($i);
        }
        $this->order(5, ['quantity_type' => 'PIECE', 'order_quantity' => 24, 'order_channel' => null]);
        $this->order(6, ['warehouse_id' => 10]);
        $this->order(7, ['item_id' => 211265]);
        $this->order(8, ['contractor_id' => 1202], ['contractor_id' => 1202]);
        $this->order(9, ['ordering_code' => '4514603325813']);
        $this->order(10, ['status' => 'EXCLUDED']);
        $this->order(11, ['status' => 'PENDING']);
        $this->order(12, doc: ['status' => 'PENDING', 'transmitted_at' => null]);
        $this->order(13, doc: ['status' => 'CANCELLED']);
        $this->order(14, doc: ['status' => 'FAILED']);
        $this->order(15, ['order_quantity' => 0]);
        $this->order(16, ['order_channel' => 'FAX']);
        $this->order(17, doc: ['contractor_id' => 1202]);
        $this->order(18, doc: ['status' => 'TEST']);
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        $h = $this->history('2026-11-04');
        self::assertSame(4, $h['case_count']);
        self::assertSame(1, $h['piece_count']);
        self::assertSame('CASE', $h['preferred_type']);
        self::assertSame(['start' => '2026-08-06', 'end' => '2026-11-03'], $h['period']);
        self::assertCount(1, $db->getQueryLog());
        self::assertStringStartsWith('select', $db->getQueryLog()[0]['query']);
    }

    public function test_confirmed_sent_documents_are_eligible_but_a_missing_send_timestamp_is_not(): void
    {
        $this->order(1, doc: ['status' => 'CONFIRMED']);
        $this->order(2, doc: ['status' => 'CONFIRMED', 'transmitted_at' => null]);
        self::assertSame(1, $this->history()['count_orders']);
    }

    public function test_replay_excludes_future_transmissions_and_window_edges_and_tenant_mismatches(): void
    {
        $this->order(1, doc: ['order_date' => '2026-08-06']);
        $this->order(2, doc: ['order_date' => '2026-08-05']);
        $this->order(3, doc: ['order_date' => '2026-11-04']);
        $this->order(4, doc: ['transmitted_at' => '2026-11-04 00:00:00']);
        $this->order(5, doc: ['transmitted_at' => null]);
        self::assertSame(1, $this->history()['count_orders']);
        self::assertSame(0, $this->history('2026-11-03')['count_orders']);
        // Changing the business date also excludes the not-yet-sent history, without retaining a stale cache.
        DB::connection('sakemaru')->table('client_settings')->update(['system_date' => '2026-11-03']);
        self::assertSame(0, $this->history()['count_orders']);
        DB::connection('sakemaru')->table('items')->where('id', 211264)->update(['client_id' => 7]);
        self::assertSame(0, $this->history('2026-11-04')['count_orders']);
        DB::connection('sakemaru')->table('items')->where('id', 211264)->update(['client_id' => 6]);
        DB::connection('sakemaru')->table('warehouses')->where('id', 8)->update(['client_id' => 7]);
        self::assertSame(0, $this->history('2026-11-04')['count_orders']);
    }

    public function test_thresholds_and_explicit_modes_and_master_restrictions(): void
    {
        $history = new EosOrderUnitHistory;
        $units = new OrderUnitService;
        foreach ([[4, 0, null], [4, 1, 'CASE'], [1, 4, 'PIECE'], [7, 2, null], [0, 0, null]] as [$cases, $pieces, $type]) {
            $h = $history->summarize($cases, $pieces) + ['source' => 'eos_transmitted_orders'];
            self::assertSame($type, $h['preferred_type']);
            self::assertSame($type ?? 'PIECE', $units->resolve(6, 211264, 24, history: $h)['quantity_type']);
            self::assertSame('CASE', $units->resolve(6, 211264, 24, mode: 'CASE', history: $h)['quantity_type']);
            self::assertSame('PIECE', $units->resolve(6, 211264, 24, mode: 'PIECE', history: $h)['quantity_type']);
            self::assertSame('CASE', $units->resolve(6, 211264, 24, caseOutput: true, history: $h)['quantity_type']);
            self::assertSame('CASE', $units->resolve(6, 211264, 24, rows: [211264 => [24]], history: $h)['quantity_type']);
        }
    }

    public function test_editor_and_recommendation_use_identical_scope_and_retain_explicit_piece(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->order($i);
        }
        $search = Mockery::mock(OrderRegistrationSearchService::class);
        $search->shouldReceive('orderingCodeForItem')->with(211264)->andReturn('4514603325812');
        $this->app->instance(OrderRegistrationSearchService::class, $search);
        $resolver = Mockery::mock(OrderOutputQuantityResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['ordering_unit_quantity' => null]);
        $this->app->instance(OrderOutputQuantityResolver::class, $resolver);
        $item = Item::findOrFail(211264);
        $units = new OrderUnitService;
        $editor = $units->forEditor(6, (object) $item->getAttributes(), 'EOS', warehouse: 8, contractor: 1106);
        $recommend = (new SettingsRecommendationHistory)->orderUnit(6, $item, 'EOS', 0, 'AUTO', '2026-11-04', 8, 1106);
        self::assertSame($editor, $recommend);
        self::assertSame(24, $recommend['packing_unit']);
        self::assertSame('PIECE', $units->forEditor(6, (object) $item->getAttributes(), 'EOS', 'PIECE', warehouse: 8, contractor: 1106)['quantity_type']);
        self::assertSame('PIECE', $units->forEditor(6, (object) $item->getAttributes(), 'EOS', warehouse: 10, contractor: 1106)['quantity_type']);
    }
}
