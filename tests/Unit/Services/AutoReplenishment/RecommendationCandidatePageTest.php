<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Filament\Pages\AutoReplenishmentPage;
use App\Models\User;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Sakemaru\Auth\Services\PermissionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecommendationCandidatePageTest extends TestCase
{
    private bool $allowEdit = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        DB::connection('sakemaru')->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, is_active INTEGER, is_virtual INTEGER)');
        DB::connection('sakemaru')->table('warehouses')->insert(['id' => 1, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['id' => 1, 'client_id' => 6]);
        $user->shouldReceive('fresh')->andReturnSelf();
        $user->shouldReceive('getSelectedWarehouseId')->andReturn(1);
        $this->be($user);
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('clientId')->andReturn(6);
        $this->app->instance(ReplenishmentGuard::class, $guard);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('check')->andReturnUsing(fn ($user, $permission) => ! str_ends_with($permission, '.edit') || $this->allowEdit);
        $this->app->instance(PermissionService::class, $permissions);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function page(array $candidateChanges = []): RecommendationCandidatePageFixture
    {
        $sales = ['available' => true, 'values' => ['strategy_key' => 'arrival_adaptive_v1', 'reorder_point_pieces' => 9,
            'min_order_pieces' => 4, 'max_order_pieces' => 4, 'order_multiple_pieces' => 1, 'target_stock_pieces' => 8],
            'basis' => ['candidate_key' => 'sales', 'batch_quantity_pieces' => 4]];
        $history = ['available' => true, 'values' => array_replace($sales['values'], ['min_order_pieces' => 6, 'max_order_pieces' => 12, 'target_stock_pieces' => 10]),
            'basis' => ['candidate_key' => 'history', 'batch_quantity_pieces' => 6, 'reference_only' => true]];
        $recommendation = $sales + ['candidates' => ['sales' => $sales, 'history' => array_replace_recursive($history, $candidateChanges)]];
        $service = Mockery::mock(SettingsRecommendationService::class);
        $service->shouldReceive('recommend')->once()->andReturn($recommendation);
        $this->app->instance(SettingsRecommendationService::class, $service);
        $page = new RecommendationCandidatePageFixture;
        $page->warehouseId = 1;
        $page->showEditor = true;
        $page->form = ['item_id' => 122442, 'channel' => 'EOS', 'contractor_id' => 1021, 'supplier_id' => 1021, 'shipping_unit_mode' => 'STANDARD', 'is_enabled' => true];
        $page->calculateSettings();

        return $page;
    }

    public function test_selecting_candidates_uses_one_survey_and_preserves_route_and_enable_state_without_writing(): void
    {
        $page = $this->page();
        $this->assertSame(4, $page->form['min_order_pieces']);
        DB::connection('sakemaru')->enableQueryLog();
        $page->selectRecommendationCandidate('history');
        $this->assertSame(6, $page->form['min_order_pieces']);
        $this->assertSame(12, $page->form['max_order_pieces']);
        $this->assertSame('history', $page->recommendation['basis']['candidate_key']);
        $this->assertTrue($page->form['is_enabled']);
        $this->assertSame(1021, $page->form['contractor_id']);
        $page->form['max_order_pieces'] = 10;
        $page->selectRecommendationCandidate('history');
        $this->assertSame(12, $page->form['max_order_pieces']);
        $page->selectRecommendationCandidate('sales');
        $this->assertSame(4, $page->form['max_order_pieces']);
        foreach (DB::connection('sakemaru')->getQueryLog() as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
    }

    public function test_candidate_values_cannot_replace_route_or_enabled_state(): void
    {
        $page = $this->page(['values' => ['is_enabled' => false, 'contractor_id' => 999, 'shipping_unit_mode' => 'PALLET']]);
        $page->selectRecommendationCandidate('history');
        $this->assertTrue($page->form['is_enabled']);
        $this->assertSame(1021, $page->form['contractor_id']);
        $this->assertSame('STANDARD', $page->form['shipping_unit_mode']);
    }

    public function test_unknown_or_disabled_candidate_keeps_form_unchanged(): void
    {
        $page = $this->page(['available' => false, 'basis' => ['reason' => '発注単位に合う実績がありません。']]);
        $before = $page->form;
        foreach (['history', 'external'] as $key) {
            try {
                $page->selectRecommendationCandidate($key);
                $this->fail('Unavailable candidates must be rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('operation', $e->errors());
                if ($key === 'history') {
                    $this->assertSame('発注単位に合う実績がありません。', $e->errors()['operation'][0]);
                }
                $this->assertSame($before, $page->form);
            }
        }
    }

    public function test_changed_route_is_rejected_even_when_no_livewire_update_hook_ran(): void
    {
        $page = $this->page();
        $page->form['supplier_id'] = 999;
        $before = $page->form;
        try {
            $page->selectRecommendationCandidate('history');
            $this->fail('Stale route must be rejected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('算定条件が変わりました', $e->getMessage());
            $this->assertSame($before, $page->form);
            $this->assertNull($page->recommendation);
        }
    }

    public function test_unit_and_review_day_changes_clear_old_candidates_without_changing_quantities(): void
    {
        $page = $this->page();
        $before = $page->form;
        $page->recommendationUnitMode = 'CASE';
        $page->updatedRecommendationUnitMode();
        $this->assertNull($page->recommendation);
        $this->assertNull($page->recommendationContext);
        $this->assertSame($before, $page->form);
        $page->recommendation = ['available' => true];
        $page->recommendationReviewDays = '3';
        $page->updatedRecommendationReviewDays();
        $this->assertNull($page->recommendation);
        $this->assertSame($before, $page->form);
    }

    public function test_permission_is_checked_again_when_selecting_candidate(): void
    {
        $page = $this->page();
        $this->allowEdit = false;
        $this->expectException(HttpException::class);
        $page->selectRecommendationCandidate('history');
    }

    public function test_closed_editor_cannot_select_candidate(): void
    {
        $page = $this->page();
        $page->showEditor = false;
        $this->expectException(HttpException::class);
        $page->selectRecommendationCandidate('history');
    }
}

class RecommendationCandidatePageFixture extends AutoReplenishmentPage {}
