<?php

namespace Tests\Feature\Api;

use App\Models\WmsPicker;
use App\Models\WmsPickingTask;
use App\Models\WmsPickingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PickingApiTest extends TestCase
{
    /**
     * Test picking task start API
     */
    public function test_can_start_picking_task(): void
    {
        // Get test picker
        $picker = WmsPicker::first();
        if (!$picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        // Create token
        $token = $picker->createToken('test')->plainTextToken;

        // Get a pending task
        $task = WmsPickingTask::where('status', 'PENDING')->first();
        if (!$task) {
            // Reset a task to pending
            $task = WmsPickingTask::first();
            if (!$task) {
                $this->markTestSkipped('No picking tasks found in database');
            }
            $task->update(['status' => 'PENDING', 'started_at' => null, 'completed_at' => null]);
        }

        // Call start API
        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->postJson("/api/picking/tasks/{$task->id}/start");

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'SUCCESS',
            ]);

        // Verify task status updated
        $task->refresh();
        $this->assertEquals('PICKING', $task->status);
        $this->assertNotNull($task->started_at);

        // Verify log created
        $log = WmsPickingLog::where('picking_task_id', $task->id)
            ->where('action_type', 'START')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($picker->id, $log->picker_id);
    }

    /**
     * Test picking item update API
     */
    public function test_can_update_picking_item(): void
    {
        // Get test picker
        $picker = WmsPicker::first();
        if (!$picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        // Create token
        $token = $picker->createToken('test')->plainTextToken;

        // Get a task with items
        $task = WmsPickingTask::whereHas('pickingItemResults')->first();
        if (!$task) {
            $this->markTestSkipped('No picking tasks with items found in database');
        }

        $itemResult = $task->pickingItemResults()->first();
        if (!$itemResult) {
            $this->markTestSkipped('No picking item results found');
        }

        // Reset item result
        DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $itemResult->id)
            ->update(['picked_qty' => 0, 'shortage_qty' => 0, 'status' => 'PICKING']);

        $itemResult->refresh();
        $pickedQty = min(5, $itemResult->planned_qty); // Pick 5 or less

        // Call update API
        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->postJson("/api/picking/tasks/{$itemResult->id}/update", [
            'picked_qty' => $pickedQty,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'SUCCESS',
            ]);

        // Verify item result updated
        $updated = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $itemResult->id)
            ->first();

        $this->assertEquals($pickedQty, $updated->picked_qty);

        // Verify log created
        $log = WmsPickingLog::where('picking_item_result_id', $itemResult->id)
            ->where('action_type', 'PICK')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($picker->id, $log->picker_id);
        $this->assertEquals($pickedQty, $log->picked_qty);
    }

    /**
     * Test picking task complete API
     */
    public function test_can_complete_picking_task(): void
    {
        // Get test picker
        $picker = WmsPicker::first();
        if (!$picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        // Create token
        $token = $picker->createToken('test')->plainTextToken;

        // Get a task in PICKING status
        $task = WmsPickingTask::where('status', 'PICKING')->first();
        if (!$task) {
            // Set a task to PICKING
            $task = WmsPickingTask::first();
            if (!$task) {
                $this->markTestSkipped('No picking tasks found in database');
            }
            $task->update(['status' => 'PICKING', 'completed_at' => null]);
        }

        $statusBefore = $task->status;

        // Call complete API
        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->postJson("/api/picking/tasks/{$task->id}/complete");

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'SUCCESS',
            ]);

        // Verify task status updated
        $task->refresh();
        $this->assertContains($task->status, ['COMPLETED', 'SHORTAGE']);
        $this->assertNotNull($task->completed_at);

        // Verify log created
        $log = WmsPickingLog::where('picking_task_id', $task->id)
            ->where('action_type', 'COMPLETE')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($picker->id, $log->picker_id);
        $this->assertEquals($statusBefore, $log->status_before);
    }

    /**
     * Test API requires authentication
     */
    public function test_picking_apis_require_authentication(): void
    {
        $task = WmsPickingTask::first();
        if (!$task) {
            $this->markTestSkipped('No picking tasks found in database');
        }

        // Try without API key
        $response = $this->postJson("/api/picking/tasks/{$task->id}/start");
        $response->assertStatus(401);

        // Try with API key but no bearer token
        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Accept' => 'application/json',
        ])->postJson("/api/picking/tasks/{$task->id}/start");

        $response->assertStatus(401);
    }

    /**
     * Test picking logs are created for all actions
     */
    public function test_picking_logs_are_created(): void
    {
        $initialLogCount = WmsPickingLog::count();

        // Get test picker
        $picker = WmsPicker::first();
        if (!$picker) {
            $this->markTestSkipped('No pickers found in database');
        }

        // Create token
        $token = $picker->createToken('test')->plainTextToken;

        // Get a task
        $task = WmsPickingTask::first();
        if (!$task) {
            $this->markTestSkipped('No picking tasks found in database');
        }

        // Reset task
        $task->update(['status' => 'PENDING', 'started_at' => null, 'completed_at' => null]);

        // 1. Start task
        $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->postJson("/api/picking/tasks/{$task->id}/start");

        // Verify START log created
        $this->assertEquals(
            $initialLogCount + 1,
            WmsPickingLog::count(),
            'START log should be created'
        );

        // Verify log details
        $log = WmsPickingLog::latest('id')->first();
        $this->assertEquals('START', $log->action_type);
        $this->assertEquals($picker->id, $log->picker_id);
        $this->assertEquals($task->id, $log->picking_task_id);
    }
}
