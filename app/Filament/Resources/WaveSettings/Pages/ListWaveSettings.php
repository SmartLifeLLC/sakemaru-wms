<?php

namespace App\Filament\Resources\WaveSettings\Pages;

use App\Filament\Resources\WaveSettings\WaveSettingResource;
use App\Services\Warehouse91StockLotSyncService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Throwable;

class ListWaveSettings extends ListRecords
{
    protected static string $resource = WaveSettingResource::class;

    private ?array $warehouse91StockLotSyncPreview = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncWarehouse91StockLots')
                ->label('在庫同期')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->modalHeading('91倉庫 在庫同期')
                ->modalDescription('real_stocksを正として、91倉庫のACTIVEロット数量を同期します。')
                ->modalWidth('7xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(fn (Action $action) => $action->label('在庫同期を実行')->color('danger'))
                ->modalCancelActionLabel('同期せず閉じる')
                ->modalFooterActionsAlignment(Alignment::End)
                ->schema(fn () => collect($this->getWarehouse91StockLotSyncPreview()['selectable_location_rows'])
                    ->filter(fn (array $row): bool => $row['location_options'] !== [])
                    ->map(fn (array $row): Select => Select::make("location_override_{$row['real_stock_id']}")
                        ->label("{$row['item_code']} {$row['item_name']}")
                        ->options($row['location_options'])
                        ->default(isset($row['location_options'][$row['target_lot_location_id']])
                            ? $row['target_lot_location_id']
                            : null)
                        ->required()
                        ->searchable()
                    )
                    ->all())
                ->modalContent(fn () => view('filament.resources.wave-settings.stock-lot-sync-preview', [
                    'preview' => $this->getWarehouse91StockLotSyncPreview(),
                ]))
                ->action(function (array $data): void {
                    try {
                        $result = app(Warehouse91StockLotSyncService::class)->sync($data);
                        $before = $result['before'];
                        $after = $result['after_remaining'];

                        Notification::make()
                            ->title('91倉庫の在庫を同期しました')
                            ->body(
                                "対象: {$before['rows']}件 / 既存更新: {$before['update_lot']}件 / 新規作成: {$before['create_lot']}件\n".
                                "残差分: {$after['rows']}件"
                            )
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('在庫同期に失敗しました')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }

    private function getWarehouse91StockLotSyncPreview(): array
    {
        return $this->warehouse91StockLotSyncPreview
            ??= app(Warehouse91StockLotSyncService::class)->preview();
    }
}
