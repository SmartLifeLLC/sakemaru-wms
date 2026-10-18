<?php

namespace Tests\Feature\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderCancellationService;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoReplenishment\JxGenerationGuard;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uses only a fresh in-memory database; no application migrations or external services.
 * SQLite covers state transitions and rollback, not MySQL concurrent row-lock behavior.
 */
class OrderCancellationJxLifecycleTest extends TestCase
{
    private Connection $db;

    private const TABLES = [
        'wms_order_candidates', 'wms_order_incoming_schedules', 'wms_order_jx_documents',
        'wms_order_generation_claims', 'wms_auto_order_run_lines',
        'wms_order_slip_number_assignments', 'wms_order_candidate_audit_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.sakemaru' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('sakemaru');
        $this->db = DB::connection('sakemaru');
        $this->assertSame('sqlite', $this->db->getDriverName());
        $this->assertSame(':memory:', $this->db->getDatabaseName());

        // Exercise the real guard, substituting only feature installation detection.
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('installed')->andReturn(true);
        $this->app->instance(ReplenishmentGuard::class, $guard);
        $this->app->forgetInstance(JxGenerationGuard::class);

        // These functions permit the production mutex wrapper to run on SQLite.
        // They intentionally do not claim to test concurrent MySQL locks.
        $this->db->getPdo()->sqliteCreateFunction('GET_LOCK', static fn ($key, $timeout) => 1, 2);
        $this->db->getPdo()->sqliteCreateFunction('RELEASE_LOCK', static fn ($key) => 1, 1);

        $this->createSchema();
        $this->db->table('items')->insert([
            'id' => 100, 'capacity_case' => 24, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sakemaru');
        parent::tearDown();
    }

    public static function cancellableManualCandidates(): array
    {
        return [
            'EOS confirmed' => ['EOS', 'CONFIRMED'],
            'FAX confirmed' => ['FAX', 'CONFIRMED'],
            'EOS executed without JX' => ['EOS', 'EXECUTED'],
            'FAX executed without JX' => ['FAX', 'EXECUTED'],
        ];
    }

    #[DataProvider('cancellableManualCandidates')]
    public function test_manual_candidate_without_jx_keeps_existing_cancellation_behavior(string $channel, string $status): void
    {
        $candidate = $this->candidate(1, ['order_channel' => $channel, 'status' => $status]);
        $this->schedule(1, 1);
        $this->schedule(2, 1, 'PARTIAL');
        $this->candidate(2);
        $this->schedule(3, 2);

        $deleted = app(OrderCancellationService::class)->cancelConfirmation($candidate, 99, '数量を修正');

        $this->assertSame(1, $deleted);
        $saved = WmsOrderCandidate::findOrFail(1);
        $this->assertSame(CandidateStatus::APPROVED, $saved->status);
        $this->assertNull($saved->wms_order_jx_document_id);
        $this->assertSame(99, (int) $saved->modified_by);
        $this->assertSame(72, (int) $saved->order_quantity);
        $this->assertSame([2, 3], $this->db->table('wms_order_incoming_schedules')->orderBy('id')->pluck('id')->all());
        $this->assertSame('CONFIRMED', $this->db->table('wms_order_candidates')->where('id', 2)->value('status'));
        $audit = $this->db->table('wms_order_candidate_audit_logs')->sole();
        $this->assertSame('confirmation_cancelled', $audit->action);
        $this->assertSame('数量を修正', $audit->reason);
    }

    public static function documentStates(): array
    {
        $cases = [];
        foreach (TransmissionDocumentStatus::cases() as $status) {
            $cases[$status->value] = [$status->value];
        }

        return $cases;
    }

    #[DataProvider('documentStates')]
    public function test_linked_jx_cannot_be_detached_and_shared_document_candidates_are_unchanged(string $status): void
    {
        $this->document($status);
        $candidate = $this->candidate(1, ['wms_order_jx_document_id' => 11]);
        $this->candidate(2, ['wms_order_jx_document_id' => 11]);
        $this->schedule(1, 1);
        $this->schedule(2, 2);
        $this->claim(1, 'PUBLISHED', 11);
        $this->claim(2, 'PUBLISHED', 11);

        $this->assertCancellationRejectedWithoutChanges($candidate, '「JX送信ファイル」');
    }

    public function test_stale_candidate_cannot_hide_a_newly_generated_jx_link(): void
    {
        $candidate = $this->candidate(1);
        $this->schedule(1, 1);
        $this->document('PENDING');
        $this->db->table('wms_order_candidates')->where('id', 1)->update(['wms_order_jx_document_id' => 11]);
        $this->claim(1, 'PUBLISHED', 11);
        $this->assertNull($candidate->wms_order_jx_document_id);

        $this->assertCancellationRejectedWithoutChanges($candidate, 'JXデータが生成済み');
    }

    public function test_stale_candidate_cannot_hide_a_no_longer_cancellable_status(): void
    {
        $candidate = $this->candidate(1);
        $this->schedule(1, 1);
        $this->db->table('wms_order_candidates')->where('id', 1)->update(['status' => 'APPROVED']);

        $this->assertCancellationRejectedWithoutChanges($candidate, 'この発注候補は確定取消できません');
    }

    public function test_building_claim_still_prevents_cancellation(): void
    {
        $candidate = $this->candidate(1);
        $this->schedule(1, 1);
        $this->claim(1, 'BUILDING');

        $this->assertCancellationRejectedWithoutChanges($candidate, 'JX生成中のため変更できません');
    }

    public function test_generated_automatic_order_still_requires_its_existing_stop_flow(): void
    {
        $candidate = $this->candidate(1);
        $this->schedule(1, 1);
        $this->db->table('wms_auto_order_run_lines')->insert([
            'id' => 1, 'order_candidate_id' => 1, 'generated_at' => '2026-10-18 10:00:00',
        ]);

        $this->assertCancellationRejectedWithoutChanges($candidate, '自動発注の内容変更は工程の照合・中止');
    }

    public function test_audit_write_failure_rolls_back_candidate_and_schedule_changes(): void
    {
        $candidate = $this->candidate(1);
        $this->schedule(1, 1);
        $before = $this->snapshot();
        $this->db->unprepared("CREATE TRIGGER reject_cancellation_audit BEFORE INSERT ON wms_order_candidate_audit_logs BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");

        try {
            app(OrderCancellationService::class)->cancelConfirmation($candidate, 99, '数量を修正');
            $this->fail('Audit failure must abort the cancellation.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('test audit failure', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_document_cancellation_allows_confirmation_cancellation_and_later_jx_generation_claim(): void
    {
        $this->document('PENDING');
        $this->candidate(1, ['wms_order_jx_document_id' => 11]);
        $this->candidate(2, ['wms_order_jx_document_id' => 11]);
        $this->schedule(1, 1);
        $this->schedule(2, 2);
        $this->claim(1, 'PUBLISHED', 11);
        $this->claim(2, 'PUBLISHED', 11);
        $this->db->table('wms_order_slip_number_assignments')->insert([
            'id' => 1, 'wms_order_jx_document_id' => 11, 'status' => 'ACTIVE',
            'slip_number' => 'TEST000001', 'order_candidate_ids' => '[1,2]',
        ]);

        $result = app(OrderTransmissionService::class)->cancelPendingJxDocumentAndRestoreCandidates(11);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['restored_count']);
        $this->assertSame('CANCELLED', $this->db->table('wms_order_jx_documents')->where('id', 11)->value('status'));
        $this->assertSame('CANCELLED', $this->db->table('wms_order_slip_number_assignments')->where('id', 1)->value('status'));
        $this->assertSame(0, $this->db->table('wms_order_incoming_schedules')->whereNotNull('slip_number')->count());
        $this->assertSame(2, $this->db->table('wms_order_generation_claims')->where('state', 'PUBLISHED')->count());

        $deleted = app(OrderCancellationService::class)->cancelConfirmation(WmsOrderCandidate::findOrFail(1), 99, '数量を修正');
        $this->assertSame(1, $deleted);
        $this->assertSame('APPROVED', $this->db->table('wms_order_candidates')->where('id', 1)->value('status'));
        $this->assertSame('CONFIRMED', $this->db->table('wms_order_candidates')->where('id', 2)->value('status'));
        $this->assertSame(1, $this->db->table('wms_order_incoming_schedules')->where('order_candidate_id', 2)->count());

        // Confirmation calculation is independently tested. Prepare its committed state
        // here to exercise the real generation guard after the actual cancellation flow.
        $this->db->table('wms_order_candidates')->where('id', 1)->update(['status' => 'CONFIRMED']);
        $guard = app(JxGenerationGuard::class);
        $token = $guard->acquire(collect([WmsOrderCandidate::findOrFail(1)]));
        try {
            $this->assertNotNull($token);
            $claim = $this->db->table('wms_order_generation_claims')->where('candidate_id', 1)->sole();
            $this->assertSame('BUILDING', $claim->state);
            $this->assertSame($token, $claim->token);
            $this->assertNull($claim->document_id);
            $this->assertSame('PUBLISHED', $this->db->table('wms_order_generation_claims')->where('candidate_id', 2)->value('state'));
        } finally {
            $guard->releaseUnpublished($token);
        }
    }

    private function assertCancellationRejectedWithoutChanges(WmsOrderCandidate $candidate, string $message): void
    {
        $before = $this->snapshot();
        try {
            app(OrderCancellationService::class)->cancelConfirmation($candidate, 99, '数量を修正');
            $this->fail('Cancellation must be rejected before changing any data.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $data = [];
        foreach (self::TABLES as $table) {
            $data[$table] = $this->db->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $data;
    }

    private function candidate(int $id, array $overrides = []): WmsOrderCandidate
    {
        $this->db->table('wms_order_candidates')->insert(array_replace([
            'id' => $id, 'warehouse_id' => 1, 'item_id' => 100,
            'contractor_id' => 1021, 'supplier_id' => 1021, 'batch_code' => 'TEST-CANCEL',
            'status' => 'CONFIRMED', 'order_channel' => 'EOS', 'order_quantity' => 72,
            'quantity_type' => 'PIECE', 'ordering_code' => '1234567890123',
            'expected_arrival_date' => '2026-10-19', 'purchase_unit_price' => 100,
            'lock_version' => 1,
        ], $overrides));

        return WmsOrderCandidate::findOrFail($id);
    }

    private function schedule(int $id, int $candidateId, string $status = 'PENDING'): void
    {
        $this->db->table('wms_order_incoming_schedules')->insert([
            'id' => $id, 'order_candidate_id' => $candidateId, 'status' => $status,
            'expected_quantity' => 72, 'received_quantity' => $status === 'PARTIAL' ? 24 : 0,
            'slip_number' => 'TEST000001',
        ]);
    }

    private function document(string $status): void
    {
        $this->db->table('wms_order_jx_documents')->insert(['id' => 11, 'status' => $status]);
    }

    private function claim(int $candidateId, string $state, ?int $documentId = null): void
    {
        $this->db->table('wms_order_generation_claims')->insert([
            'candidate_id' => $candidateId, 'state' => $state, 'document_id' => $documentId,
            'token' => 'original-generation', 'manifest_hash' => str_repeat('a', 64),
        ]);
    }

    private function createSchema(): void
    {
        $schema = $this->db->getSchemaBuilder();
        $schema->create('wms_order_candidates', function (Blueprint $table) {
            $table->id();
            foreach (['warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'order_quantity', 'lock_version', 'modified_by', 'wms_order_jx_document_id'] as $column) {
                $table->integer($column)->nullable();
            }
            foreach (['batch_code', 'status', 'order_channel', 'quantity_type', 'ordering_code', 'expected_arrival_date', 'purchase_unit_price', 'modified_at'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
        });
        $schema->create('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->id();
            $table->integer('order_candidate_id');
            $table->string('status');
            $table->integer('expected_quantity');
            $table->integer('received_quantity');
            $table->string('slip_number')->nullable();
            $table->timestamps();
        });
        $schema->create('wms_order_jx_documents', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        $schema->create('wms_order_generation_claims', function (Blueprint $table) {
            $table->id();
            $table->integer('candidate_id')->unique();
            $table->string('state');
            $table->string('token');
            $table->string('manifest_hash');
            $table->integer('document_id')->nullable();
            $table->timestamps();
        });
        $schema->create('wms_auto_order_run_lines', function (Blueprint $table) {
            $table->id();
            $table->integer('order_candidate_id');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
        $schema->create('wms_order_slip_number_assignments', function (Blueprint $table) {
            $table->id();
            $table->integer('wms_order_jx_document_id');
            $table->string('status');
            $table->string('slip_number');
            $table->text('order_candidate_ids');
            $table->timestamps();
        });
        $schema->create('wms_order_candidate_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('order_candidate_id');
            foreach (['batch_code', 'action', 'old_status', 'new_status', 'old_quantity', 'new_quantity', 'changes', 'reason', 'performed_by', 'performed_by_name', 'ip_address', 'user_agent'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
        });
        $schema->create('items', function (Blueprint $table) {
            $table->id();
            $table->integer('capacity_case');
            $table->boolean('is_active');
        });
    }
}
