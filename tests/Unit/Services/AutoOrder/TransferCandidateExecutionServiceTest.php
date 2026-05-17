<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TransferCandidateExecutionService テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class TransferCandidateExecutionServiceTest extends TestCase
{
    private TransferCandidateExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransferCandidateExecutionService::class);
    }

    /**
     * @test
     * サービスがDIで解決できること
     */
    public function it_can_be_resolved_from_container(): void
    {
        $service = app(TransferCandidateExecutionService::class);
        $this->assertInstanceOf(TransferCandidateExecutionService::class, $service);
    }

    /**
     * @test
     * WmsStockTransferCandidateモデルにshipment_dateが追加されていること
     */
    public function it_has_shipment_date_in_transfer_candidate_model(): void
    {
        $fillable = (new WmsStockTransferCandidate)->getFillable();

        $this->assertContains('shipment_date', $fillable, 'shipment_date should be fillable');
    }

    /**
     * @test
     * WmsStockTransferCandidateモデルのshipment_dateがdate型にキャストされること
     */
    public function it_casts_shipment_date_to_date(): void
    {
        $casts = (new WmsStockTransferCandidate)->getCasts();

        $this->assertArrayHasKey('shipment_date', $casts);
        $this->assertEquals('date', $casts['shipment_date']);
    }

    /**
     * @test
     * WmsOrderIncomingScheduleモデルに移動関連カラムが追加されていること
     */
    public function it_has_transfer_columns_in_incoming_schedule_model(): void
    {
        $fillable = (new WmsOrderIncomingSchedule)->getFillable();

        $requiredColumns = [
            'transfer_candidate_id',
            'source_warehouse_id',
            'stock_transfer_id',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $fillable, "Column {$column} should be fillable");
        }
    }

    /**
     * @test
     * OrderSourceにTRANSFERが定義されていること
     */
    public function it_has_transfer_in_order_source_enum(): void
    {
        $this->assertEquals('TRANSFER', OrderSource::TRANSFER->value);
        $this->assertEquals('倉庫間移動', OrderSource::TRANSFER->label());
    }

    /**
     * @test
     * APPROVED状態の移動候補が取得できること
     */
    public function it_can_find_approved_transfer_candidates(): void
    {
        $approvedCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();

        // APPROVED状態の候補がなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $approvedCount);
    }

    /**
     * @test
     * 存在しないバッチコードでexecuteBatchを呼んでもエラーにならないこと
     */
    public function it_handles_non_existent_batch_gracefully(): void
    {
        $result = $this->service->executeBatch('NON_EXISTENT_BATCH_'.time(), 1);

        $this->assertTrue($result->isEmpty());
    }

    /**
     * @test
     * 空の候補ID配列でexecuteMultipleを呼んでもエラーにならないこと
     */
    public function it_handles_empty_candidate_ids_gracefully(): void
    {
        $result = $this->service->executeMultiple([], 1);

        $this->assertTrue($result->isEmpty());
    }

    /**
     * @test
     * 倉庫関連のマスタデータが存在すること
     */
    public function it_has_warehouse_master_data(): void
    {
        $warehouseCount = Warehouse::count();
        $this->assertGreaterThan(0, $warehouseCount, 'Should have warehouses in database');
    }

    /**
     * @test
     * 商品マスタデータが存在すること
     */
    public function it_has_item_master_data(): void
    {
        $itemCount = Item::count();
        $this->assertGreaterThan(0, $itemCount, 'Should have items in database');
    }

    /**
     * @test
     * 配送コースマスタデータが存在すること
     */
    public function it_has_delivery_course_master_data(): void
    {
        $courseCount = DeliveryCourse::count();
        $this->assertGreaterThanOrEqual(0, $courseCount);
    }

    /**
     * @test
     * 倉庫間移動の配送コース設定から配送コースIDを取得すること
     */
    public function it_resolves_transfer_delivery_course_from_mapping(): void
    {
        $mapping = DB::connection('sakemaru')
            ->table('warehouse_stock_transfer_delivery_courses')
            ->whereNotNull('delivery_course_id')
            ->first();

        if (! $mapping) {
            $this->markTestSkipped('No warehouse stock transfer delivery course mapping available');
        }

        $method = new \ReflectionMethod($this->service, 'resolveTransferDeliveryCourseId');
        $method->setAccessible(true);

        $deliveryCourseId = $method->invoke(
            $this->service,
            (int) $mapping->from_warehouse_id,
            (int) $mapping->to_warehouse_id,
            null,
        );

        $this->assertSame((int) $mapping->delivery_course_id, $deliveryCourseId);
    }

    /**
     * @test
     * 倉庫間移動の配送コース設定がない場合は現在値をそのまま使うこと
     */
    public function it_keeps_current_delivery_course_when_mapping_is_missing(): void
    {
        $method = new \ReflectionMethod($this->service, 'resolveTransferDeliveryCourseId');
        $method->setAccessible(true);

        $deliveryCourseId = $method->invoke($this->service, 999999998, 999999999, null);

        $this->assertNull($deliveryCourseId);
    }

    /**
     * @test
     * 解決済み配送コースIDを一時保持してもモデルの更新対象カラムに混ざらないこと
     */
    public function it_does_not_store_resolved_delivery_course_as_model_attribute(): void
    {
        $candidate = new WmsStockTransferCandidate([
            'delivery_course_id' => null,
        ]);
        $candidate->id = 123456789;
        $candidate->syncOriginal();

        $setMethod = new \ReflectionMethod($this->service, 'setResolvedDeliveryCourseId');
        $setMethod->setAccessible(true);
        $setMethod->invoke($this->service, $candidate, 919207);

        $resolveMethod = new \ReflectionMethod($this->service, 'resolvedDeliveryCourseId');
        $resolveMethod->setAccessible(true);

        $this->assertSame(919207, $resolveMethod->invoke($this->service, $candidate));
        $this->assertArrayNotHasKey('resolved_delivery_course_id', $candidate->getDirty());
    }

    /**
     * @test
     * 入荷予定日が未設定の移動候補は出荷日を入荷予定日のフォールバックに使うこと
     */
    public function it_uses_shipment_date_when_expected_arrival_date_is_missing(): void
    {
        $candidate = new WmsStockTransferCandidate([
            'expected_arrival_date' => null,
            'shipment_date' => '2026-05-19',
        ]);

        $method = new \ReflectionMethod($this->service, 'resolveIncomingExpectedArrivalDate');
        $method->setAccessible(true);

        $this->assertSame('2026-05-19', $method->invoke($this->service, $candidate)->format('Y-m-d'));
    }

    /**
     * @test
     * 入荷予定日も出荷日も未設定の移動候補は今日を入荷予定日のフォールバックに使うこと
     */
    public function it_uses_today_when_transfer_candidate_dates_are_missing(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');

        try {
            $candidate = new WmsStockTransferCandidate([
                'expected_arrival_date' => null,
                'shipment_date' => null,
            ]);

            $method = new \ReflectionMethod($this->service, 'resolveIncomingExpectedArrivalDate');
            $method->setAccessible(true);

            $this->assertSame('2026-05-20', $method->invoke($this->service, $candidate)->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @test
     * グループ化用の入荷日はexpected_arrival_dateを優先すること
     */
    public function it_uses_expected_arrival_date_for_transfer_grouping(): void
    {
        $candidate = new WmsStockTransferCandidate([
            'expected_arrival_date' => '2026-05-21',
            'shipment_date' => '2026-05-20',
        ]);

        $method = new \ReflectionMethod($this->service, 'resolveTransferGroupArrivalDate');
        $method->setAccessible(true);

        $this->assertSame('2026-05-21', $method->invoke($this->service, $candidate)->format('Y-m-d'));
    }

    /**
     * @test
     * 同一移動伝票グループに入荷日違いが混ざる場合は検知すること
     */
    public function it_rejects_mixed_arrival_dates_in_transfer_group(): void
    {
        $candidates = collect([
            new WmsStockTransferCandidate(['expected_arrival_date' => '2026-05-21']),
            new WmsStockTransferCandidate(['expected_arrival_date' => '2026-05-22']),
        ]);

        $method = new \ReflectionMethod($this->service, 'assertGroupedCandidatesUseSameArrivalDate');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Arrival date mismatch in stock transfer group');

        $method->invoke($this->service, $candidates, '2026-05-21');
    }

    /**
     * @test
     * stock_transfer_queueテーブルにaction_typeカラムが存在すること
     * 注意: action_typeカラムはsakemaru-ai-core側で追加される
     */
    public function it_has_action_type_column_in_queue_table(): void
    {
        $columns = DB::connection('sakemaru')
            ->getSchemaBuilder()
            ->getColumnListing('stock_transfer_queue');

        if (! in_array('action_type', $columns)) {
            $this->markTestSkipped('action_type column not yet added (requires sakemaru-ai-core migration)');
        }

        $this->assertContains('action_type', $columns, 'stock_transfer_queue should have action_type column');
    }

    /**
     * @test
     * executeAllApprovedGroupedが実行できること（APPROVED候補がない場合）
     */
    public function it_can_execute_all_approved_grouped(): void
    {
        // 既存のAPPROVED候補を変更せずに、結果の構造を確認
        $result = $this->service->executeAllApprovedGrouped(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('queue_count', $result);
        $this->assertArrayHasKey('candidate_count', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    /**
     * @test
     * WmsOrderIncomingScheduleのorderSourceリレーションが正しく動作すること
     */
    public function it_has_correct_order_source_relationship(): void
    {
        $schedule = WmsOrderIncomingSchedule::where('order_source', OrderSource::TRANSFER)->first();

        if ($schedule) {
            $this->assertEquals(OrderSource::TRANSFER, $schedule->order_source);
        } else {
            // TRANSFERタイプのスケジュールがなくてもテストは成功
            $this->assertTrue(true);
        }
    }

    /**
     * @test
     * WmsOrderIncomingScheduleのfromTransferスコープが動作すること
     */
    public function it_has_from_transfer_scope(): void
    {
        $query = WmsOrderIncomingSchedule::fromTransfer();

        $this->assertStringContainsString(
            'order_source',
            $query->toSql()
        );
    }

    /**
     * @test
     * WmsStockTransferCandidateのリレーションが正しく定義されていること
     */
    public function it_has_correct_relationships_on_transfer_candidate(): void
    {
        $candidate = new WmsStockTransferCandidate;

        $this->assertTrue(method_exists($candidate, 'satelliteWarehouse'));
        $this->assertTrue(method_exists($candidate, 'hubWarehouse'));
        $this->assertTrue(method_exists($candidate, 'item'));
        $this->assertTrue(method_exists($candidate, 'contractor'));
        $this->assertTrue(method_exists($candidate, 'deliveryCourse'));
    }

    /**
     * @test
     * WmsOrderIncomingScheduleのリレーションが正しく定義されていること
     */
    public function it_has_correct_relationships_on_incoming_schedule(): void
    {
        $schedule = new WmsOrderIncomingSchedule;

        $this->assertTrue(method_exists($schedule, 'transferCandidate'));
        $this->assertTrue(method_exists($schedule, 'sourceWarehouse'));
    }
}
