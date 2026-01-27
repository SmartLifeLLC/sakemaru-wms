<?php

namespace App\Filament\Resources\WmsOrderDataFiles\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderDataFiles\WmsOrderDataFileResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderDataFile;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderDataFiles extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderDataFileResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'contractor', 'downloadedByUser'])
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('contractor_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 発注データファイルに存在する倉庫を取得
        $warehouseIds = WmsOrderDataFile::distinct()
            ->pluck('warehouse_id')
            ->toArray();

        // 倉庫情報を取得
        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get();

        // デフォルト倉庫が発注データファイルに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);

        // プリセットビュー構築
        $views = [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        // 全ての倉庫タブを追加
        foreach ($warehouses as $warehouse) {
            $isDefault = $hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId;
            $views["warehouse_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name)
                ->default($isDefault);
        }

        return $views;
    }
}
