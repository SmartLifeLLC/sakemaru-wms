<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortagesWaitingApprovals\WmsShortagesWaitingApprovalResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use App\Services\Shortage\ShortageApprovalService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListWmsShortagesWaitingApprovals extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShortagesWaitingApprovalResource::class;

    protected ?Collection $cachedWarehouses = null;

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $systemDate = ClientSetting::systemDateYMD();

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        $defaultFilterData = [
            'shipment_date' => ['shipment_date' => $systemDate],
        ];

        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        $views = [
            'default' => PresetView::make()
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        if ($defaultWarehouse) {
            $views["default_{$defaultWarehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        }

        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }

            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    /**
     * @return array{ids: array<int>, warehouses: Collection<int, Warehouse>}
     */
    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->cachedWarehouses !== null) {
            return [
                'ids' => $this->cachedWarehouses->pluck('id')->toArray(),
                'warehouses' => $this->cachedWarehouses,
            ];
        }

        $warehouseIds = WmsShortage::query()
            ->where('is_confirmed', false)
            ->where('status', '!=', WmsShortage::STATUS_BEFORE)
            ->distinct()
            ->pluck('warehouse_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->cachedWarehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get(['id', 'name']);

        return [
            'ids' => $warehouseIds,
            'warehouses' => $this->cachedWarehouses,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmAll')
                ->label('全承認')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('全件承認しますか？')
                ->modalDescription('承認待ちの欠品対応を全て承認します。')
                ->action(function () {
                    $shortages = WmsShortage::where('is_confirmed', false)
                        ->where('status', '!=', WmsShortage::STATUS_BEFORE)
                        ->get();

                    if ($shortages->isEmpty()) {
                        Notification::make()
                            ->title('承認対象なし')
                            ->body('承認待ちのレコードがありません。')
                            ->warning()
                            ->send();

                        return;
                    }

                    $count = 0;
                    $totalAllocationsConfirmed = 0;
                    $queueCreated = 0;

                    $approvalService = app(ShortageApprovalService::class);

                    foreach ($shortages as $shortage) {
                        try {
                            $result = $approvalService->approveShortage(
                                shortage: $shortage,
                                confirmedUserId: auth()->id() ?? 0,
                                markPickingResultReadyForShipment: false,
                            );

                            if (! $result['confirmed']) {
                                continue;
                            }

                            $count++;
                            $totalAllocationsConfirmed += $result['allocations_confirmed'];
                            if ($result['queue']) {
                                $queueCreated++;
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body("欠品ID {$shortage->id} の処理に失敗: {$e->getMessage()}")
                                ->danger()
                                ->send();
                        }
                    }

                    $message = "{$count}件の欠品対応を承認しました";
                    if ($totalAllocationsConfirmed > 0) {
                        $message .= "（代理出荷{$totalAllocationsConfirmed}件承認）";
                    }
                    if ($queueCreated > 0) {
                        $message .= "（在庫更新キュー{$queueCreated}件作成）";
                    }

                    Notification::make()
                        ->title('全承認完了')
                        ->body($message)
                        ->success()
                        ->send();
                }),
        ];
    }
}
