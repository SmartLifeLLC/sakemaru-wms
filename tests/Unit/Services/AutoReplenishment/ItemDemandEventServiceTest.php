<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsDemandEventProfile;
use App\Models\WmsItemDemandEvent;
use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\DemandEventRepository;
use App\Services\AutoReplenishment\ItemDemandEventService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Which standard events apply to which product: matrix, suggestions from learned factors, saving, command. */
class ItemDemandEventServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.client_id' => 6, 'wms.client' => 'hana']);
        DB::purge('sakemaru');
        DemandEventRepository::resetInstalledFlag();
        $db = DB::connection('sakemaru');
        (require base_path('database/migrations/2026_10_30_000001_create_wms_demand_events.php'))->up();
        DemandEventRepository::resetInstalledFlag();
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, item_category3_id INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, channel TEXT)');
        $db->table('items')->insert([
            ['id' => 143024, 'client_id' => 6, 'code' => 143024, 'name' => 'スーパードライ350', 'item_category3_id' => 3142],
            ['id' => 200, 'client_id' => 6, 'code' => 20000, 'name' => '日本酒 一升', 'item_category3_id' => 3142], // code ≠ id: the command resolves 商品CD
            ['id' => 300, 'client_id' => 6, 'code' => 300, 'name' => '水', 'item_category3_id' => null],
        ]);
        $db->table('wms_item_reorder_policies')->insert([
            ['id' => 1, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 143024, 'channel' => 'EOS'], ['id' => 2, 'client_id' => 6, 'warehouse_id' => 3, 'item_id' => 143024, 'channel' => 'EOS'],
            ['id' => 3, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 200, 'channel' => 'EOS'], ['id' => 4, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 300, 'channel' => 'EOS'],
        ]);
        $profile = fn (string $key, string $scope, int $id, array $factors, int $instances, float $pieces = 500) => ['client_id' => 6, 'profile_key' => $key, 'scope_type' => $scope, 'scope_id' => $id,
            'positions' => json_encode(array_map(fn ($f) => ['factor' => $f, 'factor_weekday' => $f, 'years' => $instances], $factors)), 'pieces' => $pieces, 'instance_count' => $instances,
            'trained_through' => '2026-10-29', 'model_version' => \App\Services\AutoReplenishment\DemandEventLearner::MODEL_VERSION];
        WmsDemandEventProfile::insert([
            $profile('YEAR_END', 'ITEM', 143024, ['12-28' => 2.0, '12-30' => 3.0, '01-02' => 0.4], 3), // block mean 1.8 → suggested
            $profile('OBON', 'ITEM', 143024, ['08-13' => 1.1, '08-14' => 1.2], 3),                      // mean 1.15 → not suggested
            $profile('GW', 'ITEM', 143024, ['pre3' => 1.5, 'd1' => 1.6, 'd2' => 1.4, 'post1' => 0.8], 1), // mean 1.5 but one year → not suggested
            $profile('YEAR_END', 'ITEM', 200, ['12-30' => 1.2], 3),
            $profile('YEAR_END', 'CATEGORY', 3142, ['12-30' => 1.9], 3),                                 // category: reference only
        ]);
    }

    protected function tearDown(): void
    {
        DemandEventRepository::resetInstalledFlag();
        parent::tearDown();
    }

    public function test_matrix_suggestions_and_apply(): void
    {
        $service = app(ItemDemandEventService::class);
        $matrix = $service->matrix(6);
        self::assertSame([143024, 200, 300], array_keys($matrix));
        self::assertSame(['YEAR_END' => false, 'OBON' => false, 'GW' => false], $matrix[143024]);
        $suggestions = $service->suggestions(6, [143024, 200, 300]);
        self::assertTrue($suggestions[143024]['YEAR_END']['suggest']);
        self::assertSame(1.8, $suggestions[143024]['YEAR_END']['mean']);
        self::assertSame(3.0, $suggestions[143024]['YEAR_END']['peak']);
        self::assertFalse($suggestions[143024]['OBON']['suggest']);
        self::assertFalse($suggestions[143024]['GW']['suggest']); // one instance only
        self::assertSame(1.5, $suggestions[143024]['GW']['mean']); // pre/post positions are not part of the block mean
        self::assertFalse($suggestions[200]['YEAR_END']['suggest']); // own mean 1.2 even though the category says 1.9
        self::assertSame(1.9, $suggestions[200]['YEAR_END']['category_mean']);
        self::assertNull($suggestions[300]['YEAR_END']['mean']);
        self::assertSame(0, $suggestions[300]['YEAR_END']['instances']);
        // Apply: rows per type, disabled rows kept for the audit trail.
        $counts = $service->apply(6, [143024 => ['YEAR_END', 'GW'], 200 => []], 7);
        self::assertSame(['enabled' => 2, 'disabled' => 0, 'unchanged' => 4], $counts);
        self::assertEqualsCanonicalizing(['YEAR_END', 'GW'], (new DemandEventRepository)->itemEventTypes(6, 143024));
        $counts = $service->apply(6, [143024 => ['YEAR_END']], 7);
        self::assertSame(['enabled' => 0, 'disabled' => 1, 'unchanged' => 2], $counts);
        self::assertSame(['YEAR_END'], (new DemandEventRepository)->itemEventTypes(6, 143024));
        self::assertFalse(WmsItemDemandEvent::where(['item_id' => 143024, 'event_type' => 'GW'])->firstOrFail()->is_enabled);
        self::assertSame(7, (int) WmsItemDemandEvent::where(['item_id' => 143024, 'event_type' => 'GW'])->value('updated_by'));
        try {
            $service->apply(6, [143024 => ['LONG_WEEKEND']], 7);
            self::fail('only standard events can be applied');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('標準イベントのみ', $e->getMessage());
        }
    }

    public function test_command_previews_and_applies_from_learned_factors_or_explicit_options(): void
    {
        $code = Artisan::call('wms:set-item-demand-events', ['client' => 6, '--from-learned' => true]);
        $output = Artisan::output();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('143024', $output);
        self::assertStringContainsString('--apply', $output);
        self::assertSame(0, WmsItemDemandEvent::count());
        // Candidates are a hint, not the verified list: registering them needs an explicit acceptance.
        self::assertSame(1, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--from-learned' => true, '--apply' => true]));
        self::assertStringContainsString('--accept-candidates', Artisan::output());
        self::assertSame(0, WmsItemDemandEvent::count());
        self::assertSame(0, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--from-learned' => true, '--apply' => true, '--accept-candidates' => true]));
        self::assertSame(['YEAR_END'], (new DemandEventRepository)->itemEventTypes(6, 143024));
        self::assertSame([], (new DemandEventRepository)->itemEventTypes(6, 200));
        // Explicit: --item takes 商品CD by default (20000 → items.id 200) and replaces the product's set.
        self::assertSame(0, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--item' => ['20000'], '--events' => 'YEAR_END,OBON', '--apply' => true]), Artisan::output());
        self::assertEqualsCanonicalizing(['YEAR_END', 'OBON'], (new DemandEventRepository)->itemEventTypes(6, 200));
        self::assertSame(1, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--item' => ['200'], '--events' => '', '--apply' => true])); // 200 is not a 商品CD
        self::assertStringContainsString('商品CD 200 が見つかりません', Artisan::output());
        self::assertSame(0, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--item' => ['200'], '--key' => 'id', '--events' => '', '--apply' => true]), Artisan::output());
        self::assertSame([], (new DemandEventRepository)->itemEventTypes(6, 200));
        // Unknown product or non-standard type fails without writing.
        self::assertSame(1, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--item' => ['999'], '--key' => 'id', '--events' => 'YEAR_END', '--apply' => true]));
        self::assertStringContainsString('items.id が見つかりません', Artisan::output());
        self::assertSame(1, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--item' => ['20000'], '--events' => 'LONG_WEEKEND', '--apply' => true]));
        // CSV by 商品CD (header and comment lines skipped).
        $csv = tempnam(sys_get_temp_dir(), 'ev');
        file_put_contents($csv, "item_code,events\n# 缶ビール\n300,GW|OBON\n20000,\n");
        self::assertSame(0, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--csv' => $csv, '--apply' => true]), Artisan::output());
        self::assertEqualsCanonicalizing(['GW', 'OBON'], (new DemandEventRepository)->itemEventTypes(6, 300));
        // Export → review → register: the CSV the command writes is accepted by --csv as is.
        $export = tempnam(sys_get_temp_dir(), 'ex');
        self::assertSame(0, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--export' => $export]), Artisan::output());
        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim(file_get_contents($export), "\xEF\xBB\xBF"))));
        self::assertSame(['item_code', 'events', 'item_name', 'current', 'year_end_mean', 'year_end_years', 'obon_mean', 'obon_years', 'gw_mean', 'gw_years', 'suggested'], $lines[0]);
        $byCode = collect(array_slice($lines, 1))->keyBy(0);
        self::assertSame(['YEAR_END', 'YEAR_END', '1.8', '3'], [$byCode['143024'][1], $byCode['143024'][10], $byCode['143024'][4], $byCode['143024'][5]]); // current application wins, suggestion shown
        self::assertSame(['OBON|GW', 'OBON|GW', ''], [$byCode['300'][1], $byCode['300'][3], $byCode['300'][10]]); // standard order
        self::assertSame(['', ''], [$byCode['20000'][1], $byCode['20000'][10]]); // own mean 1.2 → no suggestion
        self::assertSame(0, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--csv' => $export, '--apply' => true]), Artisan::output());
        self::assertSame(['YEAR_END'], (new DemandEventRepository)->itemEventTypes(6, 143024));
        unlink($export);
        // An ambiguous 商品CD is refused.
        DB::connection('sakemaru')->table('items')->insert(['id' => 301, 'client_id' => 6, 'code' => 300, 'name' => '水（別ID）', 'item_category3_id' => null]);
        self::assertSame(1, Artisan::call('wms:set-item-demand-events', ['client' => 6, '--csv' => $csv, '--apply' => true]));
        self::assertStringContainsString('複数の商品に一致', Artisan::output());
        unlink($csv);
    }

    public function test_display_segments_follow_the_fixed_definitions(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $yearEnd = $calendar->segments(['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-07']);
        self::assertSame([['前半', '2026-12-20', '2026-12-26'], ['ピーク', '2026-12-27', '2026-12-31'], ['年始', '2027-01-01', '2027-01-07']], array_map(fn ($s) => [$s['label'], $s['start'], $s['end']], $yearEnd));
        $obon = $calendar->segments(['type' => 'OBON', 'start' => '2026-08-03', 'end' => '2026-08-17']);
        self::assertSame([['前半', '2026-08-03', '2026-08-09'], ['本体', '2026-08-10', '2026-08-15'], ['後半', '2026-08-16', '2026-08-17']], array_map(fn ($s) => [$s['label'], $s['start'], $s['end']], $obon));
        $gw = $calendar->segments(['type' => 'GW', 'start' => '2026-05-02', 'end' => '2026-05-06']);
        self::assertSame([['前半', '2026-04-22', '2026-05-01'], ['本体', '2026-05-02', '2026-05-06'], ['後半', '2026-05-07', '2026-05-08']], array_map(fn ($s) => [$s['label'], $s['start'], $s['end']], $gw));
        $custom = $calendar->segments(['type' => 'CUSTOM', 'start' => '2026-09-28', 'end' => '2026-09-30', 'pre_days' => 0, 'post_days' => 0]);
        self::assertSame([['本体', '2026-09-28', '2026-09-30']], array_map(fn ($s) => [$s['label'], $s['start'], $s['end']], $custom));
    }
}
