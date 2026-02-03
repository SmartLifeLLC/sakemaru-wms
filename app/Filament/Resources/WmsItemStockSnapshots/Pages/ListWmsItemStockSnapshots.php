<?php

namespace App\Filament\Resources\WmsItemStockSnapshots\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsItemStockSnapshots\WmsItemStockSnapshotResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsItemStockSnapshot;
use App\Services\AutoOrder\StockSnapshotService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
        return [
            Action::make('regenerate')
                ->label('スナップショット生成')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('スナップショット生成')
                ->modalDescription('最新の在庫データからスナップショットを生成します。よろしいですか？')
                ->modalSubmitActionLabel('生成する')
                ->action(function () {
                    try {
                        $service = app(StockSnapshotService::class);
                        $job = $service->generateAll();

                        Notification::make()
                            ->title('スナップショット生成完了')
                            ->body("処理件数: {$job->processed_count} 件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('スナップショット生成失敗')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
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

        // プリセットビュー構築（データがなくても「全て」タブは常に表示）
        $views = [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse || empty($warehouses)),
        ];

        // 倉庫タブを追加
        foreach ($warehouses as $warehouse) {
            $isDefault = $hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId;
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name)
                ->default($isDefault);
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
