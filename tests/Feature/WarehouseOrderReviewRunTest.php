<?php

namespace Tests\Feature;

use App\Enums\ExportStatus;
use App\Jobs\GenerateWarehouseOrderReview;
use App\Models\User;
use App\Models\WmsExportLog;
use App\Services\Export\ExportService;
use App\Services\Statistics\WarehouseOrderReviewRunService;
use App\Services\Statistics\WarehouseOrderReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Sakemaru\Auth\Services\PermissionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WarehouseOrderReviewRunTest extends TestCase
{
    private WarehouseOrderReviewRunService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'warehouse-order-review.connection' => 'database', 'warehouse-order-review.disk' => 'local']);
        DB::purge('sakemaru');
        Schema::connection('sakemaru')->create('users', function (Blueprint $table) {
            $table->id();
            $table->integer('client_id');
        });
        Schema::connection('sakemaru')->create('wms_export_logs', function (Blueprint $table) {
            $table->id();
            foreach (['resource_name', 'format', 'status', 'file_name', 'file_path', 'error_message'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['user_id', 'file_size', 'row_count'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->json('filters')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });
        DB::connection('sakemaru')->table('users')->insert(['id' => 991, 'client_id' => 6]);
        Bus::fake();
        Storage::fake('local');
        $this->service = app(WarehouseOrderReviewRunService::class);
    }

    private function start(): WmsExportLog
    {
        return $this->service->start(991, 6, ['date' => '2026-10-29', 'warehouse_id' => 1, 'warehouse_code' => 91, 'warehouse_name' => 'テスト倉庫']);
    }

    public function test_repeated_request_reuses_active_run_and_dispatches_once(): void
    {
        $first = $this->start();
        $this->assertSame($first->id, $this->start()->id);
        $this->assertSame(ExportStatus::PENDING, $first->status);
        Bus::assertDispatchedTimes(GenerateWarehouseOrderReview::class, 1);
        $this->assertSame($first->id, $this->service->owned(991, 6)->latest('id')->first()->id);
        $this->assertNull($this->service->owned(992, 6)->first());
        $this->assertNull($this->service->owned(991, 7)->first());
    }

    public function test_generates_durable_excel_and_duplicate_job_does_not_run_again(): void
    {
        $log = $this->start();
        $query = Mockery::mock(WarehouseOrderReviewService::class);
        $query->shouldReceive('rows')->once()->with(6, '2026-10-29', 91)->andReturn([
            ['__item_id' => 1, '単品CD' => '001', '商品名' => 'テスト', '備考' => '=1+1'],
            ['__item_id' => 2, '単品CD' => '002', '商品名' => 'テスト2', '備考' => 'B-4'],
        ]);
        $this->app->instance(WarehouseOrderReviewService::class, $query);
        $this->service->generate($log->id);
        $this->service->generate($log->id);
        $log->refresh();
        $this->assertSame(ExportStatus::COMPLETED, $log->status);
        $this->assertSame(100, $log->filters['progress']['percent']);
        $this->assertSame(2, $log->row_count);
        Storage::disk('local')->assertExists($log->file_path);
        $this->assertSame('PK', substr(Storage::disk('local')->get($log->file_path), 0, 2));
        $this->service->fail($log->id);
        $this->assertSame(ExportStatus::COMPLETED, $log->fresh()->status);
        $this->assertNotSame($log->id, $this->start()->id);
    }

    public function test_query_failure_is_safe_and_retry_creates_a_new_run(): void
    {
        $log = $this->start();
        $query = Mockery::mock(WarehouseOrderReviewService::class);
        $query->shouldReceive('rows')->once()->andThrow(new \RuntimeException('private SQL details'));
        $this->app->instance(WarehouseOrderReviewService::class, $query);
        try {
            $this->service->generate($log->id);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('private SQL details', $e->getMessage());
        }
        $log->refresh();
        $this->assertSame(ExportStatus::FAILED, $log->status);
        $this->assertSame(WarehouseOrderReviewRunService::FAILURE, $log->error_message);
        $this->assertNull($log->file_path);
        $this->assertNotSame($log->id, $this->start()->id);
    }

    public function test_storage_failure_does_not_publish_a_completed_file(): void
    {
        $log = $this->start();
        $query = Mockery::mock(WarehouseOrderReviewService::class);
        $query->shouldReceive('rows')->once()->andReturn([]);
        $this->app->instance(WarehouseOrderReviewService::class, $query);
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk = Mockery::mock());
        $disk->shouldReceive('put')->once()->andReturn(false);
        try {
            $this->service->generate($log->id);
            $this->fail('Expected storage failure');
        } catch (\RuntimeException) {
            $this->assertSame(ExportStatus::FAILED, $log->fresh()->status);
            $this->assertNull($log->fresh()->file_path);
        }
    }

    public function test_dispatch_failure_is_visible_instead_of_remaining_pending(): void
    {
        Bus::swap($bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class));
        $bus->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $log = $this->start()->fresh();
        $this->assertSame(ExportStatus::FAILED, $log->status);
    }

    public function test_download_from_shared_history_checks_ownership(): void
    {
        $log = $this->start();
        $user = new User(['client_id' => 6]);
        $user->id = 992;
        $this->actingAs($user);
        $permission = Mockery::mock(PermissionService::class);
        $permission->shouldReceive('check')->andReturn(true);
        $this->app->instance(PermissionService::class, $permission);
        $this->expectException(HttpException::class);
        app(ExportService::class)->getDownloadResponse($log);
    }

    public function test_empty_export_downloads_and_missing_file_is_reported(): void
    {
        $log = $this->start();
        $query = Mockery::mock(WarehouseOrderReviewService::class);
        $query->shouldReceive('rows')->once()->andReturn([]);
        $this->app->instance(WarehouseOrderReviewService::class, $query);
        $this->service->generate($log->id);
        $log->refresh();
        $this->assertSame(0, $log->row_count);
        $user = new User(['client_id' => 6]);
        $user->id = 991;
        $this->actingAs($user);
        $permission = Mockery::mock(PermissionService::class);
        $permission->shouldReceive('check')->andReturn(true);
        $this->app->instance(PermissionService::class, $permission);
        $response = app(ExportService::class)->getDownloadResponse($log);
        ob_start();
        $response->sendContent();
        $this->assertSame('PK', substr(ob_get_clean(), 0, 2));
        $this->assertNotNull($log->fresh()->downloaded_at);
        Storage::disk('local')->delete($log->file_path);
        $this->expectException(\RuntimeException::class);
        $this->service->download($log);
    }

    public function test_sync_connection_is_rejected_before_creating_a_run(): void
    {
        config(['warehouse-order-review.connection' => 'sync']);
        try {
            $this->start();
            $this->fail('Sync queue should not be used');
        } catch (\RuntimeException) {
            $this->assertSame(0, WmsExportLog::count());
        }
    }
}
