<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsStockTransferCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsStockTransferCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsStockTransferCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('移動追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('移動候補を追加')
                ->modalWidth('lg')
                ->schema([
                    Select::make('satellite_warehouse_id')
                        ->label('依頼倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('hub_warehouse_id')
                        ->label('横持ち出荷倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('item_id')
                        ->label('商品')
                        ->options(fn () => Item::query()
                            ->orderBy('code')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($i) => [$i->id => "[{$i->code}]{$i->name}"]))
                        ->searchable()
                        ->required(),

                    TextInput::make('transfer_quantity')
                        ->label('移動数')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                ])
                ->action(function (array $data) {
                    // 依頼倉庫と横持ち出荷倉庫が同じ場合はエラー
                    if ($data['satellite_warehouse_id'] === $data['hub_warehouse_id']) {
                        Notification::make()
                            ->title('エラー')
                            ->body('依頼倉庫と横持ち出荷倉庫を同じにすることはできません')
                            ->danger()
                            ->send();
                        return;
                    }

                    // 最新のバッチコードを取得（なければ新規生成）
                    $batchCode = WmsStockTransferCandidate::orderBy('batch_code', 'desc')->value('batch_code')
                        ?? now()->format('YmdHis');

                    // 同じ倉庫・商品の組み合わせが既に存在するかチェック
                    $exists = WmsStockTransferCandidate::where('satellite_warehouse_id', $data['satellite_warehouse_id'])
                        ->where('hub_warehouse_id', $data['hub_warehouse_id'])
                        ->where('item_id', $data['item_id'])
                        ->where('status', CandidateStatus::PENDING)
                        ->exists();

                    if ($exists) {
                        Notification::make()
                            ->title('エラー')
                            ->body('この倉庫・商品の組み合わせは既に移動候補に存在します')
                            ->danger()
                            ->send();
                        return;
                    }

                    // 移動候補を作成
                    WmsStockTransferCandidate::create([
                        'batch_code' => $batchCode,
                        'satellite_warehouse_id' => $data['satellite_warehouse_id'],
                        'hub_warehouse_id' => $data['hub_warehouse_id'],
                        'item_id' => $data['item_id'],
                        'contractor_id' => null,
                        'suggested_quantity' => $data['transfer_quantity'],
                        'transfer_quantity' => $data['transfer_quantity'],
                        'expected_arrival_date' => now()->addDays(1),
                        'original_arrival_date' => now()->addDays(1),
                        'status' => CandidateStatus::PENDING,
                        'lot_status' => LotStatus::RAW,
                        'is_manually_modified' => true,
                        'modified_by' => auth()->id(),
                        'modified_at' => now(),
                    ]);

                    // 計算ログを作成（手動追加として記録）
                    WmsOrderCalculationLog::create([
                        'batch_code' => $batchCode,
                        'warehouse_id' => $data['satellite_warehouse_id'],
                        'item_id' => $data['item_id'],
                        'calculation_type' => CalculationType::INTERNAL,
                        'contractor_id' => null,
                        'source_warehouse_id' => $data['hub_warehouse_id'],
                        'current_effective_stock' => 0,
                        'incoming_quantity' => 0,
                        'safety_stock_setting' => 0,
                        'lead_time_days' => 1,
                        'calculated_shortage_qty' => $data['transfer_quantity'],
                        'calculated_order_quantity' => $data['transfer_quantity'],
                        'calculation_details' => [
                            'manual_entry' => true,
                            'created_by' => auth()->id(),
                            'created_at' => now()->toDateTimeString(),
                            'formula' => '手動追加',
                        ],
                    ]);

                    Notification::make()
                        ->title('移動候補を追加しました')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'satelliteWarehouse',
                    'hubWarehouse',
                    'item',
                    'contractor',
                ])
                ->orderBy('batch_code', 'desc')
                ->orderBy('satellite_warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 移動候補に存在する在庫依頼倉庫（satellite）を取得してタブを生成
        $warehouseIds = WmsStockTransferCandidate::distinct()->pluck('satellite_warehouse_id')->toArray();
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('name')->get();

        // デフォルト倉庫が移動候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? Warehouse::find($userDefaultWarehouseId) : null;

        // デフォルトタブ：デフォルト倉庫があればその倉庫名とフィルタ、なければ「全て」
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'all' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        // 他の倉庫タブ（デフォルト倉庫は除外）
        foreach ($warehouses as $warehouse) {
            // デフォルト倉庫は既にdefaultタブで表示しているのでスキップ
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }

            $views["warehouse_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
