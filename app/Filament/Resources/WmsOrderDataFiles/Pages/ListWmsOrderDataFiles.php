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
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class ListWmsOrderDataFiles extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderDataFileResource::class;

    #[Url(as: 'tab')]
    public string $fileTypeTab = 'production';

    /**
     * プリセットビュー用倉庫データのキャッシュ
     * getPresetViews()が複数回呼び出されるため、リクエスト内でキャッシュする
     */
    protected ?Collection $cachedWarehouses = null;

    public function getView(): string
    {
        return 'filament.resources.wms-order-data-files.pages.list-wms-order-data-files';
    }

    public function table(Table $table): Table
    {
        $isTest = $this->fileTypeTab === 'test';

        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'contractor', 'downloadedByUser'])
                ->where('is_test', $isTest)
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('contractor_id')
            );
    }

    public function setFileTypeTab(string $tab): void
    {
        $this->redirect(
            static::getResource()::getUrl('index', ['tab' => $tab]),
            navigate: true
        );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 倉庫データをキャッシュから取得（リクエスト内で複数回呼び出されるため）
        $warehouses = $this->getWarehousesForPresetViews();
        $warehouseIds = $warehouses->pluck('id')->toArray();

        // デフォルト倉庫が発注データファイルに存在するかチェック
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

        // 倉庫タブを追加（データがある場合のみ）
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

    /**
     * プリセットビュー用の倉庫データを取得（キャッシュ付き）
     */
    protected function getWarehousesForPresetViews(): Collection
    {
        if ($this->cachedWarehouses !== null) {
            return $this->cachedWarehouses;
        }

        $isTest = $this->fileTypeTab === 'test';
        $warehouseIds = WmsOrderDataFile::where('is_test', $isTest)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $this->cachedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get();

        return $this->cachedWarehouses;
    }
}
