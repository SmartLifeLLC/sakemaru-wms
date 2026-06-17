<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Filament\Concerns\HasStockSubqueries;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingCompleted\WmsIncomingCompletedResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingCompleted extends ListRecords
{
    use AdvancedTables;
    use HasStockSubqueries;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingCompletedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transmitPurchase')
                ->label('仕入データ登録')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('仕入データ登録')
                ->modalDescription(fn () => $this->getPurchaseTransmissionModalDescription())
                ->requiresConfirmation()
                ->modalSubmitActionLabel('登録')
                ->action(function () {
                    $warehouseId = $this->getPurchaseTransmissionWarehouseId();
                    if ($warehouseId === null) {
                        Notification::make()
                            ->title('倉庫を選択してください')
                            ->body('仕入データ登録は倉庫別に実行します。倉庫タブを選択してから再実行してください。')
                            ->warning()
                            ->send();

                        return;
                    }

                    $transmissionService = app(IncomingTransmissionService::class);

                    try {
                        $result = $transmissionService->transmitConfirmedIncomings($warehouseId);

                        if ($result['success']) {
                            if ($result['schedule_count'] === 0) {
                                Notification::make()
                                    ->title('仕入データ登録対象はありません')
                                    ->body('選択中倉庫に、仕入データ生成前の外部発注データはありません。')
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('仕入キューに登録しました')
                                    ->body("キュー: {$result['queue_count']}件 / 入荷データ: {$result['schedule_count']}件")
                                    ->success()
                                    ->send();
                            }
                        } else {
                            Notification::make()
                                ->title('一部エラーが発生しました')
                                ->body("成功: {$result['schedule_count']}件 / エラー: ".count($result['errors']).'件')
                                ->warning()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('登録エラー')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'item', 'contractor', 'location', 'orderCandidate', 'confirmedByUser', 'confirmedByPicker'])
                ->addSelect([
                    'computed_current_stock' => static::currentStockSubquery('wms_order_incoming_schedules'),
                    'computed_available_stock' => static::availableStockSubquery('wms_order_incoming_schedules'),
                    'computed_default_location' => static::defaultLocationSubquery('wms_order_incoming_schedules'),
                ])
                ->orderBy('confirmed_at', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    private function getPurchaseTransmissionWarehouseId(): ?int
    {
        $activeView = $this->activePresetView ?? null;

        if (is_string($activeView)) {
            if ($activeView === 'all') {
                return null;
            }

            if (preg_match('/^(?:wh|default)_(\d+)$/', $activeView, $matches)) {
                return (int) $matches[1];
            }
        }

        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? (int) $warehouseId : null;
    }

    private function getPurchaseTransmissionWarehouse(): ?Warehouse
    {
        $warehouseId = $this->getPurchaseTransmissionWarehouseId();

        return $warehouseId ? Warehouse::find($warehouseId) : null;
    }

    private function getPurchaseTransmissionModalDescription(): string
    {
        $warehouseName = $this->getPurchaseTransmissionWarehouse()?->name ?? '選択中倉庫';

        return "{$warehouseName} の入荷完了データのうち、仕入データ生成前の外部発注のみを基幹システムの仕入キューに登録します。同一の仕入先・入荷日ごとに1伝票としてまとめられます。登録後はデータの修正ができなくなります。";
    }

    protected ?array $presetViewWarehouseData = null;

    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->presetViewWarehouseData !== null) {
            return $this->presetViewWarehouseData;
        }

        $cacheKey = 'incoming_completed_warehouses_'.auth()->id();
        $this->presetViewWarehouseData = cache()->remember($cacheKey, 30, function () {
            $warehouseIds = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)
                ->distinct()
                ->pluck('warehouse_id')
                ->toArray();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('code')
                ->get(['id', 'name']);

            return [
                'ids' => $warehouseIds,
                'warehouses' => $warehouses,
            ];
        });

        return $this->presetViewWarehouseData;
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
                'all' => PresetView::make()
                    ->favorite()
                    ->label('全て'),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
