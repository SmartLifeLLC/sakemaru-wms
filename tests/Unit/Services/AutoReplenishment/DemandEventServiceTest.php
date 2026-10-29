<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Services\AutoReplenishment\DemandEventRepository;
use App\Services\AutoReplenishment\DemandEventService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemandEventServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        DemandEventRepository::resetInstalledFlag();
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, item_category3_id INTEGER)');
        $db->statement('CREATE TABLE wms_national_holidays (id INTEGER PRIMARY KEY, holiday_date TEXT, holiday_name TEXT, created_at TEXT, updated_at TEXT)');
        $db->table('items')->insert([['id' => 143024, 'client_id' => 6, 'item_category3_id' => 3142], ['id' => 999, 'client_id' => 6, 'item_category3_id' => null]]);
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_10_30_000001_create_wms_demand_events.php'))->up();
        DemandEventRepository::resetInstalledFlag();
    }

    protected function tearDown(): void
    {
        DemandEventRepository::resetInstalledFlag();
        parent::tearDown();
    }

    public function test_repository_is_inert_until_the_tables_exist(): void
    {
        self::assertNull(app(DemandEventRepository::class)->recommendationOptions(6, 143024));
        self::assertSame([], app(DemandEventRepository::class)->approvedEvents(6));
    }

    public function test_proposals_are_drafts_until_approved_and_only_approved_events_reach_the_recommendation(): void
    {
        $this->migrate();
        $service = app(DemandEventService::class);
        $inserted = $service->proposeYear(6, 2026, 1);
        self::assertGreaterThanOrEqual(4, $inserted);
        self::assertSame(0, $service->proposeYear(6, 2026, 1)); // idempotent
        $repository = app(DemandEventRepository::class);
        self::assertNull($repository->recommendationOptions(6, 143024));
        $yearEnd = WmsDemandEvent::where(['client_id' => 6, 'type' => 'YEAR_END'])->firstOrFail();
        self::assertSame('DRAFT', $yearEnd->status);
        self::assertSame('2026-12-20', $yearEnd->start_date->toDateString());
        $service->setStatus(6, (int) $yearEnd->id, 'APPROVED', 7);
        WmsDemandEventProfile::insert([
            ['client_id' => 6, 'profile_key' => 'YEAR_END', 'scope_type' => 'ITEM', 'scope_id' => 143024, 'positions' => json_encode(['12-31' => ['factor' => 2.5, 'factor_weekday' => 2.4, 'years' => 3]]), 'pieces' => 5000, 'instance_count' => 3, 'trained_through' => '2026-10-29', 'model_version' => 'event-factors-v1'],
            ['client_id' => 6, 'profile_key' => 'YEAR_END', 'scope_type' => 'CATEGORY', 'scope_id' => 3142, 'positions' => json_encode(['12-31' => ['factor' => 2.0, 'factor_weekday' => 2.0, 'years' => 3]]), 'pieces' => 9000, 'instance_count' => 3, 'trained_through' => '2026-10-29', 'model_version' => 'event-factors-v1'],
            ['client_id' => 6, 'profile_key' => 'OBON', 'scope_type' => 'ITEM', 'scope_id' => 143024, 'positions' => json_encode(['08-13' => ['factor' => 1.5]]), 'pieces' => 100, 'instance_count' => 3, 'trained_through' => '2026-10-29', 'model_version' => 'event-factors-v1'],
        ]);
        $options = (new DemandEventRepository)->recommendationOptions(6, 143024);
        self::assertCount(1, $options['events']);
        self::assertSame(['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-05'], array_intersect_key($options['events'][0], array_flip(['type', 'start', 'end'])));
        self::assertSame(2.5, $options['factors']['item']['YEAR_END']['positions']['12-31']['factor']);
        self::assertEquals(2.0, $options['factors']['category']['YEAR_END']['positions']['12-31']['factor']);
        self::assertSame(5000.0, $options['factors']['item']['YEAR_END']['pieces']);
        // A product without a category only gets its own profiles; the repository never guesses a category.
        $other = (new DemandEventRepository)->recommendationOptions(6, 999);
        self::assertSame([], $other['factors']['category']);
        self::assertSame([], $other['factors']['item']);
        // Editing an approved event's dates sends it back to DRAFT.
        $service->save(6, ['type' => 'YEAR_END', 'name' => '年末年始', 'start_date' => '2026-12-19', 'end_date' => '2027-01-05'], 7, (int) $yearEnd->id);
        self::assertSame('DRAFT', $yearEnd->fresh()->status);
        self::assertNull((new DemandEventRepository)->recommendationOptions(6, 143024));
        $service->setStatus(6, (int) $yearEnd->id, 'DISABLED', 7);
        self::assertSame('DISABLED', $yearEnd->fresh()->status);
    }

    public function test_custom_events_are_validated_and_unique_per_type_and_start(): void
    {
        $this->migrate();
        $service = app(DemandEventService::class);
        $event = $service->save(6, ['type' => 'CUSTOM', 'name' => '値上げ前', 'start_date' => '2026-09-28', 'end_date' => '2026-09-30', 'pre_days' => 0, 'post_days' => 2], 7);
        self::assertSame('DRAFT', $event->status);
        self::assertSame(2026, $event->year);
        self::assertSame(2, $event->post_days);
        try {
            $service->save(6, ['type' => 'CUSTOM', 'name' => '重複', 'start_date' => '2026-09-28', 'end_date' => '2026-09-29'], 7);
            self::fail('duplicate must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('既にあります', $e->getMessage());
        }
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->save(6, ['type' => 'CUSTOM', 'name' => 'x', 'start_date' => '2026-10-02', 'end_date' => '2026-10-01'], 7);
    }
}
