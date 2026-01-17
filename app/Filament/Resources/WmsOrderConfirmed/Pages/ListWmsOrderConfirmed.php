<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmed\WmsOrderConfirmedResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderConfirmed extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderConfirmedResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 発注確定済みに存在する倉庫を取得
        $warehouseIds = WmsOrderCandidate::whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        // デフォルト倉庫が発注確定済みに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? Warehouse::find($userDefaultWarehouseId) : null;

        // プリセットビュー構築
        $views = [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),

            'confirmed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::CONFIRMED))
                ->favorite()
                ->label('確定済み'),

            'executed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::EXECUTED))
                ->favorite()
                ->label('送信済み'),
        ];

        // デフォルト倉庫があればその倉庫タブを追加
        if ($defaultWarehouse) {
            $views["warehouse_{$defaultWarehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $defaultWarehouse->id))
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        }

        return $views;
    }
}
