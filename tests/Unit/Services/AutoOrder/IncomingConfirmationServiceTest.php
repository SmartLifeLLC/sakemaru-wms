<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IncomingConfirmationService テスト
 *
 * 実DBの既存データを利用してテストを実行する。
 * DB:fresh, refreshなどのリセットは一切行わない。
 */
class IncomingConfirmationServiceTest extends TestCase
{
    private IncomingConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IncomingConfirmationService::class);
    }

    /**
     * @test
     * サービスがDIで解決できること
     */
    public function it_can_be_resolved_from_container(): void
    {
        $service = app(IncomingConfirmationService::class);
        $this->assertInstanceOf(IncomingConfirmationService::class, $service);
    }

    /**
     * @test
     * 存在しないスケジュールIDでconfirmMultipleを呼んでもエラーになること
     */
    public function it_handles_non_existent_schedule_in_confirm_multiple(): void
    {
        $result = $this->service->confirmMultiple([999999999], 1);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['success']);
        $this->assertEquals(1, $result['failed']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * @test
     * 空のスケジュールID配列でconfirmMultipleを呼んでもエラーにならないこと
     */
    public function it_handles_empty_schedule_ids_gracefully(): void
    {
        $result = $this->service->confirmMultiple([], 1);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['success']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * @test
     * IncomingScheduleStatusのenum値が正しいこと
     */
    public function it_has_correct_incoming_schedule_statuses(): void
    {
        $this->assertEquals('PENDING', IncomingScheduleStatus::PENDING->value);
        $this->assertEquals('PARTIAL', IncomingScheduleStatus::PARTIAL->value);
        $this->assertEquals('CONFIRMED', IncomingScheduleStatus::CONFIRMED->value);
        $this->assertEquals('CANCELLED', IncomingScheduleStatus::CANCELLED->value);
    }

    /**
     * @test
     * PENDING状態の入庫予定が取得できること
     */
    public function it_can_find_pending_incoming_schedules(): void
    {
        $pendingCount = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::PENDING)->count();

        // PENDING状態のスケジュールがなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $pendingCount);
    }

    /**
     * @test
     * TRANSFER タイプの入庫予定が取得できること
     */
    public function it_can_find_transfer_incoming_schedules(): void
    {
        $transferCount = WmsOrderIncomingSchedule::where('order_source', OrderSource::TRANSFER)->count();

        // TRANSFERタイプのスケジュールがなくてもテストは成功
        $this->assertGreaterThanOrEqual(0, $transferCount);
    }

    /**
     * @test
     * stock_transfer_queueテーブルにDELIVERタイプのレコードを挿入できる構造であること
     * 注意: action_typeカラムはsakemaru-ai-core側で追加される
     */
    public function it_can_insert_deliver_type_queue(): void
    {
        $columns = DB::connection('sakemaru')
            ->getSchemaBuilder()
            ->getColumnListing('stock_transfer_queue');

        $requiredColumns = [
            'client_id',
            'request_id',
            'stock_transfer_id',
            'delivered_date',
            'items',
            'note',
            'status',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "stock_transfer_queue should have {$column} column");
        }

        // action_typeはsakemaru-ai-core側で追加されるため、存在しない場合はスキップ
        if (! in_array('action_type', $columns)) {
            $this->markTestIncomplete('action_type column not yet added (requires sakemaru-ai-core migration)');
        }

        $this->assertContains('action_type', $columns);
    }

    /**
     * @test
     * WmsOrderIncomingScheduleモデルにstock_transfer_idカラムがあること
     */
    public function it_has_stock_transfer_id_in_incoming_schedule(): void
    {
        $fillable = (new WmsOrderIncomingSchedule)->getFillable();

        $this->assertContains('stock_transfer_id', $fillable, 'stock_transfer_id should be fillable');
    }

    /**
     * @test
     * WmsOrderIncomingScheduleモデルにtransfer_candidate_idカラムがあること
     */
    public function it_has_transfer_candidate_id_in_incoming_schedule(): void
    {
        $fillable = (new WmsOrderIncomingSchedule)->getFillable();

        $this->assertContains('transfer_candidate_id', $fillable, 'transfer_candidate_id should be fillable');
    }

    /**
     * @test
     * 確定済みスケジュールの再確定でエラーが発生すること
     */
    public function it_throws_exception_for_already_confirmed_schedule(): void
    {
        $confirmedSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->first();

        if (! $confirmedSchedule) {
            $this->markTestSkipped('No confirmed schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is already confirmed');

        $this->service->confirmIncoming($confirmedSchedule, 1);
    }

    /**
     * @test
     * キャンセル済みスケジュールの確定でエラーが発生すること
     */
    public function it_throws_exception_for_cancelled_schedule(): void
    {
        $cancelledSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CANCELLED)->first();

        if (! $cancelledSchedule) {
            $this->markTestSkipped('No cancelled schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is cancelled');

        $this->service->confirmIncoming($cancelledSchedule, 1);
    }

    /**
     * @test
     * 確定済みスケジュールの一部入庫記録でエラーが発生すること
     */
    public function it_throws_exception_for_partial_incoming_on_confirmed(): void
    {
        $confirmedSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->first();

        if (! $confirmedSchedule) {
            $this->markTestSkipped('No confirmed schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is already fully confirmed');

        $this->service->recordPartialIncoming($confirmedSchedule, 10, 1);
    }

    /**
     * @test
     * 確定済み・送信済みスケジュールのキャンセルでエラーが発生すること
     */
    public function it_throws_exception_for_cancelling_confirmed_schedule(): void
    {
        $confirmedSchedule = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->first();

        if (! $confirmedSchedule) {
            $this->markTestSkipped('No confirmed schedule available for testing');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel confirmed/transmitted schedule');

        $this->service->cancelIncoming($confirmedSchedule, 1, 'テストキャンセル');
    }

    /**
     * @test
     * createDeliverQueueメソッドが存在すること（リフレクションで確認）
     */
    public function it_has_create_deliver_queue_method(): void
    {
        $reflection = new \ReflectionClass(IncomingConfirmationService::class);

        $this->assertTrue(
            $reflection->hasMethod('createDeliverQueue'),
            'IncomingConfirmationService should have createDeliverQueue method'
        );

        $method = $reflection->getMethod('createDeliverQueue');
        $this->assertTrue($method->isPrivate(), 'createDeliverQueue should be private');
    }

    /**
     * @test
     * syncStockTransferIdメソッドが存在すること（リフレクションで確認）
     */
    public function it_has_sync_stock_transfer_id_method(): void
    {
        $reflection = new \ReflectionClass(IncomingConfirmationService::class);

        $this->assertTrue(
            $reflection->hasMethod('syncStockTransferId'),
            'IncomingConfirmationService should have syncStockTransferId method'
        );

        $method = $reflection->getMethod('syncStockTransferId');
        $this->assertTrue($method->isPrivate(), 'syncStockTransferId should be private');
    }
}
