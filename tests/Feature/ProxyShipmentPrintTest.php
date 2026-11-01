<?php

namespace Tests\Feature;

use App\Http\Controllers\ProxyShipmentPrintController;
use App\Models\User;
use App\Services\ProxyShipmentPrintService;
use App\Services\Shortage\ProxyShipmentTransferResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sakemaru\Auth\Services\PermissionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ProxyShipmentDatabase;
use Tests\TestCase;

class ProxyShipmentPrintTest extends TestCase
{
    use ProxyShipmentDatabase;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProxyShipmentDatabase('sakemaru');
        $this->makePrintable();
        $user = new User;
        $user->setRawAttributes(['id' => 9, 'client_id' => 6]);
        $this->actingAs($user);
        $this->mock(PermissionService::class)->shouldReceive('check')->andReturnTrue();
        $this->uuid = (string) Str::uuid();
        $id = DB::table('wms_proxy_shipment_print_requests')->insertGetId([
            'uuid' => $this->uuid, 'client_id' => 6, 'requested_by' => 9, 'idempotency_key' => Str::uuid(),
            'selection_hash' => hash('sha256', '[1]'), 'expires_at' => now()->addDay(),
        ]);
        $resolved = app(ProxyShipmentTransferResolver::class)->resolve(1, 6);
        unset($resolved['serial_id']);
        DB::table('wms_proxy_shipment_print_request_items')->insert(['request_id' => $id] + $resolved);
        Queue::fake();
    }

    public function test_print_permission_is_required_even_with_a_valid_request_uuid(): void
    {
        $permission = \Mockery::mock(PermissionService::class);
        $permission->shouldReceive('check')->andReturnFalse();
        $this->app->instance(PermissionService::class, $permission);
        $this->expectException(HttpException::class);
        app(ProxyShipmentPrintService::class)->start($this->uuid);
    }

    public function test_process_view_uses_the_registration_print_steps_and_same_origin_endpoints(): void
    {
        $this->withoutVite();
        $html = app(ProxyShipmentPrintController::class)->show($this->uuid)->render();
        $this->assertStringContainsString('対象確認', $html);
        $this->assertStringContainsString('PDF作成', $html);
        $this->assertStringContainsString('ファイル取得', $html);
        $this->assertStringContainsString('印刷準備', $html);
        $this->assertStringContainsString('/admin/stock-transfer-print/'.$this->uuid.'/start', $html);
        $this->assertStringContainsString('酒丸蔵', $html);
        $this->assertStringNotContainsString('https://sakemaru.test/stock-transfer-print', $html);
    }

    public function test_repeated_start_uses_one_attempt_and_never_creates_business_documents(): void
    {
        $service = app(ProxyShipmentPrintService::class);
        $a = $service->start($this->uuid);
        $b = $service->start($this->uuid);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, DB::table('wms_proxy_shipment_print_attempts')->count());
        $this->assertSame(1, DB::table('log_pdf_exports')->count());
        $this->assertSame(1, DB::table('stock_transfers')->count());
        $this->assertSame(1, DB::table('stock_transfer_queue')->count());
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        Queue::assertNothingPushed(); // Core receives the queued attempt through its existing worker.
    }

    public function test_foreign_owner_or_client_and_expired_request_are_rejected(): void
    {
        DB::table('wms_proxy_shipment_print_requests')->update(['requested_by' => 10]);
        try { app(ProxyShipmentPrintService::class)->authorizedRequest($this->uuid); $this->fail('Foreign owner allowed'); }
        catch (HttpException $exception) { $this->assertSame(404, $exception->getStatusCode()); }
        DB::table('wms_proxy_shipment_print_requests')->update(['requested_by' => 9, 'client_id' => 7]);
        try { app(ProxyShipmentPrintService::class)->authorizedRequest($this->uuid); $this->fail('Foreign client allowed'); }
        catch (HttpException $exception) { $this->assertSame(404, $exception->getStatusCode()); }
        DB::table('wms_proxy_shipment_print_requests')->update(['client_id' => 6, 'expires_at' => now()->subDay()]);
        $this->expectException(HttpException::class);
        app(ProxyShipmentPrintService::class)->authorizedRequest($this->uuid);
    }

    public function test_changed_document_is_rejected_before_a_job_or_log_is_created(): void
    {
        DB::table('trades')->where('id', 20)->update(['note' => 'changed']);
        try { app(ProxyShipmentPrintService::class)->start($this->uuid); $this->fail('Stale document allowed'); }
        catch (HttpException $exception) { $this->assertSame(409, $exception->getStatusCode()); }
        $this->assertSame(0, DB::table('log_pdf_exports')->count());
        Queue::assertNothingPushed();
    }

    public function test_file_get_does_not_mark_output_and_completion_is_idempotent(): void
    {
        $attempt = app(ProxyShipmentPrintService::class)->start($this->uuid);
        DB::table('wms_proxy_shipment_print_attempts')->where('id', $attempt->id)->update(['status' => 'ready']);
        DB::table('log_pdf_exports')->where('id', $attempt->log_pdf_export_id)->update(['prepared_at' => now()]);
        $path = DB::table('log_pdf_exports')->where('id', $attempt->log_pdf_export_id)->value('path');
        Storage::fake('core_pdf');
        Storage::disk('core_pdf')->put($path, '%PDF-1.4 fixture');
        $request = Request::create('/', 'GET', ['attempt' => 1]);
        $controller = app(ProxyShipmentPrintController::class);
        $this->assertSame('%PDF-1.4 fixture', $controller->file($request, $this->uuid)->getContent());
        $this->assertSame(0, DB::table('log_pdf_exports')->value('exported_count'));
        $controller->complete($request, $this->uuid);
        $controller->complete($request, $this->uuid);
        $this->assertSame(1, DB::table('log_pdf_exports')->value('exported_count'));
        $this->assertSame(10, DB::table('trades')->where('id', 10)->value('quantity'));
    }

    public function test_generation_retry_preserves_old_attempt_and_stale_completion_cannot_mark_new_log(): void
    {
        $service = app(ProxyShipmentPrintService::class);
        $first = $service->start($this->uuid);
        DB::table('wms_proxy_shipment_print_attempts')->where('id', $first->id)->update(['status' => 'failed']);
        $second = $service->start($this->uuid, true, 1);
        $this->assertSame(2, $second->attempt);
        $this->assertNotSame($first->log_pdf_export_id, $second->log_pdf_export_id);
        $this->assertSame('failed', DB::table('wms_proxy_shipment_print_attempts')->where('id', $first->id)->value('status'));
        try {
            app(ProxyShipmentPrintController::class)->complete(Request::create('/', 'POST', ['attempt' => 1]), $this->uuid);
            $this->fail('Failed attempt marked complete');
        } catch (HttpException) {}
        $this->assertSame(0, DB::table('log_pdf_exports')->where('id', $second->log_pdf_export_id)->value('exported_count'));
    }

    public function test_retry_resend_recovers_the_accepted_attempt_after_a_lost_response(): void
    {
        $service = app(ProxyShipmentPrintService::class);
        $first = $service->start($this->uuid);
        DB::table('wms_proxy_shipment_print_attempts')->where('id', $first->id)->update(['status' => 'failed']);
        $second = $service->start($this->uuid, true, 1);
        $replayed = $service->start($this->uuid, true, 1);
        $this->assertSame($second->id, $replayed->id);
        $this->assertSame(2, DB::table('wms_proxy_shipment_print_attempts')->count());
        // A delayed retry of attempt 1 must not generate attempt 3, even if attempt 2 failed.
        DB::table('wms_proxy_shipment_print_attempts')->where('id', $second->id)->update(['status' => 'failed']);
        $this->assertSame($second->id, $service->start($this->uuid, true, 1)->id);
        $this->assertSame(2, DB::table('log_pdf_exports')->count());
    }

    public function test_retry_endpoint_requires_the_failed_attempt_and_replays_its_successor(): void
    {
        $first = app(ProxyShipmentPrintService::class)->start($this->uuid);
        DB::table('wms_proxy_shipment_print_attempts')->where('id', $first->id)->update(['status' => 'failed']);
        $controller = app(ProxyShipmentPrintController::class);
        try {
            $controller->retry(Request::create('/', 'POST'), $this->uuid);
            $this->fail('Missing attempt accepted');
        } catch (\Illuminate\Validation\ValidationException) {}
        $request = Request::create('/', 'POST', ['attempt' => 1]);
        $this->assertSame(2, $controller->retry($request, $this->uuid)->getData()->attempt);
        $this->assertSame(2, $controller->retry($request, $this->uuid)->getData()->attempt);
        $this->assertSame(2, DB::table('log_pdf_exports')->count());
    }

    public function test_pending_file_cannot_be_downloaded_or_marked_as_output(): void
    {
        app(ProxyShipmentPrintService::class)->start($this->uuid);
        $this->expectException(HttpException::class);
        app(ProxyShipmentPrintController::class)->file(Request::create('/', 'GET', ['attempt' => 1]), $this->uuid);
    }
}
