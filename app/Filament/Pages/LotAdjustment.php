<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use App\Models\Sakemaru\Warehouse;
use App\Services\LotAdjustment\LotAdjustmentRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

/**
 * ロット調節（手動実行ツール / Phase 1）
 *
 * 選択倉庫に対し、A 相殺 / B §3.3 再ACTIVE化 / D STLA repoint を
 * プレビュー（DRY_RUN）→確認→実行（APPLIED）で適用する。
 * wave生成へは挿入しない。実行結果は wms_lot_adjustment_logs に記録される。
 */
class LotAdjustment extends AdminPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected string $view = 'filament.pages.lot-adjustment';

    public ?int $warehouseId = null;

    public ?string $warehouseName = null;

    /** @var array<string, mixed>|null 直近のプレビュー/実行結果 */
    public ?array $result = null;

    public ?string $resultMode = null;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WAVE_MANAGEMENT_ADJUST_LOT->sort();
    }

    public function getTitle(): string
    {
        return 'ロット調節';
    }

    public function mount(): void
    {
        $this->resolveWarehouse();
    }

    protected function resolveWarehouse(): void
    {
        $this->warehouseId = auth()->user()?->getSelectedWarehouseId() ?? 91;
        $this->warehouseName = Warehouse::query()->whereKey($this->warehouseId)->value('name');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('プレビュー（変更しない）')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->action(function (): void {
                    $this->resolveWarehouse();
                    $this->result = app(LotAdjustmentRunner::class)->run($this->warehouseId, false);
                    $this->resultMode = 'DRY_RUN';

                    Notification::make()
                        ->title('プレビューを生成しました（在庫は変更していません）')
                        ->success()
                        ->send();
                }),

            Action::make('apply')
                ->label('調節を実行')
                ->icon('heroicon-o-play')
                ->color('danger')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalHeading('ロット調節の実行')
                ->modalDescription(fn () => "倉庫「{$this->warehouseName}」のロット調節（相殺・非ACTIVE再ACTIVE化・STLA参照修正）を適用します。実行前にプレビューで内容をご確認ください。棚番は変更されません。")
                ->modalSubmitActionLabel('実行する')
                ->modalCancelActionLabel('実行せず閉じる')
                ->modalFooterActionsAlignment(Alignment::End)
                ->action(function (): void {
                    $this->resolveWarehouse();
                    $this->result = app(LotAdjustmentRunner::class)->run($this->warehouseId, true);
                    $this->resultMode = 'APPLIED';

                    Notification::make()
                        ->title('ロット調節を実行しました')
                        ->body("適用 {$this->result['affected_count']} 件 / "
                            .'相殺'.$this->result['summary']['offset']
                            .'・再ACTIVE'.$this->result['summary']['reactivate']
                            .'・STLA修正'.$this->result['summary']['repoint'])
                        ->success()
                        ->send();
                }),
        ];
    }
}
