<?php

namespace Tests\Feature\Api;

use App\Models\Sakemaru\User;
use App\Services\Distribution\DistributionBatchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class DistributionBatchTest extends TestCase
{
    private User $actor;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $name = DB::connection('sakemaru')->getDatabaseName();
        self::assertMatchesRegularExpression('/(?:_test(?:_|$)|_testing(?:_|$))/', $name, 'Use only a dedicated test database.');
        DB::connection('sakemaru')->beginTransaction();
        Storage::fake('local');
        $this->actor = User::query()->firstOrFail();
        config(['app.client_id' => $this->actor->client_id]);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('check')->andReturn(true);
        $this->app->instance(PermissionService::class, $permissions);
        $this->actingAs($this->actor, 'web');
    }

    protected function tearDown(): void
    {
        while (DB::connection('sakemaru')->transactionLevel() > 0) {
            DB::connection('sakemaru')->rollBack();
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function batch(string $name = '分配X'): int
    {
        return $this->postJson('/api/distribution/batches', ['name' => $name, 'shipping_date' => '2026-11-01',
            'responsible_department' => 'warehouse', 'execution_id' => (string) Str::uuid()])->assertOk()->json('result.data.id');
    }

    private function save(int $batch, int $revision, array $rows, array $extra = [])
    {
        return $this->putJson('/api/distribution/batches/'.$batch.'/rows', array_replace([
            'mode' => 'allocation', 'replace' => true, 'rows' => $rows, 'expected_revision' => $revision, 'deleted_row_ids' => [],
        ], $extra));
    }

    private function spreadsheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'distribution-batch-http-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->files[] = $path;

        return new UploadedFile($path, '数量.xlsx', null, null, true);
    }

    public function test_department_is_required_on_registration_and_can_be_filtered(): void
    {
        $data = ['name' => '担当部署テスト', 'shipping_date' => '2026-11-01', 'execution_id' => (string) Str::uuid()];
        $this->postJson('/api/distribution/batches', $data)->assertUnprocessable()->assertJsonValidationErrors('responsible_department');
        $this->postJson('/api/distribution/batches', $data + ['responsible_department' => 'other'])->assertUnprocessable();
        $warehouse = $this->postJson('/api/distribution/batches', $data + ['responsible_department' => 'warehouse'])
            ->assertOk()->assertJsonPath('result.data.responsible_department_label', '倉庫担当')->json('result.data.id');
        $data['execution_id'] = (string) Str::uuid();
        $headquarters = $this->postJson('/api/distribution/batches', $data + ['responsible_department' => 'headquarters'])
            ->assertOk()->assertJsonPath('result.data.responsible_department_label', '本部担当')->json('result.data.id');
        $legacy = $this->batch('担当部署テスト・旧データ');
        DB::connection('sakemaru')->table('wms_distribution_batches')->where('id', $legacy)->update(['responsible_department' => null]);
        foreach (['warehouse' => $warehouse, 'headquarters' => $headquarters, 'unassigned' => $legacy] as $department => $id) {
            $rows = $this->getJson('/api/distribution/batches?search=担当部署テスト&responsible_department='.$department)->assertOk()->json('result.data.data');
            self::assertSame([$id], array_column($rows, 'id'));
        }
        $this->getJson('/api/distribution/batches?responsible_department=other')->assertUnprocessable();
        $this->getJson('/api/distribution/batches/'.$legacy)->assertOk()->assertJsonPath('result.data.responsible_department_label', '未設定');
    }

    public function test_department_changes_are_audited_and_cannot_overwrite_a_concurrent_edit(): void
    {
        $batch = $this->batch();
        $data = ['name' => '担当変更', 'shipping_date' => '2026-11-01', 'responsible_department' => 'headquarters', 'expected_revision' => 0];
        $this->patchJson('/api/distribution/batches/'.$batch, $data)->assertOk()->assertJsonPath('result.data.revision', 1)
            ->assertJsonPath('result.data.responsible_department', 'headquarters');
        $event = DB::connection('sakemaru')->table('wms_distribution_batch_events')->where('distribution_batch_id', $batch)->latest('id')->first();
        $changes = json_decode($event->changes, true);
        self::assertSame('warehouse', $changes['header']['before']['responsible_department']);
        self::assertSame('headquarters', $changes['header']['after']['responsible_department']);
        $this->patchJson('/api/distribution/batches/'.$batch, array_replace($data, ['responsible_department' => 'warehouse']))->assertStatus(409);
        $this->patchJson('/api/distribution/batches/'.$batch, array_replace($data, ['responsible_department' => 'invalid', 'expected_revision' => 1]))->assertUnprocessable();
        unset($data['responsible_department']);
        $data['expected_revision'] = 1;
        $this->patchJson('/api/distribution/batches/'.$batch, $data)->assertOk()->assertJsonPath('result.data.responsible_department', 'headquarters');
        self::assertSame(0, DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $batch)->count());
    }

    public function test_existing_unassigned_department_can_archive_reopen_and_be_assigned(): void
    {
        $batch = $this->batch();
        DB::connection('sakemaru')->table('wms_distribution_batches')->where('id', $batch)->update(['responsible_department' => null]);
        $data = ['name' => '旧分配', 'shipping_date' => '2026-11-01', 'responsible_department' => null, 'expected_revision' => 0, 'archive' => true];
        $this->patchJson('/api/distribution/batches/'.$batch, $data)->assertOk()->assertJsonPath('result.data.archived', true)->assertJsonPath('result.data.responsible_department', null);
        $this->patchJson('/api/distribution/batches/'.$batch, array_replace($data, ['expected_revision' => 1, 'archive' => false]))->assertOk()->assertJsonPath('result.data.archived', false);
        $this->patchJson('/api/distribution/batches/'.$batch, array_replace($data, ['expected_revision' => 2, 'archive' => false, 'responsible_department' => 'warehouse']))
            ->assertOk()->assertJsonPath('result.data.responsible_department_label', '倉庫担当');
    }

    public function test_separate_batches_save_independently_and_stale_same_batch_cannot_overwrite(): void
    {
        $x = $this->batch();
        $other = $this->actor->replicate();
        $other->id = 9900000099;
        $other->code = 9900000099;
        $other->name = '検証担当B';
        $other->email = 'distribution-b@example.test';
        $other->save();
        $this->actingAs($other, 'web');
        $y = $this->batch('分配Y');
        $this->actingAs($this->actor, 'web');
        $this->save($x, 0, [['id' => 'x-row', 'productCode' => '100567', 'alloc_01' => 12]])->assertOk()->assertJsonPath('result.data.revision', 1);
        $this->actingAs($other, 'web');
        $this->save($y, 0, [['id' => 'y-row', 'productCode' => '100567', 'alloc_01' => 24]])->assertOk()->assertJsonPath('result.data.revision', 1);
        $this->save($x, 0, [['id' => 'x-row', 'alloc_01' => 99]])->assertStatus(409);
        $this->getJson('/api/distribution/batches/'.$x.'/rows')->assertOk()->assertJsonPath('result.data.allocation.0.alloc_01', 12);
        $this->getJson('/api/distribution/batches/'.$y.'/rows')->assertOk()->assertJsonPath('result.data.allocation.0.alloc_01', 24);
        $this->save($x, 1, [])->assertOk();
        self::assertSame(1, DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $y)->whereNull('deleted_at')->count());
    }

    public function test_foreign_rows_wrong_warehouse_and_old_editor_are_rejected(): void
    {
        $x = $this->batch();
        $y = $this->batch();
        $this->save($x, 0, [['id' => 'foreign-row', 'alloc_01' => 12]])->assertOk();
        $this->save($y, 0, [['id' => 'foreign-row', 'alloc_01' => 88]])->assertForbidden();
        $this->putJson('/api/distribution/rows', ['mode' => 'allocation', 'replace' => true, 'rows' => [], 'expected_revision' => 0])->assertStatus(409);
        DB::connection('sakemaru')->table('wms_distribution_batches')->where('id', $x)->update(['warehouse_id' => 1]);
        $this->getJson('/api/distribution/batches/'.$x.'/rows')->assertNotFound();
    }

    public function test_store_limit_can_recover_by_product_search_without_losing_the_batch_revision(): void
    {
        $batch = $this->batch();
        $marker = 'limit-recovery-'.Str::uuid();
        $now = now();
        foreach (array_chunk(range(1, 1001), 200) as $numbers) {
            DB::connection('sakemaru')->table('wms_distribution_rows')->insert(array_map(fn ($number) => [
                'client_id' => $this->actor->client_id, 'warehouse_id' => 91, 'mode' => 'allocation',
                'distribution_batch_id' => $batch, 'row_id' => $marker.'-'.$number, 'sort_order' => $number,
                'product_code' => '100567',
                'row_data' => json_encode(['id' => $marker.'-'.$number, 'name' => $marker.($number === 1 ? '-target' : '-other'),
                    'productCode' => '100567', 'alloc_01' => 3, 'checked' => false]),
                'created_at' => $now, 'updated_at' => $now,
            ], $numbers));
        }
        $url = '/api/distribution/rows?context=store&mode=allocation&product_search=';
        $this->getJson($url.$marker)->assertStatus(409)->assertJsonPath('code', 'DISTRIBUTION_ROWS_LIMIT_EXCEEDED');
        $this->getJson($url.$marker.'-other')->assertOk()->assertJsonCount(1000, 'result.data.allocation');
        $rows = $this->getJson($url.$marker.'-target')->assertOk()->json('result.data.allocation');
        self::assertCount(1, $rows);
        self::assertSame($batch, $rows[0]['distributionBatchId']);
        self::assertSame(0, $rows[0]['distributionBatchRevision']);
        self::assertSame(3, $rows[0]['alloc_01']);
        $this->getJson($url.$marker.'-missing')->assertOk()->assertJsonCount(0, 'result.data.allocation');
    }

    public function test_store_snapshot_cannot_overwrite_an_update_between_row_and_parent_reads(): void
    {
        $batch = $this->batch();
        $rowId = 'snapshot-'.Str::uuid();
        $marker = 'snapshot-'.Str::uuid();
        $row = ['id' => $rowId, 'productCode' => '100567', 'name' => $marker, 'alloc_01' => 3, 'checked' => false];
        $this->save($batch, 0, [$row])->assertOk();
        $armed = true;
        // Interleave a committed-state change after SQL returns rows, before Eloquent hydrates them.
        DB::listen(function ($query) use (&$armed, $batch, $rowId, $row): void {
            if (! $armed || ! str_starts_with($query->sql, 'select ') || ! str_contains($query->sql, 'from `wms_distribution_rows`') || str_contains($query->sql, 'count(*)')) {
                return;
            }
            $armed = false;
            $changed = array_replace($row, ['alloc_01' => 9]);
            DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $batch)->where('row_id', $rowId)
                ->update(['row_data' => json_encode($changed)]);
            DB::connection('sakemaru')->table('wms_distribution_batches')->where('id', $batch)->update(['revision' => 2]);
        });
        $data = $this->getJson('/api/distribution/rows?context=store&mode=allocation&confirm_status=all&product_search='.$marker)
            ->assertOk()->json('result.data.allocation');
        self::assertFalse($armed);
        $returned = collect($data)->firstWhere('id', $rowId);
        self::assertNotNull($returned);
        self::assertSame(3, $returned['alloc_01']);
        self::assertSame(1, $returned['distributionBatchRevision']);
        $returned['memo'] = '備考だけの変更';
        $this->save($batch, $returned['distributionBatchRevision'], [$returned], ['replace' => false])->assertStatus(409);
        $saved = DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $batch)->where('row_id', $rowId)->value('row_data');
        self::assertSame(9, json_decode($saved, true)['alloc_01']);
    }

    public function test_excel_overwrites_only_present_store_columns_and_preserves_other_rows_and_metadata(): void
    {
        $x = $this->batch();
        $item = DB::connection('sakemaru')->table('items')->where('code', 100567)->first();
        $this->save($x, 0, [['id' => 'existing', 'itemId' => $item->id, 'productCode' => '100567',
            'alloc_01' => 10, 'alloc_02' => 20, 'alloc_03' => 30, 'wish_01' => 9, 'memo' => '維持する備考'],
            ['id' => 'keep-other', 'productCode' => '100568', 'alloc_01' => 4]])->assertOk();
        $preview = $this->post('/api/distribution/batches/'.$x.'/excel/preview', ['file' => $this->spreadsheet([
            ['単品ＣＤ', '本店', '二宮'], [100567, '３', null],
        ]), 'quantity_unit' => 'piece', 'store_mode' => 'name'], ['Accept' => 'application/json'])->assertOk()->json('result.data');
        self::assertSame([], $preview['errors']);
        self::assertSame(33, $preview['rows'][0]['after_total']);
        $path = '/api/distribution/batches/'.$x.'/excel/commit';
        $this->postJson($path, ['token' => $preview['token'], 'acknowledge_warnings' => true])->assertOk()->assertJsonPath('result.data.update_count', 1);
        $this->postJson($path, ['token' => $preview['token'], 'acknowledge_warnings' => true])->assertOk();
        $saved = DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $x)->where('row_id', 'existing')->first();
        $data = json_decode($saved->row_data, true);
        self::assertContains('02', $data['visibleDestinationKeys']);
        self::assertSame([3, 0, 30, 9, '維持する備考'], [$data['alloc_01'], $data['alloc_02'], $data['alloc_03'], $data['wish_01'], $data['memo']]);
        self::assertSame(1, DB::connection('sakemaru')->table('wms_distribution_imports')->where('distribution_batch_id', $x)->count());
        self::assertSame(2, DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $x)->whereNull('deleted_at')->count());
    }

    public function test_changed_revision_and_generated_rows_prevent_import_and_date_changes(): void
    {
        $x = $this->batch();
        $preview = $this->post('/api/distribution/batches/'.$x.'/excel/preview', ['file' => $this->spreadsheet([
            ['単品CD', '本店'], [100567, 3],
        ]), 'quantity_unit' => 'piece', 'store_mode' => 'name'], ['Accept' => 'application/json'])->assertOk()->json('result.data');
        $this->save($x, 0, [['id' => 'row', 'productCode' => '100567', 'warehouseTransferGenerated' => true]])->assertOk();
        $this->postJson('/api/distribution/batches/'.$x.'/excel/commit', ['token' => $preview['token'], 'acknowledge_warnings' => true])->assertStatus(409);
        $this->patchJson('/api/distribution/batches/'.$x, ['name' => '変更', 'shipping_date' => '2026-11-02', 'note' => '', 'expected_revision' => 1])->assertStatus(422);
        $this->deleteJson('/api/distribution/batches/'.$x, ['expected_revision' => 1])->assertStatus(422);
    }

    public function test_legacy_migration_retains_business_data_and_does_not_include_direct_rows(): void
    {
        $db = DB::connection('sakemaru');
        $timestamp = '2026-10-01 12:00:00';
        foreach (['allocation', 'direct'] as $mode) {
            $db->table('wms_distribution_rows')->insert(['client_id' => $this->actor->client_id, 'warehouse_id' => 91,
                'mode' => $mode, 'row_id' => 'legacy-'.$mode, 'sort_order' => 37, 'business_key' => sha1($mode),
                'row_data' => json_encode(['alloc_01' => 12]), 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        }
        $service = app(DistributionBatchService::class);
        $service->migrateLegacy();
        self::assertSame(0, $db->table('wms_distribution_batches')->where('is_legacy', true)->count());
        $service->migrateLegacy(true);
        $service->migrateLegacy(true);
        self::assertSame(1, $db->table('wms_distribution_batches')->where('is_legacy', true)->count());
        $saved = $db->table('wms_distribution_rows')->where('row_id', 'legacy-allocation')->first();
        self::assertNotNull($saved->distribution_batch_id);
        self::assertSame([$timestamp, $timestamp, 37, sha1('allocation')], [$saved->created_at, $saved->updated_at, $saved->sort_order, $saved->business_key]);
        self::assertNull($db->table('wms_distribution_rows')->where('row_id', 'legacy-direct')->value('distribution_batch_id'));
    }

    public function test_archive_reopen_malformed_input_and_generation_snapshot_guards(): void
    {
        $x = $this->batch();
        $this->save($x, 0, [['id' => 'guard-row', 'productCode' => '100567', 'alloc_01' => 12]])->assertOk();
        $this->putJson('/api/distribution/batches/'.$x.'/rows', ['rows' => ['bad'], 'expected_revision' => 1])->assertUnprocessable();
        foreach (['warehouse-transfers', 'stock-transfer-slips'] as $endpoint) {
            $this->postJson('/api/distribution/batches/'.$x.'/'.$endpoint, ['expected_revision' => 0, 'process_date' => '2026-11-01', 'check_only' => true])->assertStatus(409);
            $this->postJson('/api/distribution/batches/'.$x.'/'.$endpoint, ['expected_revision' => 1, 'process_date' => '2026-11-02', 'check_only' => true])->assertStatus(409);
        }
        $header = ['name' => '終了試験', 'shipping_date' => '2026-11-01', 'expected_revision' => 1, 'archive' => true];
        $this->patchJson('/api/distribution/batches/'.$x, $header)->assertOk()->assertJsonPath('result.data.archived', true);
        $this->save($x, 2, [['id' => 'guard-row', 'alloc_01' => 99]])->assertUnprocessable();
        $this->getJson('/api/distribution/batches/'.$x.'/rows')->assertOk()->assertJsonPath('result.data.allocation.0.alloc_01', 12);
        $this->patchJson('/api/distribution/batches/'.$x, array_replace($header, ['expected_revision' => 2, 'archive' => false]))->assertOk();
        $this->save($x, 3, [['id' => 'guard-row', 'alloc_01' => 13]])->assertOk();
    }

    public function test_registration_is_idempotent_and_deleted_parent_cannot_be_reused(): void
    {
        $data = ['name' => '二重登録', 'shipping_date' => '2026-11-01', 'responsible_department' => 'warehouse', 'execution_id' => (string) Str::uuid()];
        $x = $this->postJson('/api/distribution/batches', $data)->assertOk()->json('result.data.id');
        $this->postJson('/api/distribution/batches', $data)->assertOk()->assertJsonPath('result.data.id', $x);
        $this->deleteJson('/api/distribution/batches/'.$x, ['expected_revision' => 0])->assertOk();
        $this->postJson('/api/distribution/batches', $data)->assertStatus(409);
        $this->getJson('/api/distribution/batches/'.$x.'/rows')->assertNotFound();
    }

    public function test_import_exception_rolls_back_all_rows_revision_and_history(): void
    {
        $x = $this->batch();
        $preview = $this->post('/api/distribution/batches/'.$x.'/excel/preview', ['file' => $this->spreadsheet([
            ['単品CD', '本店'], [100567, 3], [100568, 5],
        ]), 'quantity_unit' => 'piece', 'store_mode' => 'name'], ['Accept' => 'application/json'])->assertOk()->json('result.data');
        self::assertSame([], $preview['errors']);
        self::assertSame(2, $preview['new_count']);
        \App\Models\WmsDistributionRow::creating(function ($row) {
            if ($row->product_code === '100568') {
                throw new \RuntimeException('Injected second-row failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/distribution/batches/'.$x.'/excel/commit', ['token' => $preview['token'], 'acknowledge_warnings' => true]);
            self::fail('The import should fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected second-row failure', $e->getMessage());
        }
        self::assertSame(0, DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $x)->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_distribution_imports')->where('distribution_batch_id', $x)->count());
        self::assertSame(0, (int) DB::connection('sakemaru')->table('wms_distribution_batches')->where('id', $x)->value('revision'));
    }

    public function test_missing_permission_rejects_batch_registration_and_mutation(): void
    {
        $x = $this->batch();
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('check')->andReturn(false);
        $this->app->instance(PermissionService::class, $permissions);
        $this->getJson('/api/distribution/batches')->assertForbidden();
        $this->postJson('/api/distribution/batches', ['name' => '権限なし'])->assertForbidden();
        $this->save($x, 0, [])->assertForbidden();
    }

    public function test_generation_receipt_protects_rows_before_any_browser_followup_save(): void
    {
        $x = $this->batch();
        $item = DB::connection('sakemaru')->table('items')->where('code', 100567)->first();
        $this->save($x, 0, [['id' => 'receipt-row', 'itemId' => $item->id, 'productCode' => '100567', 'deliveryCourseId' => 1, 'alloc_01' => 12]])->assertOk();
        $service = Mockery::mock(\App\Services\Distribution\DistributionStockTransferSlipService::class);
        $service->shouldReceive('create')->once()->andReturn(['queues' => [['queue_id' => 99902801]], 'row_ids' => ['receipt-row']]);
        $this->app->instance(\App\Services\Distribution\DistributionStockTransferSlipService::class, $service);
        $this->postJson('/api/distribution/batches/'.$x.'/warehouse-transfers', [
            'expected_revision' => 1, 'process_date' => '2026-11-01', 'rows' => [[
                'row_id' => 'receipt-row', 'distribution_business_key' => DB::connection('sakemaru')->table('wms_distribution_rows')->where('distribution_batch_id', $x)->value('business_key'), 'item_id' => $item->id, 'product_code' => '100567', 'delivery_course_id' => 1,
                'allocations' => [['destination_id' => 1, 'destination_key' => '01', 'quantity' => 12]],
            ]],
        ])->assertOk()->assertJsonPath('result.data.revision', 2);
        $this->getJson('/api/distribution/batches/'.$x.'/rows')->assertOk()->assertJsonPath('result.data.allocation.0.warehouseTransferGenerated', true);
        $this->save($x, 1, [['id' => 'receipt-row', 'alloc_01' => 99]])->assertStatus(409);
        $this->save($x, 2, [['id' => 'receipt-row', 'alloc_01' => 99]])->assertOk();
        $this->getJson('/api/distribution/batches/'.$x.'/rows')->assertOk()->assertJsonPath('result.data.allocation.0.alloc_01', 12);
    }
}
