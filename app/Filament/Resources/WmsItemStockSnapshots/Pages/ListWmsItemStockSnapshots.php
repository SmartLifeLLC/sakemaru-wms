<?php

namespace App\Filament\Resources\WmsItemStockSnapshots\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsItemStockSnapshots\WmsItemStockSnapshotResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsItemStockSnapshot;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsItemStockSnapshots extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsItemStockSnapshotResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'item', 'jobControl'])
            );
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 最新のスナップショットジョブを取得
        $latestJobId = WmsAutoOrderJobControl::query()
            ->where('process_name', 'STOCK_SNAPSHOT')
            ->orderByDesc('id')
            ->value('id');

        // スナップショットに存在する倉庫を取得
        $warehouseIds = WmsItemStockSnapshot::query()
            ->when($latestJobId, fn ($q) => $q->where('job_control_id', $latestJobId))
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        // 倉庫情報を取得
        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get();

        // デフォルト倉庫がスナップショットに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // プリセットビュー構築（データがなくても「全て」タブは常に表示）
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
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

        // 倉庫タブを追加
        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    public function getSubheading(): ?string
    {
        // 最新のスナップショットジョブを取得
        $latestJob = WmsAutoOrderJobControl::query()
            ->where('process_name', 'STOCK_SNAPSHOT')
            ->orderByDesc('id')
            ->first();

        if ($latestJob) {
            return '最新スナップショット: '.$latestJob->batch_code.' ('.$latestJob->started_at?->format('Y年m月d日 H:i:s').')';
        }

        return 'スナップショットがありません';
    }
}
