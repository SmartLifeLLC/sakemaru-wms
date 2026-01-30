<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsPickingWaitingResource;
use App\Models\WmsPicker;
use App\Models\WmsPickingAssignmentStrategy;
use App\Services\Picking\AssignPickersToTasksService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ListWmsPickingWaitings extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingWaitingResource::class;

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $user->loadMissing('warehouse');
        $defaultWarehouseId = $user->warehouse?->id;

        return [
            Action::make('assignPickers')
                ->label('ピッカー割り当て')
                ->icon('heroicon-o-user-group')
                ->color('primary')
                ->modalHeading('ピッカー一括割り当て')
                ->modalDescription('選択したピッカーに未割当タスクを自動的に割り当てます')
                ->modalSubmitActionLabel('割り当て実行')
                ->form([
                    Select::make('warehouse_id')
                        ->label('対象倉庫')
                        ->options(function () {
                            return DB::connection('sakemaru')
                                ->table('warehouses')
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"])
                                ->toArray();
                        })
                        ->default($defaultWarehouseId)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('picker_ids', []);
                            $set('strategy_id', null);
                        }),

                    CheckboxList::make('picker_ids')
                        ->label('ピッカー選択')
                        ->options(function (Get $get) {
                            $warehouseId = $get('warehouse_id');
                            if (! $warehouseId) {
                                return [];
                            }

                            // 選択倉庫で出勤中のピッカーを取得
                            return WmsPicker::where('current_warehouse_id', $warehouseId)
                                ->where('is_available_for_picking', true)
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($p) => [$p->id => "[{$p->code}] {$p->name}"])
                                ->toArray();
                        })
                        ->columns(2)
                        ->required()
                        ->helperText('出勤中で稼働可能なピッカーのみ表示されます'),

                    Select::make('strategy_id')
                        ->label('割当戦略')
                        ->options(function (Get $get) {
                            $warehouseId = $get('warehouse_id');
                            if (! $warehouseId) {
                                return [];
                            }

                            return WmsPickingAssignmentStrategy::where('warehouse_id', $warehouseId)
                                ->where('is_active', true)
                                ->orderBy('is_default', 'desc')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($s) => [
                                    $s->id => $s->name.($s->is_default ? ' (デフォルト)' : ''),
                                ])
                                ->toArray();
                        })
                        ->default(function (Get $get) {
                            $warehouseId = $get('warehouse_id');
                            if (! $warehouseId) {
                                return null;
                            }

                            return WmsPickingAssignmentStrategy::where('warehouse_id', $warehouseId)
                                ->where('is_default', true)
                                ->where('is_active', true)
                                ->value('id');
                        })
                        ->required()
                        ->helperText('タスクの割り当て方法を選択します'),
                ])
                ->action(function (array $data) {
                    $service = new AssignPickersToTasksService;

                    $result = $service->execute(
                        warehouseId: $data['warehouse_id'],
                        pickerIds: $data['picker_ids'],
                        strategyId: $data['strategy_id']
                    );

                    if ($result['success']) {
                        Notification::make()
                            ->title('割り当て完了')
                            ->body($result['message'])
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('割り当てエラー')
                            ->body($result['message'])
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function getPresetViews(): array
    {
        $user = Auth::user();
        $user->loadMissing('warehouse');

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $user->warehouse->id))
                ->label($user->warehouse->name)
                ->favorite()
                ->default(),

            'with_shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('warehouse_id', $user->warehouse->id)
                    ->whereHas('pickingItemResults', fn ($q) => $q->where('has_soft_shortage', true)))
                ->label('引当欠品あり')
                ->favorite(),

            'all' => PresetView::make()
                ->label('全データ')
                ->favorite(),
        ];
    }

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table, isWaitingView: true);
    }
}
