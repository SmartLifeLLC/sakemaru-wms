<?php

namespace App\Filament\Resources\ExpirationAlerts\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\ExpirationAlerts\ExpirationAlertResource;
use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListExpirationAlerts extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = ExpirationAlertResource::class;

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        $cacheKey = 'expiration_alerts_warehouses_'.auth()->id();
        $warehouseIds = cache()->remember($cacheKey, 30, function () {
            return DB::connection('sakemaru')
                ->table('real_stock_lots')
                ->join('real_stocks', 'real_stocks.id', '=', 'real_stock_lots.real_stock_id')
                ->where('real_stock_lots.status', 'ACTIVE')
                ->where('real_stock_lots.current_quantity', '>', 0)
                ->whereNotNull('real_stock_lots.expiration_date')
                ->where(function ($q) {
                    $q->whereRaw('real_stock_lots.expiration_date < CURDATE()')
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('real_stock_lots.alert_date')
                                ->whereRaw('real_stock_lots.alert_date <= CURDATE()');
                        });
                })
                ->distinct()
                ->pluck('real_stocks.warehouse_id')
                ->toArray();
        });

        $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('code')->get();
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('real_stocks.warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $views['all'] = PresetView::make()
            ->favorite()
            ->label('全て');

        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('real_stocks.warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
