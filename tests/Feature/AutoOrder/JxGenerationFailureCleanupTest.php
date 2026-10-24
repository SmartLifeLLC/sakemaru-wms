<?php

namespace Tests\Feature\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\User;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoReplenishment\JxGenerationGuard;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** Real generation orchestration/guard with memory DB, synthetic output and in-memory storage only. */
class JxGenerationFailureCleanupTest extends TestCase
{
    private Connection $db;

    private MockInterface $generator;

    private array $heldLocks = [];

    private array $releasedLocks = [];

    private array $storedFiles = [];

    private ?string $failStorageFilename = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wms.client' => 'hana',
            'cache.default' => 'array',
            'database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('sakemaru');
        $this->db = DB::connection('sakemaru');
        $this->assertSame('sqlite', $this->db->getDriverName());
        $this->assertSame(':memory:', $this->db->getDatabaseName());
        Http::preventStrayRequests();
        Log::spy();

        $installed = Mockery::mock(ReplenishmentGuard::class);
        $installed->shouldReceive('installed')->andReturn(true);
        $this->app->instance(ReplenishmentGuard::class, $installed);
        $this->app->forgetInstance(JxGenerationGuard::class);

        // Exercise the real mutex wrapper without claiming SQLite tests MySQL concurrency.
        $this->db->getPdo()->sqliteCreateFunction('GET_LOCK', function ($key, $timeout): int {
            $this->heldLocks[$key] = true;

            return 1;
        }, 2);
        $this->db->getPdo()->sqliteCreateFunction('RELEASE_LOCK', function ($key): int {
            unset($this->heldLocks[$key]);
            $this->releasedLocks[] = $key;

            return 1;
        }, 1);

        $this->generator = Mockery::mock(OrderFileGeneratorInterface::class);
        $this->generator->shouldReceive('getTransmissionContractorMapping')->andReturn([]);
        $this->app->instance(HanaOrderJXFileGenerator::class, $this->generator);

        $quantity = Mockery::mock(OrderOutputQuantityResolver::class);
        $quantity->shouldReceive('resolve')->andReturnUsing(fn ($candidate): array => [
            'ordering_code' => $candidate->ordering_code, 'display_capacity' => 24,
            'order_quantity' => $candidate->order_quantity, 'case_quantity' => 0,
            'piece_quantity' => $candidate->order_quantity,
        ]);
        $quantity->shouldReceive('sumOutputOrderQuantity')->andReturnUsing(fn (Collection $candidates): int => $candidates->sum('order_quantity'));
        $this->app->instance(OrderOutputQuantityResolver::class, $quantity);

        $disk = Mockery::mock();
        $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $content): bool {
            if ($this->failStorageFilename !== null && str_ends_with($path, '/'.$this->failStorageFilename)) {
                return false;
            }
            $this->storedFiles[$path] = $content;

            return true;
        });
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->actingAs((new User)->forceFill(['id' => 77, 'name' => '生成テスト担当']));
        $this->createSchema();
        $this->db->table('items')->insert(['id' => 100, 'code' => '143024', 'name' => 'テスト商品', 'capacity_case' => 24, 'is_active' => 1]);
        $this->db->table('client_settings')->insert(['id' => 1, 'client_id' => 6, 'system_date' => '2026-10-18']);
        ClientSetting::clearCachedSetting(6);
    }

    protected function tearDown(): void
    {
        ClientSetting::clearCachedSetting(6);
        DB::purge('sakemaru');
        parent::tearDown();
    }

    public function test_existing_claim_rejection_returns_candidate_id_error_without_generation_or_release_of_old_claim(): void
    {
        $candidate = $this->candidate(37);
        $this->claim(37, 'old-worker-token');
        $before = $this->db->table('wms_order_generation_claims')->first();
        $this->generator->shouldNotReceive('generate');

        $result = $this->generate(collect([$candidate]));

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['files']);
        $this->assertSame(0, $result['total_orders']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('発注候補ID 37', $result['errors'][0]);
        $this->assertStringContainsString('JX生成中または生成履歴があります', $result['errors'][0]);
        $this->assertEquals($before, $this->db->table('wms_order_generation_claims')->first());
        $this->assertSame([], $this->storedFiles);
        $this->assertSame(0, $this->db->table('wms_order_jx_documents')->count());
        $this->assertSame(['auto-jx-generation:37'], $this->releasedLocks);
        $this->assertNoHeldLocksOrHttpRequests();
    }

    public function test_generator_throwable_releases_only_this_attempts_unpublished_claim(): void
    {
        $candidate = $this->candidate(1);
        $this->claim(99, 'unrelated-worker-token');
        $this->generator->shouldReceive('generate')->once()->andThrow(new \Error('生成処理テストエラー'));

        $result = $this->generate(collect([$candidate]));

        $this->assertFalse($result['success']);
        $this->assertSame(['生成処理テストエラー'], $result['errors']);
        $this->assertSame([], $result['files']);
        $this->assertSame([99], $this->db->table('wms_order_generation_claims')->pluck('candidate_id')->all());
        $this->assertSame([], $this->storedFiles);
        $this->assertSame(['auto-jx-generation:1'], $this->releasedLocks);
        $this->assertNoHeldLocksOrHttpRequests();
    }

    public function test_error_logging_exception_still_releases_claim_and_propagates_logging_failure(): void
    {
        $candidate = $this->candidate(1);
        $this->generator->shouldReceive('generate')->once()->andThrow(new RuntimeException('ファイル生成失敗'));
        Log::shouldReceive('error')->once()->with('Order file generation failed', Mockery::type('array'))
            ->andThrow(new RuntimeException('ログ出力失敗'));

        try {
            $this->generate(collect([$candidate]));
            $this->fail('The logging exception must propagate after cleanup.');
        } catch (RuntimeException $e) {
            $this->assertSame('ログ出力失敗', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('wms_order_generation_claims')->count());
        $this->assertSame(0, $this->db->table('wms_order_jx_documents')->count());
        $this->assertSame(['auto-jx-generation:1'], $this->releasedLocks);
        $this->assertNoHeldLocksOrHttpRequests();
    }

    public function test_success_keeps_published_claim_and_document_while_releasing_mutex(): void
    {
        $candidate = $this->candidate(1);
        $this->generator->shouldReceive('generate')->once()->andReturn([$this->file(1021, 'first.dat')]);

        $result = $this->generate(collect([$candidate]));

        $this->assertTrue($result['success'], implode(' / ', $result['errors']));
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['total_orders']);
        $this->assertCount(1, $result['files']);
        $this->assertPublished(1, (int) $result['files'][0]['document_id']);
        $this->assertCount(2, $this->storedFiles);
        $this->assertSame(['auto-jx-generation:1'], $this->releasedLocks);
        $this->assertNoHeldLocksOrHttpRequests();
    }

    public function test_later_file_failure_preserves_published_document_and_cleans_only_unpublished_claim(): void
    {
        $first = $this->candidate(1, 1021);
        $second = $this->candidate(2, 1330);
        $this->generator->shouldReceive('generate')->once()->andReturn([
            $this->file(1021, 'first.dat'), $this->file(1330, 'second.dat'),
        ]);
        $this->failStorageFilename = 'second.dat';

        $result = $this->generate(collect([$first, $second]));

        $this->assertFalse($result['success']);
        $this->assertSame(['JXファイルを保存できませんでした。'], $result['errors']);
        $this->assertSame(1, $result['total_orders']);
        $this->assertCount(1, $result['files']);
        $this->assertPublished(1, (int) $result['files'][0]['document_id']);
        $this->assertNull($this->db->table('wms_order_candidates')->where('id', 2)->value('wms_order_jx_document_id'));
        $this->assertSame([1], $this->db->table('wms_order_generation_claims')->pluck('candidate_id')->all());
        $this->assertSame(1, $this->db->table('wms_order_jx_documents')->count());
        $this->assertSame(1, $this->db->table('wms_order_data_files')->count());
        $this->assertSame(['auto-jx-generation:1', 'auto-jx-generation:2'], $this->releasedLocks);
        $this->assertNoHeldLocksOrHttpRequests();
    }

    private function generate(Collection $candidates): array
    {
        $method = new ReflectionMethod(OrderTransmissionService::class, 'doGenerateOrderFilesWithProgress');

        return $method->invoke(app(OrderTransmissionService::class), 'JX-FAILURE-TEST', $candidates, TransmissionDocumentStatus::PENDING, true, null, false);
    }

    private function assertPublished(int $candidateId, int $documentId): void
    {
        $claim = $this->db->table('wms_order_generation_claims')->where('candidate_id', $candidateId)->sole();
        $this->assertSame('PUBLISHED', $claim->state);
        $this->assertSame($documentId, (int) $claim->document_id);
        $this->assertSame($documentId, (int) $this->db->table('wms_order_candidates')->where('id', $candidateId)->value('wms_order_jx_document_id'));
        $document = $this->db->table('wms_order_jx_documents')->where('id', $documentId)->sole();
        $this->assertSame('PENDING', $document->status);
        $this->assertSame(hash('sha256', $this->storedFiles[$document->file_path]), $document->content_sha256);
    }

    private function assertNoHeldLocksOrHttpRequests(): void
    {
        $this->assertSame([], $this->heldLocks);
        Http::assertNothingSent();
    }

    private function candidate(int $id, int $contractorId = 1021): WmsOrderCandidate
    {
        $this->db->table('wms_order_candidates')->insert([
            'id' => $id, 'warehouse_id' => 1, 'item_id' => 100, 'contractor_id' => $contractorId,
            'supplier_id' => $contractorId, 'batch_code' => 'JX-FAILURE-TEST', 'status' => 'CONFIRMED',
            'order_quantity' => 24, 'quantity_type' => 'PIECE', 'ordering_code' => '1234567890123',
            'expected_arrival_date' => '2026-10-19', 'purchase_unit_price' => 100, 'lock_version' => 1,
        ]);

        return WmsOrderCandidate::findOrFail($id)
            ->setRelation('warehouse', new Warehouse(['id' => 1, 'code' => '1', 'name' => 'テスト倉庫']))
            ->setRelation('contractor', new Contractor(['id' => $contractorId, 'code' => (string) $contractorId, 'name' => 'テスト発注先']));
    }

    private function claim(int $candidateId, string $token): void
    {
        $this->db->table('wms_order_generation_claims')->insert([
            'candidate_id' => $candidateId, 'token' => $token, 'state' => 'BUILDING',
            'manifest_hash' => str_repeat('a', 64), 'created_at' => '2026-10-18 09:00:00', 'updated_at' => '2026-10-18 09:00:00',
        ]);
    }

    private function file(int $contractorId, string $filename): array
    {
        return [
            'contractor_id' => $contractorId, 'filename' => $filename, 'content' => str_pad('A', 128),
            'record_count' => 1, 'order_count' => 1, 'encoding' => 'SJIS',
        ];
    }

    private function createSchema(): void
    {
        $this->db->statement('CREATE TABLE wms_order_candidates (id INTEGER PRIMARY KEY, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, batch_code TEXT, status TEXT, order_quantity INTEGER, quantity_type TEXT, ordering_code TEXT, expected_arrival_date TEXT, purchase_unit_price INTEGER, lock_version INTEGER, wms_order_jx_document_id INTEGER, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE wms_order_generation_claims (id INTEGER PRIMARY KEY AUTOINCREMENT, candidate_id INTEGER UNIQUE, state TEXT, token TEXT, manifest_hash TEXT, document_id INTEGER, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE wms_auto_order_run_lines (id INTEGER PRIMARY KEY, order_candidate_id INTEGER)');
        $this->db->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, code TEXT, name TEXT, capacity_case INTEGER, is_active INTEGER)');
        $this->db->statement('CREATE TABLE client_settings (id INTEGER PRIMARY KEY, client_id INTEGER, system_date TEXT)');
        $this->db->statement('CREATE TABLE wms_contractor_settings (id INTEGER PRIMARY KEY, contractor_id INTEGER, order_mail TEXT)');
        $this->db->statement('CREATE TABLE wms_order_jx_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_code TEXT, wms_order_jx_setting_id INTEGER, warehouse_id INTEGER, contractor_id INTEGER, order_date TEXT, expected_arrival_date TEXT, document_type TEXT, status TEXT, file_path TEXT, csv_path TEXT, file_size INTEGER, content_sha256 TEXT, record_count INTEGER, order_count INTEGER, encoding TEXT, created_by INTEGER, created_by_name TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE wms_order_data_files (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_code TEXT, created_by INTEGER, created_by_name TEXT, warehouse_id INTEGER, contractor_id INTEGER, candidate_ids TEXT, order_channel TEXT, show_eos_stamp INTEGER, order_date TEXT, expected_arrival_date TEXT, file_path TEXT, file_size INTEGER, order_count INTEGER, total_quantity INTEGER, is_mail_order INTEGER, is_test INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    }
}
