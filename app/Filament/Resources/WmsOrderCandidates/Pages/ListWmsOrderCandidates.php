<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderCandidates\WmsOrderCandidateResource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\ContractorLeadTimeService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderCandidates extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('発注追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('発注候補を追加')
                ->modalWidth('lg')
                ->schema([
                    Select::make('warehouse_id')
                        ->label('在庫拠点倉庫')
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

                    TextInput::make('order_quantity')
                        ->label('発注数')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                ])
                ->action(function (array $data) {
                    // 最新のバッチコードを取得（なければ新規生成）
                    $batchCode = WmsOrderCandidate::orderBy('batch_code', 'desc')->value('batch_code')
                        ?? now()->format('YmdHis');

                    // 同じ倉庫・商品の組み合わせが既に存在するかチェック
                    $exists = WmsOrderCandidate::where('warehouse_id', $data['warehouse_id'])
                        ->where('item_id', $data['item_id'])
                        ->where('status', CandidateStatus::PENDING)
                        ->exists();

                    if ($exists) {
                        Notification::make()
                            ->title('エラー')
                            ->body('この倉庫・商品の組み合わせは既に発注候補に存在します')
                            ->danger()
                            ->send();
                        return;
                    }

                    // item_contractorsから発注先を取得
                    $itemContractor = ItemContractor::where('warehouse_id', $data['warehouse_id'])
                        ->where('item_id', $data['item_id'])
                        ->first();

                    if (!$itemContractor) {
                        Notification::make()
                            ->title('エラー')
                            ->body('この倉庫・商品の組み合わせに対する発注先が設定されていません')
                            ->danger()
                            ->send();
                        return;
                    }

                    // 発注先を取得してリードタイムから入荷予定日を計算
                    $contractor = Contractor::find($itemContractor->contractor_id);
                    $leadTimeService = app(ContractorLeadTimeService::class);
                    $arrivalInfo = $leadTimeService->calculateArrivalDate($contractor, now());
                    $expectedArrivalDate = $arrivalInfo['arrival_date'];
                    $leadTimeDays = $arrivalInfo['lead_time_days'] ?? 0;

                    // 発注候補を作成
                    WmsOrderCandidate::create([
                        'batch_code' => $batchCode,
                        'warehouse_id' => $data['warehouse_id'],
                        'item_id' => $data['item_id'],
                        'contractor_id' => $itemContractor->contractor_id,
                        'self_shortage_qty' => 0,
                        'satellite_demand_qty' => 0,
                        'suggested_quantity' => $data['order_quantity'],
                        'order_quantity' => $data['order_quantity'],
                        'expected_arrival_date' => $expectedArrivalDate,
                        'original_arrival_date' => $expectedArrivalDate,
                        'status' => CandidateStatus::PENDING,
                        'lot_status' => LotStatus::RAW,
                        'is_manually_modified' => true,
                        'modified_by' => auth()->id(),
                        'modified_at' => now(),
                    ]);

                    // 計算ログを作成（手動追加として記録）
                    WmsOrderCalculationLog::create([
                        'batch_code' => $batchCode,
                        'warehouse_id' => $data['warehouse_id'],
                        'item_id' => $data['item_id'],
                        'calculation_type' => CalculationType::EXTERNAL,
                        'contractor_id' => $itemContractor->contractor_id,
                        'source_warehouse_id' => null,
                        'current_effective_stock' => 0,
                        'incoming_quantity' => 0,
                        'safety_stock_setting' => 0,
                        'lead_time_days' => $leadTimeDays,
                        'calculated_shortage_qty' => $data['order_quantity'],
                        'calculated_order_quantity' => $data['order_quantity'],
                        'calculation_details' => [
                            'manual_entry' => true,
                            'created_by' => auth()->id(),
                            'created_at' => now()->toDateTimeString(),
                            'formula' => '手動追加',
                        ],
                    ]);

                    Notification::make()
                        ->title('発注候補を追加しました')
                        ->success()
                        ->send();
                }),

            Action::make('transmitOrders')
                ->label('発注送信')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('発注データの送信')
                ->modalDescription('承認済みの発注候補をJX-FINETまたはFTPで送信します。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                                ->distinct()
                                ->pluck('batch_code', 'batch_code');
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    // TODO: Phase 5で実装
                    Notification::make()
                        ->title('発注送信機能は Phase 5 で実装予定です')
                        ->warning()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'warehouse',
                    'item',
                    'contractor',
                ])
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 発注候補に存在する倉庫を取得してタブを生成
        $warehouseIds = WmsOrderCandidate::distinct()->pluck('warehouse_id')->toArray();
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('name')->get();

        // デフォルト倉庫が発注候補に存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? Warehouse::find($userDefaultWarehouseId) : null;

        // デフォルトタブ：デフォルト倉庫があればその倉庫名とフィルタ、なければ「全て」
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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
