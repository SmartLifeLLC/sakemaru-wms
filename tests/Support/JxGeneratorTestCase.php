<?php

namespace Tests\Support;

use App\Models\Sakemaru\ClientSetting;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Generator format tests use only synthetic data in a fresh SQLite memory DB. */
abstract class JxGeneratorTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::prepareFixtureDatabase();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        ClientSetting::clearCachedSetting();
        DB::purge('sakemaru');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function prepareFixtureDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('JX fixtures require the testing environment.');
        }

        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('JX fixtures must use an isolated memory database.');
        }

        // These emulate only the scalar functions used by read-only generator queries.
        // They do not claim to cover MySQL locking or query-plan behavior.
        $db->getPdo()->sqliteCreateFunction('regexp', static fn ($pattern, $value) => preg_match('~'.$pattern.'~', (string) $value), 2);
        $db->getPdo()->sqliteCreateFunction('lpad', static fn ($value, $length, $pad) => str_pad((string) $value, (int) $length, $pad, STR_PAD_LEFT), 3);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:15:00'));
        ClientSetting::clearCachedSetting();

        $tables = [
            'contractors' => ['code', 'name', 'is_active'],
            'warehouses' => ['code', 'name', 'kana_name', 'is_active'],
            'items' => ['code', 'name_main', 'capacity_case', 'purchase_price_type', 'is_active'],
            'item_prices' => ['item_id', 'is_active', 'start_date', 'cost_unit_price', 'cost_case_price', 'producer_unit_price', 'wholesale_unit_price', 'purchase_unit_price'],
            'item_quantity_information' => ['item_id', 'quantity'],
            'item_search_information' => ['item_id', 'item_quantity_information_id', 'search_string', 'is_used_for_ordering', 'is_active', 'quantity_type', 'code_type'],
            'wms_contractor_settings' => ['contractor_id', 'transmission_contractor_id', 'wms_order_jx_setting_id'],
            'wms_order_jx_settings' => ['contractor_id', 'is_active', 'send_document_type', 'sender_station_code', 'receiver_station_code', 'sender_trading_code', 'receiver_trading_code', 'sender_name', 'sender_office_name'],
            'wms_order_candidates' => ['item_id', 'contractor_id', 'warehouse_id', 'order_quantity', 'quantity_type', 'ordering_code', 'expected_arrival_date', 'purchase_unit_price'],
            'wms_order_incoming_schedules' => ['order_candidate_id', 'slip_number'],
        ];
        $integers = ['item_id', 'contractor_id', 'warehouse_id', 'transmission_contractor_id', 'wms_order_jx_setting_id', 'item_quantity_information_id', 'capacity_case', 'quantity', 'order_quantity', 'order_candidate_id', 'is_active', 'is_used_for_ordering'];
        foreach ($tables as $name => $columns) {
            $db->getSchemaBuilder()->create($name, function (Blueprint $table) use ($columns, $integers): void {
                $table->increments('id');
                foreach ($columns as $column) {
                    if (in_array($column, $integers, true)) {
                        $table->integer($column)->nullable();
                    } elseif (str_ends_with($column, '_price')) {
                        $table->decimal($column, 12, 4)->nullable();
                    } else {
                        $table->string($column)->nullable();
                    }
                }
            });
        }

        $db->table('warehouses')->insert(['id' => 91, 'code' => '91', 'name' => 'テスト倉庫', 'kana_name' => 'ﾃｽﾄｿｳｺ', 'is_active' => 1]);
        foreach ([1106, 1017, 1202, 1330] as $code) {
            $db->table('wms_order_jx_settings')->insert([
                'id' => $code, 'contractor_id' => $code, 'is_active' => 1,
                'send_document_type' => '91', 'sender_station_code' => '01451019',
                'receiver_station_code' => (string) $code, 'sender_trading_code' => '012345678901',
                'receiver_trading_code' => str_pad((string) $code, 12, '0', STR_PAD_LEFT),
                'sender_name' => 'TEST SENDER', 'sender_office_name' => 'TEST',
            ]);
        }
        foreach ([1106, 1017, 1202, 1330, 1021, 1029, 1068, 1126, 1127] as $index => $code) {
            $itemId = 143000 + $index;
            $orderingCode = '4900000'.str_pad((string) $itemId, 6, '0', STR_PAD_LEFT);
            $db->table('contractors')->insert(['id' => $code, 'code' => (string) $code, 'name' => "テスト発注先{$code}", 'is_active' => 1]);
            $db->table('items')->insert(['id' => $itemId, 'code' => (string) $itemId, 'name_main' => "テスト商品{$index}", 'capacity_case' => 24, 'purchase_price_type' => 'COST', 'is_active' => 1]);
            $db->table('item_prices')->insert(['item_id' => $itemId, 'is_active' => 1, 'start_date' => '2020-01-01', 'cost_unit_price' => 100, 'cost_case_price' => 2400, 'producer_unit_price' => 100, 'wholesale_unit_price' => 100, 'purchase_unit_price' => 100]);
            $quantityId = $db->table('item_quantity_information')->insertGetId(['item_id' => $itemId, 'quantity' => 1]);
            $db->table('item_search_information')->insert(['item_id' => $itemId, 'item_quantity_information_id' => $quantityId, 'search_string' => $orderingCode, 'is_used_for_ordering' => 1, 'is_active' => 1, 'quantity_type' => 'PIECE', 'code_type' => 'JAN']);
            // A second valid code proves that the ordering flag, rather than row presence, is used.
            $db->table('item_search_information')->insert(['item_id' => $itemId, 'item_quantity_information_id' => $quantityId, 'search_string' => '4909999'.str_pad((string) $itemId, 6, '0', STR_PAD_LEFT), 'is_used_for_ordering' => 0, 'is_active' => 1, 'quantity_type' => 'PIECE', 'code_type' => 'JAN']);
            $db->table('wms_order_candidates')->insert(['item_id' => $itemId, 'contractor_id' => $code, 'warehouse_id' => 91, 'order_quantity' => $index % 2 === 0 ? 2 : 7, 'quantity_type' => $index % 2 === 0 ? 'CASE' : 'PIECE', 'ordering_code' => $orderingCode, 'expected_arrival_date' => '2026-08-24', 'purchase_unit_price' => 100]);
        }
    }
}
