<?php

namespace Tests\Feature;

use App\Filament\Pages\WarehouseOrderReview;
use App\Models\User;
use Mockery;
use Sakemaru\Auth\Services\PermissionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WarehouseOrderReviewAccessTest extends TestCase
{
    public function test_guest_cannot_access_report(): void
    {
        $this->assertFalse(WarehouseOrderReview::canAccess());
    }

    public function test_export_rechecks_master_read_permission(): void
    {
        $user = new User(['client_id' => 6]);
        $user->id = 991;
        $this->actingAs($user);
        $permission = Mockery::mock(PermissionService::class);
        $permission->shouldReceive('check')->once()->with($user, 'wms.item-contractor.view')->andReturn(false);
        $this->app->instance(PermissionService::class, $permission);
        $this->expectException(HttpException::class);
        (new WarehouseOrderReview)->export();
    }
}
