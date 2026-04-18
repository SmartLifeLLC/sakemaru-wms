<?php

namespace Tests\Feature\Api;

use App\Models\WmsPicker;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProxyShipmentApiTest extends TestCase
{
    private string $apiKey;

    private ?WmsPicker $picker = null;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = config('api.keys')[0] ?? 'test-key';

        $this->picker = WmsPicker::first();
        if ($this->picker) {
            $this->token = $this->picker->createToken('test-proxy')->plainTextToken;
        }
    }

    private function apiHeaders(): array
    {
        return [
            'X-API-Key' => $this->apiKey,
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];
    }

    private function findOrSkipReservedAllocation(): WmsShortageAllocation
    {
        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', false)
            ->where('status', WmsShortageAllocation::STATUS_RESERVED)
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No RESERVED confirmed allocation found in database');
        }

        return $allocation;
    }

    /**
     * 1. RESERVED allocation が一覧に出る
     */
    public function test_reserved_allocation_appears_in_list(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = $this->findOrSkipReservedAllocation();

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/proxy-shipments?warehouse_id={$allocation->target_warehouse_id}");

        $response->assertStatus(200)
            ->assertJson(['is_success' => true, 'code' => 'SUCCESS']);

        $data = $response->json('result.data');
        $ids = array_column($data, 'allocation_id');
        $this->assertContains($allocation->id, $ids);
    }

    /**
     * 2. PENDING allocation は一覧に出ない
     */
    public function test_pending_allocation_not_in_list(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $pendingAllocation = WmsShortageAllocation::where('status', WmsShortageAllocation::STATUS_PENDING)
            ->first();

        if (! $pendingAllocation) {
            $this->markTestSkipped('No PENDING allocation found');
        }

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/proxy-shipments?warehouse_id={$pendingAllocation->target_warehouse_id}");

        $response->assertStatus(200);

        $data = $response->json('result.data');
        $ids = array_column($data, 'allocation_id');
        $this->assertNotContains($pendingAllocation->id, $ids);
    }

    /**
     * 3. is_finished = true は一覧に出ない
     */
    public function test_finished_allocation_not_in_list(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $finishedAllocation = WmsShortageAllocation::where('is_finished', true)->first();

        if (! $finishedAllocation) {
            $this->markTestSkipped('No finished allocation found');
        }

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/proxy-shipments?warehouse_id={$finishedAllocation->target_warehouse_id}");

        $response->assertStatus(200);

        $data = $response->json('result.data');
        $ids = array_column($data, 'allocation_id');
        $this->assertNotContains($finishedAllocation->id, $ids);
    }

    /**
     * 4. warehouse_id 不一致は 422
     */
    public function test_warehouse_mismatch_returns_422(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = $this->findOrSkipReservedAllocation();

        // Use a different warehouse_id
        $wrongWarehouseId = $allocation->target_warehouse_id + 9999;

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/proxy-shipments/{$allocation->id}?warehouse_id={$wrongWarehouseId}");

        $response->assertStatus(422);
    }

    /**
     * 5. start で PICKING に変わる
     */
    public function test_start_changes_status_to_picking(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = $this->findOrSkipReservedAllocation();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/start", [
                'warehouse_id' => $allocation->target_warehouse_id,
            ]);

        $response->assertStatus(200)
            ->assertJson(['is_success' => true]);

        $allocation->refresh();
        $this->assertEquals(WmsShortageAllocation::STATUS_PICKING, $allocation->status);
        $this->assertNotNull($allocation->started_at);
        $this->assertEquals($this->picker->id, $allocation->started_picker_id);
    }

    /**
     * 6. start の再送は成功扱い
     */
    public function test_start_resend_is_success(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', false)
            ->where('status', WmsShortageAllocation::STATUS_PICKING)
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No PICKING allocation found');
        }

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/start", [
                'warehouse_id' => $allocation->target_warehouse_id,
            ]);

        $response->assertStatus(200)
            ->assertJson(['is_success' => true]);
    }

    /**
     * 7. update で picked_qty が更新される
     */
    public function test_update_changes_picked_qty(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', false)
            ->whereIn('status', [WmsShortageAllocation::STATUS_RESERVED, WmsShortageAllocation::STATUS_PICKING])
            ->where('assign_qty', '>', 0)
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No updatable allocation found');
        }

        $pickedQty = min(1, $allocation->assign_qty);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/update", [
                'warehouse_id' => $allocation->target_warehouse_id,
                'picked_qty' => $pickedQty,
            ]);

        $response->assertStatus(200)
            ->assertJson(['is_success' => true]);

        $allocation->refresh();
        $this->assertEquals($pickedQty, $allocation->picked_qty);
    }

    /**
     * 8. picked_qty > assign_qty は 422
     */
    public function test_picked_qty_over_assign_qty_returns_422(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', false)
            ->whereIn('status', [WmsShortageAllocation::STATUS_RESERVED, WmsShortageAllocation::STATUS_PICKING])
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No allocation found');
        }

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/update", [
                'warehouse_id' => $allocation->target_warehouse_id,
                'picked_qty' => $allocation->assign_qty + 1,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 9. complete で stock_transfer_queue が1件だけ作られる
     */
    public function test_complete_creates_single_stock_transfer_queue(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', false)
            ->whereIn('status', [WmsShortageAllocation::STATUS_RESERVED, WmsShortageAllocation::STATUS_PICKING])
            ->where('assign_qty', '>', 0)
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No completable allocation found');
        }

        $requestId = "proxy-shipment-{$allocation->id}";

        // Clean up any existing queue for this test
        DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->delete();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/complete", [
                'warehouse_id' => $allocation->target_warehouse_id,
                'picked_qty' => $allocation->assign_qty,
            ]);

        $response->assertStatus(200)
            ->assertJson(['is_success' => true]);

        $allocation->refresh();
        $this->assertTrue($allocation->is_finished);

        $queueCount = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->count();

        $this->assertEquals(1, $queueCount);
    }

    /**
     * 10. complete 再送で queue が増えない
     */
    public function test_complete_resend_does_not_create_duplicate_queue(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        // Find a finished allocation with a queue
        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', true)
            ->where('picked_qty', '>', 0)
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No finished allocation with picked qty found');
        }

        $requestId = "proxy-shipment-{$allocation->id}";
        $beforeCount = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->count();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/complete", [
                'warehouse_id' => $allocation->target_warehouse_id,
            ]);

        $response->assertStatus(200)
            ->assertJson(['is_success' => true]);

        $afterCount = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->count();

        $this->assertEquals($beforeCount, $afterCount);
    }

    /**
     * 11. picked_qty = 0 で complete → SHORTAGE、queue作成なし
     */
    public function test_complete_with_zero_qty_creates_no_queue(): void
    {
        if (! $this->picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        $allocation = WmsShortageAllocation::where('is_confirmed', true)
            ->where('is_finished', false)
            ->whereIn('status', [WmsShortageAllocation::STATUS_RESERVED, WmsShortageAllocation::STATUS_PICKING])
            ->first();

        if (! $allocation) {
            $this->markTestSkipped('No completable allocation found');
        }

        $requestId = "proxy-shipment-{$allocation->id}";

        // Ensure picked_qty is 0
        $allocation->update(['picked_qty' => 0]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/proxy-shipments/{$allocation->id}/complete", [
                'warehouse_id' => $allocation->target_warehouse_id,
                'picked_qty' => 0,
            ]);

        $response->assertStatus(200)
            ->assertJson(['is_success' => true]);

        $allocation->refresh();
        $this->assertTrue($allocation->is_finished);
        $this->assertEquals(WmsShortageAllocation::STATUS_SHORTAGE, $allocation->status);

        $queueCount = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->count();

        $this->assertEquals(0, $queueCount);
    }
}
