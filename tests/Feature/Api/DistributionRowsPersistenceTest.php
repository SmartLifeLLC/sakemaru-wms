<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\DistributionProductController;
use App\Models\Sakemaru\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class DistributionRowsPersistenceTest extends TestCase
{
    public function test_stale_revision_is_rejected_and_partial_save_keeps_unloaded_rows(): void
    {
        $originalClientId = config('app.client_id');

        try {
            $ready = Schema::connection('sakemaru')->hasTable('wms_distribution_rows')
                && Schema::connection('sakemaru')->hasTable('wms_distribution_revisions');
        } catch (\Throwable $exception) {
            $this->skipLocallyOrFailInCi('The dedicated Sakemaru test database is unavailable: '.$exception->getMessage());
        }

        if (! $ready) {
            $this->skipLocallyOrFailInCi('Distribution persistence tables are not migrated on the test database.');
        }

        $connection = DB::connection('sakemaru');
        $connection->beginTransaction();

        try {
            $warehouse = $connection
                ->table('warehouses')
                ->where('code', '91')
                ->where('is_active', true)
                ->first(['id', 'client_id']);

            if (! $warehouse) {
                $this->skipLocallyOrFailInCi('The dedicated test database has no active warehouse 91.');
            }

            $destinationWarehouse = $connection
                ->table('warehouses')
                ->where('is_active', true)
                ->where('id', '<>', (int) $warehouse->id)
                ->first(['id', 'code']);
            if (! $destinationWarehouse) {
                $this->skipLocallyOrFailInCi('The dedicated test database has no active destination warehouse.');
            }

            $clientId = 2_000_000_000 + random_int(1, 100_000_000);
            $warehouseId = (int) $warehouse->id;
            $connection->table('warehouses')
                ->where('id', $warehouseId)
                ->update(['client_id' => $clientId]);
            $connection->table('warehouses')
                ->where('id', (int) $destinationWarehouse->id)
                ->update(['client_id' => $clientId]);
            $destinationKey = trim((string) $destinationWarehouse->code);
            if ($destinationKey === '') {
                $destinationKey = (string) $destinationWarehouse->id;
            } elseif (ctype_digit($destinationKey) && strlen($destinationKey) < 2) {
                $destinationKey = str_pad($destinationKey, 2, '0', STR_PAD_LEFT);
            }
            config(['app.client_id' => $clientId]);
            $this->assertPersistenceBehavior($clientId, $warehouseId, $destinationKey);
            $this->assertLegacyNegativeQuantityDoesNotBlockOtherRows($clientId, $warehouseId, $destinationKey);
            $this->assertFetchLimitBehavior($clientId, $warehouseId);
        } finally {
            $connection->rollBack();
            config(['app.client_id' => $originalClientId]);
        }
    }

    private function assertLegacyNegativeQuantityDoesNotBlockOtherRows(int $clientId, int $warehouseId, string $destinationKey): void
    {
        $connection = DB::connection('sakemaru');
        $legacyRowId = 'legacy-negative-'.Str::uuid();
        $normalRowId = 'normal-after-legacy-'.Str::uuid();
        $legacyData = [
            'id' => $legacyRowId,
            'productCode' => 'LEGACY-NEGATIVE',
            'wish_'.$destinationKey => -1,
        ];

        $connection->table('wms_distribution_rows')->insert([
            'client_id' => $clientId,
            'mode' => 'allocation',
            'warehouse_id' => $warehouseId,
            'row_id' => $legacyRowId,
            'sort_order' => 0,
            'business_key' => sha1($legacyRowId),
            'product_code' => 'LEGACY-NEGATIVE',
            'row_data' => json_encode($legacyData, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionService = Mockery::mock(PermissionService::class);
        $permissionService->shouldReceive('check')->andReturnTrue();
        $response = app(DistributionProductController::class)->saveDistributionRows(
            $this->request($this->user(910004, $clientId, $warehouseId), [
                'mode' => 'allocation',
                'replace' => false,
                'expected_revision' => 0,
                'deleted_row_ids' => [],
                'rows' => [
                    $legacyData,
                    ['id' => $normalRowId, 'productCode' => 'NORMAL', 'wish_'.$destinationKey => 1],
                ],
            ]),
            $permissionService
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($connection->table('wms_distribution_rows')
            ->where('client_id', $clientId)
            ->where('mode', 'allocation')
            ->where('warehouse_id', $warehouseId)
            ->where('row_id', $normalRowId)
            ->exists());
    }

    private function assertPersistenceBehavior(int $clientId, int $warehouseId, string $destinationKey): void
    {
        $scope = [
            'client_id' => $clientId,
            'mode' => 'direct',
            'warehouse_id' => $warehouseId,
        ];
        $rowId = 'persistence-'.Str::uuid();
        $retainedRowId = 'retained-'.Str::uuid();
        $newRowId = 'new-'.Str::uuid();
        $creator = $this->user(910001, $clientId, $warehouseId);
        $updater = $this->user(910002, $clientId, $warehouseId);
        $permissionService = Mockery::mock(PermissionService::class);
        $permissionService->shouldReceive('check')->andReturnTrue();
        $controller = app(DistributionProductController::class);

        $invalidQuantity = $controller->saveDistributionRows(
            $this->request($creator, [
                'mode' => 'direct',
                'replace' => false,
                'expected_revision' => 0,
                'deleted_row_ids' => [],
                'rows' => [['id' => 'invalid-'.Str::uuid(), 'productCode' => 'INVALID', 'wish_'.$destinationKey => -1]],
            ]),
            $permissionService
        );

        $invalidPayload = $invalidQuantity->getData(true);
        $this->assertSame(422, $invalidQuantity->getStatusCode());
        $this->assertSame('DISTRIBUTION_ROW_QUANTITY_INVALID', $invalidPayload['code']);
        $this->assertNotEmpty($invalidPayload['result']['errors'] ?? []);

        $created = $controller->saveDistributionRows(
            $this->request($creator, [
                'mode' => 'direct',
                'replace' => false,
                'expected_revision' => 0,
                'deleted_row_ids' => [],
                'rows' => [['id' => $rowId, 'productCode' => 'ORIGINAL']],
            ]),
            $permissionService
        );

        $this->assertSame(200, $created->getStatusCode());
        $this->assertSame(1, $created->getData(true)['result']['data']['revision']);

        DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $rowId)
            ->update(['sort_order' => 37]);

        DB::connection('sakemaru')->table('wms_distribution_rows')->insert($scope + [
            'row_id' => $retainedRowId,
            'sort_order' => 999,
            'business_key' => sha1($retainedRowId),
            'product_code' => 'RETAINED',
            'row_data' => json_encode(['id' => $retainedRowId, 'productCode' => 'RETAINED'], JSON_THROW_ON_ERROR),
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stale = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => false,
                'expected_revision' => 0,
                'deleted_row_ids' => [],
                'rows' => [['id' => $rowId, 'productCode' => 'STALE']],
            ]),
            $permissionService
        );

        $this->assertSame(409, $stale->getStatusCode());
        $this->assertSame('DISTRIBUTION_REVISION_CONFLICT', $stale->getData(true)['code']);

        $updated = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => false,
                'expected_revision' => 1,
                'deleted_row_ids' => [],
                'rows' => [['id' => $rowId, 'productCode' => 'UPDATED']],
            ]),
            $permissionService
        );

        $this->assertSame(200, $updated->getStatusCode());
        $this->assertTrue(DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $retainedRowId)->exists());

        $saved = DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $rowId)->first();
        $this->assertSame($creator->id, (int) $saved->created_by);
        $this->assertSame($updater->id, (int) $saved->updated_by);
        $this->assertSame(37, (int) $saved->sort_order);
        $this->assertSame('UPDATED', json_decode($saved->row_data, true, flags: JSON_THROW_ON_ERROR)['productCode']);

        $expectedAppendedSortOrder = 1 + (int) DB::connection('sakemaru')
            ->table('wms_distribution_rows')
            ->where($scope)
            ->max('sort_order');
        $appended = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => false,
                'expected_revision' => 2,
                'deleted_row_ids' => [],
                'rows' => [['id' => $newRowId, 'productCode' => 'APPENDED']],
            ]),
            $permissionService
        );

        $this->assertSame(200, $appended->getStatusCode());
        $this->assertSame(
            $expectedAppendedSortOrder,
            (int) DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $newRowId)->value('sort_order')
        );

        $replaced = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => true,
                'expected_revision' => 3,
                'deleted_row_ids' => [],
                'rows' => [
                    ['id' => $newRowId, 'productCode' => 'APPENDED'],
                    ['id' => $rowId, 'productCode' => 'UPDATED'],
                ],
            ]),
            $permissionService
        );

        $this->assertSame(200, $replaced->getStatusCode());
        $this->assertSame(
            [$newRowId => 0, $rowId => 1],
            DB::connection('sakemaru')->table('wms_distribution_rows')
                ->where($scope)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->pluck('sort_order', 'row_id')
                ->map(fn ($sortOrder): int => (int) $sortOrder)
                ->all()
        );
        $this->assertNotNull(
            DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $retainedRowId)->value('deleted_at')
        );

        $confirmedData = [
            'id' => $rowId,
            'productCode' => 'CONFIRMED',
            'checked' => true,
            'confirmedAt' => '2026-08-24T10:00:00+09:00',
            'confirmed_at' => '2026-08-24T10:00:00+09:00',
            'memo' => 'locked memo',
            'alloc_'.$destinationKey => 4,
        ];
        DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $rowId)
            ->update(['row_data' => json_encode($confirmedData, JSON_THROW_ON_ERROR)]);

        $confirmationRemoved = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => false,
                'expected_revision' => 4,
                'rows' => [[
                    'id' => $rowId,
                    'productCode' => 'ILLEGAL CONFIRMED UPDATE',
                    'checked' => false,
                    'confirmedAt' => 'ILLEGAL TIMESTAMP',
                    'memo' => 'illegal memo update',
                    'alloc_'.$destinationKey => 4,
                ]],
            ]),
            $permissionService
        );

        $this->assertSame(200, $confirmationRemoved->getStatusCode());
        $this->assertSame([], $confirmationRemoved->getData(true)['result']['data']['confirmation_protected_row_ids']);
        $confirmationRemovedData = json_decode(
            DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $rowId)->value('row_data'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('ILLEGAL CONFIRMED UPDATE', $confirmationRemovedData['productCode']);
        $this->assertSame('illegal memo update', $confirmationRemovedData['memo']);
        $this->assertFalse($confirmationRemovedData['checked']);
        $this->assertSame('', $confirmationRemovedData['confirmedAt']);
        $this->assertSame('', $confirmationRemovedData['confirmed_at']);

        $confirmedData = $confirmationRemovedData;
        $confirmedData['checked'] = false;
        DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $rowId)
            ->update(['row_data' => json_encode($confirmedData, JSON_THROW_ON_ERROR)]);
        $printed = $controller->markDirectRequestPrinted(
            $this->request($updater, ['row_ids' => [$rowId]]),
            $permissionService
        );
        $this->assertSame(200, $printed->getStatusCode());
        $this->assertSame(6, $printed->getData(true)['result']['data']['revision']);
        $printedData = json_decode(
            DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $rowId)->value('row_data'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertTrue($printedData['printed']);
        $this->assertSame('ILLEGAL CONFIRMED UPDATE', $printedData['productCode']);
        $this->assertSame('illegal memo update', $printedData['memo']);

        $lockedData = [
            'id' => $rowId,
            'productCode' => 'LOCKED',
            'warehouseTransferGenerated' => true,
            'warehouseTransferQueueIds' => [12345],
        ];
        DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $rowId)
            ->update(['row_data' => json_encode($lockedData, JSON_THROW_ON_ERROR)]);

        $protected = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => true,
                'expected_revision' => 6,
                'deleted_row_ids' => [$rowId],
                'rows' => [
                    ['id' => $rowId, 'productCode' => 'ILLEGAL UPDATE'],
                    ['id' => $newRowId, 'productCode' => 'APPENDED'],
                ],
            ]),
            $permissionService
        );

        $this->assertSame(200, $protected->getStatusCode());
        $this->assertSame(1, $protected->getData(true)['result']['data']['saved_count']);
        $this->assertSame(0, $protected->getData(true)['result']['data']['deleted_count']);
        $this->assertSame([$rowId], $protected->getData(true)['result']['data']['protected_row_ids']);
        $protectedRow = DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $rowId)
            ->first(['row_data', 'deleted_at']);
        $this->assertNull($protectedRow->deleted_at);
        $persistedLockedData = json_decode($protectedRow->row_data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('LOCKED', $persistedLockedData['productCode']);
        $this->assertTrue($persistedLockedData['warehouseTransferGenerated']);
        $this->assertSame([12345], $persistedLockedData['warehouseTransferQueueIds']);

        $deleteLockedData = [
            'id' => $newRowId,
            'productCode' => 'ORDER-CANDIDATE',
            'checked' => true,
            'orderCandidateGenerated' => true,
            'orderCandidateIds' => [54321],
        ];
        DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $newRowId)
            ->update(['row_data' => json_encode($deleteLockedData, JSON_THROW_ON_ERROR)]);

        $deleteProtected = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => true,
                'expected_revision' => 7,
                'deleted_row_ids' => [$newRowId],
                'rows' => [
                    ['id' => $rowId, 'productCode' => 'ILLEGAL UPDATE'],
                ],
            ]),
            $permissionService
        );

        $this->assertSame(200, $deleteProtected->getStatusCode());
        $this->assertSame(0, $deleteProtected->getData(true)['result']['data']['deleted_count']);
        $this->assertSame([$rowId], $deleteProtected->getData(true)['result']['data']['protected_row_ids']);
        $this->assertEqualsCanonicalizing(
            [$rowId, $newRowId],
            $deleteProtected->getData(true)['result']['data']['delete_protected_row_ids']
        );
        $this->assertNull(
            DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $newRowId)->value('deleted_at')
        );
        $this->assertSame(
            'ORDER-CANDIDATE',
            json_decode(
                DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $newRowId)->value('row_data'),
                true,
                flags: JSON_THROW_ON_ERROR
            )['productCode']
        );

        $candidateFieldProtected = $controller->saveDistributionRows(
            $this->request($updater, [
                'mode' => 'direct',
                'replace' => true,
                'expected_revision' => 8,
                'rows' => [[
                    'id' => $newRowId,
                    'productCode' => 'CHANGED-PRODUCT',
                    'poCase' => 9,
                    'poEach' => 8,
                    'alloc_'.$destinationKey => 7,
                    'checked' => false,
                    'memo' => 'allowed update',
                ]],
            ]),
            $permissionService
        );

        $this->assertSame(200, $candidateFieldProtected->getStatusCode());
        $candidateResult = $candidateFieldProtected->getData(true)['result']['data'];
        $this->assertSame([$newRowId], $candidateResult['field_protected_row_ids']);
        $this->assertEqualsCanonicalizing([$newRowId, $rowId], $candidateResult['resync_required_row_ids']);
        $candidateData = json_decode(
            DB::connection('sakemaru')->table('wms_distribution_rows')->where($scope)->where('row_id', $newRowId)->value('row_data'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('ORDER-CANDIDATE', $candidateData['productCode']);
        $this->assertArrayNotHasKey('poCase', $candidateData);
        $this->assertArrayNotHasKey('poEach', $candidateData);
        $this->assertArrayNotHasKey('alloc_'.$destinationKey, $candidateData);
        $this->assertFalse($candidateData['checked']);
        $this->assertSame('allowed update', $candidateData['memo']);
        $this->assertTrue($candidateData['orderCandidateGenerated']);
        $this->assertSame([54321], $candidateData['orderCandidateIds']);

        $generationBusinessKey = sha1('generation-snapshot-'.$newRowId);
        $generationData = array_merge($candidateData, [
            'distributionBusinessKey' => $generationBusinessKey,
            'itemId' => 123,
            'productCode' => 'ORDER-CANDIDATE',
            'itemContractorId' => 456,
            'contractorId' => 789,
            'supplierId' => 987,
            'orderDate' => '2026-08-21',
            'deliveryDate' => '2026-08-22',
            'poCase' => 2,
            'poEach' => 3,
            'unitsPerCase' => 6,
            'alloc_'.$destinationKey => 4,
        ]);
        DB::connection('sakemaru')->table('wms_distribution_rows')
            ->where($scope)
            ->where('row_id', $newRowId)
            ->update([
                'business_key' => $generationBusinessKey,
                'row_data' => json_encode($generationData, JSON_THROW_ON_ERROR),
            ]);

        $generationRow = [
            'row_id' => $newRowId,
            'distribution_business_key' => $generationBusinessKey,
            'item_id' => 123,
            'product_code' => 'ORDER-CANDIDATE',
            'item_contractor_id' => 456,
            'contractor_id' => 789,
            'supplier_id' => 987,
            'order_date' => '2026-08-21',
            'delivery_date' => '2026-08-22',
            'po_case' => 2,
            'po_each' => 3,
            'units_per_case' => 6,
            'allocations' => [[
                'destination_key' => $destinationKey,
                'quantity' => 4,
            ]],
        ];
        $snapshotGuard = new \ReflectionMethod($controller, 'assertDistributionGenerationRowsAreCurrent');
        $snapshotGuard->invoke($controller, $clientId, $warehouseId, 'direct', [$generationRow], 'order-candidate');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('対象データが別の画面で更新されています');
        $generationRow['po_each'] = 99;
        $snapshotGuard->invoke($controller, $clientId, $warehouseId, 'direct', [$generationRow], 'order-candidate');
    }

    private function assertFetchLimitBehavior(int $clientId, int $warehouseId): void
    {
        $now = now();
        foreach (array_chunk(range(1, 3001), 250) as $numbers) {
            DB::connection('sakemaru')->table('wms_distribution_rows')->insert(array_map(
                fn (int $number): array => [
                    'client_id' => $clientId,
                    'mode' => 'allocation',
                    'warehouse_id' => $warehouseId,
                    'row_id' => 'limit-'.$number.'-'.Str::uuid(),
                    'sort_order' => $number,
                    'row_data' => json_encode(['id' => 'limit-'.$number], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $numbers
            ));
        }

        $permissionService = Mockery::mock(PermissionService::class);
        $permissionService->shouldReceive('check')->andReturnTrue();
        $user = $this->user(910003, $clientId, $warehouseId);
        $request = Request::create('/api/distribution/rows?mode=allocation&context=edit', 'GET', [
            'mode' => 'allocation',
            'context' => 'edit',
        ]);
        $request->setUserResolver(fn (): User => $user);
        $connection = DB::connection('sakemaru');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $response = app(DistributionProductController::class)->distributionRows($request, $permissionService);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('DISTRIBUTION_ROWS_LIMIT_EXCEEDED', $response->getData(true)['code']);
        $distributionSelects = collect($queries)
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'from `wms_distribution_rows`'));
        $this->assertNotEmpty($distributionSelects);
        $this->assertTrue($distributionSelects->every(
            fn (string $query): bool => str_contains(strtolower($query), 'count(*)')
        ));
    }

    private function skipLocallyOrFailInCi(string $message): never
    {
        if (filter_var(env('REQUIRE_DISTRIBUTION_DB_TESTS', env('CI', false)), FILTER_VALIDATE_BOOL)) {
            $this->fail($message);
        }

        $this->markTestSkipped($message);
    }

    private function user(int $id, int $clientId, int $warehouseId): User
    {
        return (new User)->forceFill([
            'id' => $id,
            'client_id' => $clientId,
            'wms_selected_warehouse_id' => $warehouseId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(User $user, array $payload): Request
    {
        $request = Request::create('/api/distribution/rows', 'PUT', $payload);
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }
}
