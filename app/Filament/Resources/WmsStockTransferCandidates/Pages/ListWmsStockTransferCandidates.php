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
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
        // 承認済み件数を取得
        $approvedCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();

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
                        ->label('移動元倉庫')
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

                    DatePicker::make('expected_arrival_date')
                        ->label('移動出荷日')
                        ->default(now()->addDay())
                        ->required(),
                ])
                ->action(function (array $data) {
                    // 依頼倉庫と移動元倉庫が同じ場合はエラー
                    if ($data['satellite_warehouse_id'] === $data['hub_warehouse_id']) {
                        Notification::make()
                            ->title('エラー')
                            ->body('依頼倉庫と移動元倉庫を同じにすることはできません')
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
                        'expected_arrival_date' => $data['expected_arrival_date'],
                        'original_arrival_date' => $data['expected_arrival_date'],
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

            Action::make('executeAllApproved')
                ->label("承認済み移動伝票生成 ({$approvedCount}件)")
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->disabled($approvedCount === 0)
                ->requiresConfirmation()
                ->modalHeading('承認済み移動候補から移動伝票を生成')
                ->modalDescription('全ての承認済み移動候補から移動伝票を生成します。移動元倉庫＋移動先倉庫＋配送コースでグループ化して処理します。')
                ->action(function () {
                    $service = app(TransferCandidateExecutionService::class);
                    $result = $service->executeAllApprovedGrouped(auth()->id());

                    if ($result['candidate_count'] > 0) {
                        Notification::make()
                            ->title('移動伝票生成が完了しました')
                            ->body("{$result['candidate_count']}件の移動候補から{$result['queue_count']}件の移動伝票を生成しました")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('生成対象がありません')
                            ->body('承認済みの移動候補がありません')
                            ->warning()
                            ->send();
                    }

                    if (! empty($result['errors'])) {
                        Notification::make()
                            ->title('一部エラーが発生しました')
                            ->body(count($result['errors']).'件のグループで失敗しました')
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
                ->with([
                    'satelliteWarehouse',
                    'hubWarehouse',
                    'deliveryCourse',
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
        // ステータス別の件数を取得
        $pendingCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
        $approvedCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();
        $executedCount = WmsStockTransferCandidate::where('status', CandidateStatus::EXECUTED)->count();

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::PENDING))
                ->favorite()
                ->label("承認前 ({$pendingCount})")
                ->default(),

            'approved' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::APPROVED))
                ->favorite()
                ->label("承認済 ({$approvedCount})"),

            'executed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::EXECUTED))
                ->favorite()
                ->label("実行完了 ({$executedCount})"),

            'all' => PresetView::make()
                ->favorite()
                ->label('全て'),
        ];
    }
}
