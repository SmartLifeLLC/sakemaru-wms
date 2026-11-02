<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Filament\Pages\AutoReplenishmentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Bus;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DetailDrawerCloseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        // Render the actual drawer without loading production history or settings.
        $source = file_get_contents(resource_path('views/filament/pages/auto-replenishment.blade.php'));
        $start = strpos($source, '    @if($detail)');
        $end = strpos($source, '    @if($this->confirmation)', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        DetailDrawerCloseFixture::$drawerView = tempnam(sys_get_temp_dir(), 'auto-order-detail-').'.blade.php';
        file_put_contents(DetailDrawerCloseFixture::$drawerView, '<div>'.substr($source, $start, $end - $start).'</div>');
    }

    protected function tearDown(): void
    {
        @unlink(DetailDrawerCloseFixture::$drawerView);
        @unlink(substr(DetailDrawerCloseFixture::$drawerView, 0, -10));
        parent::tearDown();
    }

    public static function screens(): array
    {
        return ['history' => ['history'], 'checklist' => ['checklist']];
    }

    #[DataProvider('screens')]
    public function test_all_close_controls_use_a_server_action_and_repeated_close_is_safe(string $screen): void
    {
        $page = Livewire::test(DetailDrawerCloseFixture::class, ['drawerScreen' => $screen])->assertStatus(200);
        self::assertSame(3, substr_count($page->html(), 'wire:click="closeDetail"'));
        $page->assertSee('発注内容の詳細')
            ->call('closeDetail')->assertSet('detailId', null)->assertDontSee('発注内容の詳細')
            ->call('closeDetail')->assertSet('detailId', null)
            ->assertSet('warehouseId', 10)->assertSet('runId', 123)
            ->assertSet('form.is_enabled', true);
        Bus::assertNothingDispatched();
    }

    #[DataProvider('screens')]
    public function test_client_updates_to_the_detail_id_remain_locked(string $screen): void
    {
        $page = Livewire::test(DetailDrawerCloseFixture::class, ['drawerScreen' => $screen]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('detailId', null);
    }
}

class DetailDrawerCloseFixture extends AutoReplenishmentPage
{
    public static string $drawerView;

    public string $drawerScreen = 'history';

    public static function canAccess(): bool
    {
        return true;
    }

    public function mount(): void
    {
        $this->detailId = 89;
        $this->warehouseId = 10;
        $this->runId = 123;
        $this->form = ['is_enabled' => true];
    }

    public function render(): View
    {
        $detail = $this->detailId === null ? null : (object) [
            'confirmation_snapshot' => ['item_code' => '143024', 'item_name' => 'テスト商品'],
            'proposal_snapshot' => [], 'phase' => 'RECONCILIATION_REQUIRED',
            'reason' => '照合待ち', 'quantity_pieces' => 24,
            'artifact_output_snapshot' => null, 'channel' => 'LOGISTICS',
        ];

        return view()->file(self::$drawerView, [
            'detail' => $detail, 'screen' => $this->drawerScreen,
            'labels' => [], 'links' => collect(), 'targets' => [],
        ]);
    }
}
