<?php

namespace Tests\Feature\AutoOrder;

use App\Models\WmsOrderCandidate;
use App\Services\AutoReplenishment\JxGenerationGuard;
use App\Services\AutoReplenishment\ManualJxClaimRecoveryService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

/**
 * Only isolated SQLite memory is used. No application migrations, external files,
 * queue jobs or network calls. SQLite does not prove MySQL concurrent lock behavior.
 */
class ManualJxClaimRecoveryTest extends TestCase
{
    private Connection $db;

    private bool $lockAvailable = true;

    private bool $lockOwned = true;

    private bool $permissionGranted = true;

    private int $ownershipChecks = 0;

    private ?int $loseOwnershipAt = null;

    private const NOW = '2026-10-18 20:00:00';

    private const TABLES = [
        'users', 'warehouses', 'items', 'wms_order_candidates', 'wms_order_incoming_schedules',
        'wms_order_generation_claims', 'wms_auto_order_run_lines', 'wms_auto_order_job_controls',
        'wms_order_jx_documents', 'wms_order_slip_number_assignments', 'wms_order_data_files',
        'wms_order_transmission_logs', 'wms_auto_order_events', 'wms_order_slip_number_sequences',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
        Http::preventStrayRequests();
        config(['app.client_id' => 6, 'database.connections.sakemaru' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('sakemaru');
        $this->db = DB::connection('sakemaru');
        $this->assertSame('sqlite', $this->db->getDriverName());
        $this->assertSame(':memory:', $this->db->getDatabaseName());
        $this->travelTo(\Carbon\Carbon::parse(self::NOW));
        $this->db->getPdo()->sqliteCreateFunction('GET_LOCK', fn ($key, $timeout) => $this->lockAvailable ? 1 : 0, 2);
        $this->db->getPdo()->sqliteCreateFunction('RELEASE_LOCK', static fn ($key) => 1, 1);
        $this->db->getPdo()->sqliteCreateFunction('CONNECTION_ID', static fn () => 123, 0);
        $this->db->getPdo()->sqliteCreateFunction('IS_USED_LOCK', function ($key) {
            $this->ownershipChecks++;

            return $this->lockOwned && ($this->loseOwnershipAt === null || $this->ownershipChecks < $this->loseOwnershipAt) ? 123 : null;
        }, 1);
        // Only permission cache access and feature detection are substituted.
        // Hashing, incoming conversion, claim lifecycle and audit persistence are real.
        $guard = Mockery::mock(ReplenishmentGuard::class)->makePartial();
        $guard->shouldReceive('installed')->andReturn(true);
        $this->app->instance(ReplenishmentGuard::class, $guard);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('check')->andReturnUsing(fn ($actor, $permission) => $this->permissionGranted);
        $this->app->instance(PermissionService::class, $permissions);
        $this->app->forgetInstance(JxGenerationGuard::class);
        Storage::shouldReceive('disk')->never();
        $this->createSchema();
        $this->db->table('users')->insert(['id' => 99, 'client_id' => 6, 'is_active' => true]);
        $this->db->table('warehouses')->insert([
            ['id' => 1, 'client_id' => 6], ['id' => 2, 'client_id' => 6], ['id' => 3, 'client_id' => 7],
        ]);
        $this->db->table('items')->insert(['id' => 100, 'client_id' => 6, 'capacity_case' => 24, 'capacity_carton' => 6, 'is_active' => true]);
        $this->db->table('wms_auto_order_job_controls')->insert(['id' => 1, 'batch_code' => 'TEST-RECOVERY', 'created_by' => 77]);
        $this->db->table('wms_order_slip_number_sequences')->insert(['id' => 1, 'last_number' => 88]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->travelBack();
        DB::purge('sakemaru');
        parent::tearDown();
    }

    public function test_preview_changes_nothing_and_apply_removes_only_target_claim_and_audits(): void
    {
        $token = $this->stoppedCandidate();
        $this->file();
        $before = $this->snapshot();
        $preview = $this->service()->inspect(1, 99);
        $this->assertSame('CHECKED', $preview['status']);
        $this->assertSame($token, $preview['claim_token']);
        $this->assertSame([77], $preview['original_creator_ids']);
        $this->assertSame([1], $preview['incoming_schedule_ids']);
        $this->assertSame(['test/orphan.csv'], $preview['confirmation_file_paths']);
        $this->assertSame($before, $this->snapshot());

        $result = $this->service()->recover(1, 99, $token, '送信先とファイルを確認済み', true);
        $this->assertSame('RECOVERED', $result['status']);
        $after = $this->snapshot();
        $this->assertSame([], $after['wms_order_generation_claims']);
        $event = $this->db->table('wms_auto_order_events')->sole();
        $this->assertSame('MANUAL_JX_UNPUBLISHED_CLAIM_RESOLVED', $event->event);
        $this->assertSame(99, $event->actor_id);
        $this->assertSame(6, $event->client_id);
        $this->assertNull($event->line_id);
        $payload = json_decode($event->payload, true);
        $this->assertSame($token, $payload['claim_token']);
        $this->assertSame('送信先とファイルを確認済み', $payload['reason']);
        unset($before['wms_order_generation_claims'], $before['wms_auto_order_events'], $after['wms_order_generation_claims'], $after['wms_auto_order_events']);
        $this->assertSame($before, $after);

        // Recovery permits the existing generator to acquire a NEW token.
        $guard = app(JxGenerationGuard::class);
        $newToken = $guard->acquire(collect([WmsOrderCandidate::findOrFail(1)]));
        $this->assertNotSame($token, $newToken);
        // A delayed old worker can neither publish nor release the replacement claim.
        try {
            $guard->validatePublication(collect([WmsOrderCandidate::findOrFail(1)]), $token);
            $this->fail('Old token must not publish.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('JXを公開しません', $e->getMessage());
        }
        $guard->releaseUnpublished($token);
        $this->assertSame($newToken, $this->db->table('wms_order_generation_claims')->sole()->token);
        $guard->releaseUnpublished($newToken);
    }

    public static function quantityUnits(): array
    {
        return [
            'piece' => ['PIECE', 48, 'PIECE', 48],
            'case' => ['CASE', 2, 'PIECE', 48],
            'carton' => ['CARTON', 8, 'CASE', 2],
        ];
    }

    #[DataProvider('quantityUnits')]
    public function test_incoming_quantity_uses_existing_candidate_units(string $candidateUnit, int $quantity, string $incomingUnit, int $incomingQuantity): void
    {
        $this->stoppedCandidate(1, ['quantity_type' => $candidateUnit, 'order_quantity' => $quantity]);
        $this->db->table('wms_order_incoming_schedules')->where('id', 1)->update(['quantity_type' => $incomingUnit, 'expected_quantity' => $incomingQuantity]);
        $this->assertSame('CHECKED', $this->service()->inspect(1, 99)['status']);
    }

    public function test_distributed_incoming_schedules_are_reconciled_in_total_pieces(): void
    {
        $this->stoppedCandidate(1, ['demand_breakdown' => json_encode([['warehouse_id' => 1, 'quantity' => 24], ['warehouse_id' => 2, 'quantity' => 48]])]);
        $this->db->table('wms_order_incoming_schedules')->where('id', 1)->update(['expected_quantity' => 24]);
        $this->schedule(2, 1, ['warehouse_id' => 2, 'expected_quantity' => 2, 'quantity_type' => 'CASE']);
        $this->assertSame([1, 2], $this->service()->inspect(1, 99)['incoming_schedule_ids']);
    }

    public function test_partial_publication_keeps_other_candidate_document_claim_files_and_logs(): void
    {
        $token = $this->stoppedCandidate();
        $this->stoppedCandidate(2);
        $this->document(['status' => 'TRANSMITTED', 'transmitted_at' => self::NOW]);
        $this->db->table('wms_order_candidates')->where('id', 2)->update(['wms_order_jx_document_id' => 11, 'transmission_status' => 'SENT']);
        // Same token as the interrupted multi-file batch.
        $this->db->table('wms_order_generation_claims')->where('candidate_id', 2)->update(['state' => 'PUBLISHED', 'document_id' => 11, 'token' => $token]);
        $this->assignment(['order_candidate_ids' => '[2]', 'slip_number' => 'OTHER', 'status' => 'TRANSMITTED']);
        $this->file(['candidate_ids' => '[2]', 'file_path' => 'test/other.csv']);
        $this->transmissionLog(['wms_order_jx_document_id' => 11]);
        $before = $this->snapshot();
        $result = $this->service()->recover(1, 99, $token, '未生成分のみ照合済み', true);
        $this->assertSame([11], $result['other_published_document_ids']);
        $after = $this->snapshot();
        $this->assertSame($before['wms_order_generation_claims'][1], $after['wms_order_generation_claims'][0]);
        unset($before['wms_order_generation_claims'], $before['wms_auto_order_events'], $after['wms_order_generation_claims'], $after['wms_auto_order_events']);
        $this->assertSame($before, $after);
    }

    public static function unsafeStates(): array
    {
        return [
            'actor wrong client' => ['users', ['client_id' => 7], '有効な担当者'],
            'actor inactive' => ['users', ['is_active' => false], '有効な担当者'],
            'warehouse wrong client' => ['warehouses', ['client_id' => 7], '対象倉庫'],
            'item wrong client' => ['items', ['client_id' => 7], '商品の所属'],
            'item capacity changed' => ['items', ['capacity_case' => 12], '入数が変わって'],
            'candidate quantity changed' => ['wms_order_candidates', ['order_quantity' => 24], '発注内容'],
            'candidate version changed' => ['wms_order_candidates', ['lock_version' => 2], '発注内容'],
            'candidate not confirmed' => ['wms_order_candidates', ['status' => 'APPROVED'], '確定済み'],
            'candidate FAX' => ['wms_order_candidates', ['order_channel' => 'FAX'], 'EOS'],
            'origin unknown' => ['wms_order_candidates', ['origin_type' => 'AUTO'], '手動発注'],
            'sent' => ['wms_order_candidates', ['transmission_status' => 'SENT'], '送信済み'],
            'failed transmit' => ['wms_order_candidates', ['transmission_status' => 'FAILED'], '送信結果'],
            'candidate published link' => ['wms_order_candidates', ['wms_order_jx_document_id' => 11], '登録済み'],
            'claim published' => ['wms_order_generation_claims', ['state' => 'PUBLISHED'], '登録済み'],
            'claim document' => ['wms_order_generation_claims', ['document_id' => 11], '登録済み'],
            'claim token changed' => ['wms_order_generation_claims', ['token' => 'replacement'], '照合後'],
            'claim hash changed' => ['wms_order_generation_claims', ['manifest_hash' => 'changed'], '発注内容'],
            'missing claim start' => ['wms_order_generation_claims', ['created_at' => null], '開始日時'],
            'incoming received' => ['wms_order_incoming_schedules', ['received_quantity' => 1], '入荷予定'],
            'incoming partial' => ['wms_order_incoming_schedules', ['status' => 'PARTIAL'], '入荷予定'],
            'incoming purchase queue' => ['wms_order_incoming_schedules', ['purchase_queue_id' => 12], '入荷予定'],
            'incoming transfer' => ['wms_order_incoming_schedules', ['stock_transfer_id' => 12], '入荷予定'],
            'incoming another item' => ['wms_order_incoming_schedules', ['item_id' => 200], '入荷予定'],
            'incoming another contractor' => ['wms_order_incoming_schedules', ['contractor_id' => 20], '入荷予定'],
            'incoming date' => ['wms_order_incoming_schedules', ['expected_arrival_date' => '2026-10-20'], '入荷予定'],
            'incoming quantity' => ['wms_order_incoming_schedules', ['expected_quantity' => 71], '総バラ数'],
            'incoming another warehouse' => ['wms_order_incoming_schedules', ['warehouse_id' => 2], '入荷予定の倉庫'],
            'incoming other client' => ['wms_order_incoming_schedules', ['warehouse_id' => 3], '入荷予定の倉庫'],
        ];
    }

    #[DataProvider('unsafeStates')]
    public function test_unsafe_state_is_rejected_without_mutation(string $table, array $changes, string $message): void
    {
        $token = $this->stoppedCandidate();
        $id = match ($table) {
            'users' => 99, 'items' => 100, default => 1
        };
        $this->db->table($table)->where('id', $id)->update($changes);
        $this->assertRejected($token, $message);
    }

    public function test_permissions_and_worker_mutex_are_required(): void
    {
        $token = $this->stoppedCandidate();
        $this->permissionGranted = false;
        $this->assertRejected($token, '管理権限');
        $this->permissionGranted = true;
        $this->lockAvailable = false;
        $this->assertRejected($token, '別の処理');
        $this->lockAvailable = true;
        $this->lockOwned = false;
        $this->assertRejected($token, '排他状態');
    }

    public function test_lock_ownership_loss_after_audit_rolls_back_every_change(): void
    {
        $token = $this->stoppedCandidate();
        $this->loseOwnershipAt = 3;
        $this->assertRejected($token, '排他状態');
    }

    public function test_any_automatic_run_line_is_rejected_even_for_another_client(): void
    {
        $token = $this->stoppedCandidate();
        $this->db->table('wms_auto_order_run_lines')->insert(['id' => 1, 'client_id' => 7, 'order_candidate_id' => 1]);
        $this->assertRejected($token, '自動発注');
    }

    public function test_missing_incoming_schedule_is_rejected(): void
    {
        $token = $this->stoppedCandidate();
        $this->db->table('wms_order_incoming_schedules')->where('id', 1)->delete();
        $this->assertRejected($token, '入荷予定がありません');
    }

    public static function assignmentReferences(): array
    {
        return ['number' => ['[1]', 'DIFFERENT'], 'string' => ['["1"]', 'DIFFERENT'], 'slip' => ['[2]', 'SLIP-1']];
    }

    #[DataProvider('assignmentReferences')]
    public function test_numeric_string_and_slip_assignment_links_are_checked(string $ids, string $slip): void
    {
        $token = $this->stoppedCandidate();
        $this->assignment(['order_candidate_ids' => $ids, 'slip_number' => $slip]);
        $this->assertRejected($token, '伝票番号割当');
    }

    public function test_cancelled_assignment_with_missing_document_is_rejected(): void
    {
        $token = $this->stoppedCandidate();
        $this->assignment(['status' => 'CANCELLED']);
        $this->assertRejected($token, '参照先がありません');
    }

    public function test_clean_older_cancelled_document_and_assignment_are_preserved(): void
    {
        $token = $this->stoppedCandidate();
        $this->assignment(['status' => 'CANCELLED']);
        $this->document(['status' => 'CANCELLED', 'updated_at' => '2026-10-18 19:00:00']);
        $this->transmissionLog(['wms_order_jx_document_id' => 11, 'action' => 'CANCEL', 'created_at' => '2026-10-18 19:00:00']);
        $this->assertSame([11], $this->service()->recover(1, 99, $token, '取消後の未生成を照合済み', true)['cancelled_document_ids']);
        $this->assertSame('CANCELLED', $this->db->table('wms_order_jx_documents')->sole()->status);
    }

    public static function documentStates(): array
    {
        return array_combine(['PENDING', 'TRANSMITTING', 'TRANSMITTED', 'ERROR', 'CANCELLED'], array_map(fn ($state) => [$state], ['PENDING', 'TRANSMITTING', 'TRANSMITTED', 'ERROR', 'CANCELLED']));
    }

    #[DataProvider('documentStates')]
    public function test_related_or_unlinked_recent_document_refuses_recovery(string $state): void
    {
        $token = $this->stoppedCandidate();
        // A batch document with neither candidate nor assignment linkage is uncertain.
        $this->document(['status' => $state]);
        $this->assertRejected($token, 'JXデータ');
    }

    public function test_exact_csv_document_mapping_is_checked_even_in_another_batch(): void
    {
        $token = $this->stoppedCandidate();
        $this->file(['candidate_ids' => '["1"]']);
        $this->document(['csv_path' => 'test/orphan.csv', 'batch_code' => 'OTHER-BATCH']);
        $this->assertRejected($token, 'JXデータ');
    }

    public static function distributedFiles(): array
    {
        return [
            'downloaded csv' => [['csv_downloaded_at' => self::NOW]],
            'downloaded fax' => [['fax_downloaded_at' => self::NOW]],
            'mailed' => [['mail_sent_at' => self::NOW]],
            'unknown channel' => [['order_channel' => 'FAX']],
            'unknown status' => [['status' => 'ERROR']],
        ];
    }

    #[DataProvider('distributedFiles')]
    public function test_distributed_or_unknown_file_refuses_recovery(array $changes): void
    {
        $token = $this->stoppedCandidate();
        $this->file($changes);
        $this->assertRejected($token, '発注ファイル');
    }

    public static function transmissionResults(): array
    {
        return ['success' => ['SUCCESS', 11], 'failed' => ['FAILED', null], 'unknown document' => ['SUCCESS', 99]];
    }

    #[DataProvider('transmissionResults')]
    public function test_any_unresolved_transmission_attempt_is_rejected(string $status, ?int $documentId): void
    {
        $token = $this->stoppedCandidate();
        $this->transmissionLog(['status' => $status, 'wms_order_jx_document_id' => $documentId]);
        $this->assertRejected($token, '送信を試みた記録');
    }

    public function test_audit_failure_rolls_back_claim_removal(): void
    {
        $token = $this->stoppedCandidate();
        $before = $this->snapshot();
        $this->db->unprepared("CREATE TRIGGER reject_recovery_audit BEFORE INSERT ON wms_auto_order_events BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        try {
            $this->service()->recover(1, 99, $token, '送信状況を照合済み', true);
            $this->fail('Audit must be atomic with claim deletion.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('test audit failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unlinked_transmission_before_claim_start_is_also_unknown(): void
    {
        $token = $this->stoppedCandidate();
        $this->transmissionLog(['status' => 'FAILED', 'created_at' => '2026-10-18 19:00:00']);
        $this->assertRejected($token, '送信を試みた記録');
    }

    public static function missingApplyEvidence(): array
    {
        return [
            'no token' => ['', '送信状況を照合済み', true, '対象識別子'],
            'short reason' => ['token', '短い', true, '復旧理由'],
            'no verification' => ['token', '送信状況を照合済み', false, 'ファイルとログ'],
        ];
    }

    #[DataProvider('missingApplyEvidence')]
    public function test_apply_evidence_is_required(string $token, string $reason, bool $confirmed, string $message): void
    {
        $this->stoppedCandidate();
        $before = $this->snapshot();
        try {
            $this->service()->recover(1, 99, $token, $reason, $confirmed);
            $this->fail('Explicit recovery evidence is mandatory.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_manual_command_defaults_to_preview_and_requires_explicit_apply_evidence(): void
    {
        $token = $this->stoppedCandidate();
        $before = $this->snapshot();
        $this->artisan('wms:resolve-auto-replenishment-claim', ['candidate' => '1', '--manual' => true, '--actor' => '99'])
            ->expectsOutputToContain('変更はしていません')->expectsOutputToContain($token)->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('wms:resolve-auto-replenishment-claim', ['candidate' => '1', '--manual' => true, '--apply' => true, '--actor' => '99'])
            ->expectsOutputToContain('ファイルとログ')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('wms:resolve-auto-replenishment-claim', ['candidate' => '1', '--manual' => true, '--apply' => true, '--actor' => '99', '--claim-token' => $token, '--reason' => 'ファイルと送信を照合済み', '--confirmed-unpublished' => true])
            ->expectsOutputToContain('対象1件')->assertSuccessful();
        $this->assertSame(0, $this->db->table('wms_order_generation_claims')->count());
    }

    public function test_existing_automatic_mode_still_requires_its_confirmation_flag(): void
    {
        $this->artisan('wms:resolve-auto-replenishment-claim', ['candidate' => '1'])
            ->expectsOutputToContain('未公開の照合完了フラグ')->assertFailed();
    }

    private function assertRejected(string $token, string $message): void
    {
        $before = $this->snapshot();
        try {
            $this->service()->recover(1, 99, $token, '送信状況を照合済み', true);
            $this->fail('Unsafe recovery must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function service(): ManualJxClaimRecoveryService
    {
        return app(ManualJxClaimRecoveryService::class);
    }

    private function stoppedCandidate(int $id = 1, array $changes = []): string
    {
        $values = array_replace([
            'id' => $id, 'warehouse_id' => 1, 'item_id' => 100, 'contractor_id' => 1021, 'supplier_id' => 1021,
            'batch_code' => 'TEST-RECOVERY', 'status' => 'CONFIRMED', 'order_channel' => 'EOS', 'origin_type' => 'MANUAL_SALES_BASED',
            'order_quantity' => 72, 'quantity_type' => 'PIECE', 'ordering_code' => '1234567890123',
            'expected_arrival_date' => '2026-10-19', 'purchase_unit_price' => 100, 'lock_version' => 1, 'transmission_status' => 'PENDING',
        ], $changes);
        $this->db->table('wms_order_candidates')->insert($values);
        $this->schedule($id, $id, ['expected_quantity' => $values['order_quantity'], 'quantity_type' => $values['quantity_type']]);

        // Real production claim creation; retaining the row simulates abrupt process exit.
        return app(JxGenerationGuard::class)->acquire(collect([WmsOrderCandidate::findOrFail($id)]));
    }

    private function schedule(int $id, int $candidateId, array $changes = []): void
    {
        $this->db->table('wms_order_incoming_schedules')->insert(array_replace([
            'id' => $id, 'order_candidate_id' => $candidateId, 'warehouse_id' => 1, 'item_id' => 100, 'contractor_id' => 1021,
            'expected_arrival_date' => '2026-10-19', 'expected_quantity' => 72, 'quantity_type' => 'PIECE', 'received_quantity' => 0,
            'status' => 'PENDING', 'slip_number' => 'SLIP-'.$candidateId,
        ], $changes));
    }

    private function document(array $changes = []): void
    {
        $this->db->table('wms_order_jx_documents')->insert(array_replace([
            'id' => 11, 'status' => 'PENDING', 'batch_code' => 'TEST-RECOVERY', 'order_count' => 1, 'updated_at' => self::NOW,
        ], $changes));
    }

    private function assignment(array $changes = []): void
    {
        $this->db->table('wms_order_slip_number_assignments')->insert(array_replace([
            'id' => 1, 'wms_order_jx_document_id' => 11, 'order_candidate_ids' => '[1]', 'slip_number' => 'SLIP-1', 'status' => 'ACTIVE',
        ], $changes));
    }

    private function file(array $changes = []): void
    {
        $this->db->table('wms_order_data_files')->insert(array_replace([
            'id' => 1, 'candidate_ids' => '[1]', 'batch_code' => 'TEST-RECOVERY', 'order_channel' => 'JX_CONFIRMATION',
            'status' => 'GENERATED', 'file_path' => 'test/orphan.csv',
        ], $changes));
    }

    private function transmissionLog(array $changes = []): void
    {
        $this->db->table('wms_order_transmission_logs')->insert(array_replace([
            'id' => 1, 'batch_code' => 'TEST-RECOVERY', 'action' => 'TRANSMIT', 'status' => 'SUCCESS', 'created_at' => self::NOW,
        ], $changes));
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $result[$table] = $this->db->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    private function createSchema(): void
    {
        // Nullable fields model real production metadata, while fixture schemas
        // intentionally contain only columns this recovery flow reads or writes.
        $schemas = [
            'users' => ['ints' => ['client_id', 'is_active']],
            'warehouses' => ['ints' => ['client_id']],
            'items' => ['ints' => ['client_id', 'capacity_case', 'capacity_carton', 'is_active']],
            'wms_order_candidates' => [
                'ints' => ['warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'order_quantity', 'lock_version', 'wms_order_jx_document_id'],
                'texts' => ['batch_code', 'status', 'order_channel', 'origin_type', 'quantity_type', 'ordering_code', 'expected_arrival_date', 'purchase_unit_price', 'transmission_status', 'transmitted_at', 'demand_breakdown'],
            ],
            'wms_order_incoming_schedules' => [
                'ints' => ['order_candidate_id', 'warehouse_id', 'item_id', 'contractor_id', 'expected_quantity', 'received_quantity', 'purchase_queue_id', 'stock_transfer_id'],
                'texts' => ['status', 'quantity_type', 'slip_number', 'expected_arrival_date'],
            ],
            'wms_order_generation_claims' => ['ints' => ['candidate_id', 'document_id'], 'texts' => ['token', 'state', 'manifest_hash']],
            'wms_auto_order_run_lines' => ['ints' => ['client_id', 'order_candidate_id']],
            'wms_auto_order_job_controls' => ['ints' => ['created_by'], 'texts' => ['batch_code']],
            'wms_order_jx_documents' => ['ints' => ['order_count'], 'texts' => ['status', 'batch_code', 'csv_path', 'transmitted_at', 'jx_message_id', 'jx_document_no']],
            'wms_order_slip_number_assignments' => ['ints' => ['wms_order_jx_document_id'], 'texts' => ['order_candidate_ids', 'status', 'slip_number']],
            'wms_order_data_files' => ['texts' => ['candidate_ids', 'batch_code', 'order_channel', 'status', 'file_path', 'csv_downloaded_at', 'fax_downloaded_at', 'mail_sent_at']],
            'wms_order_transmission_logs' => ['ints' => ['wms_order_jx_document_id'], 'texts' => ['batch_code', 'action', 'status']],
            'wms_auto_order_events' => ['ints' => ['client_id', 'line_id', 'actor_id'], 'texts' => ['event', 'payload']],
            'wms_order_slip_number_sequences' => ['ints' => ['last_number']],
        ];
        foreach ($schemas as $name => $columns) {
            $this->db->getSchemaBuilder()->create($name, function (Blueprint $table) use ($columns, $name) {
                $table->id();
                foreach ($columns['ints'] ?? [] as $column) {
                    $table->integer($column)->nullable();
                }
                foreach ($columns['texts'] ?? [] as $column) {
                    $table->text($column)->nullable();
                }
                $table->timestamps();
                if ($name === 'wms_order_generation_claims') {
                    $table->unique('candidate_id');
                }
            });
        }
    }
}
