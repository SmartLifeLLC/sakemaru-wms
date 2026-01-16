<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\WmsOrderConfirmationWaitingResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderExecutionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderConfirmationWaiting extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderConfirmationWaitingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmAll')
                ->label('発注確定')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->modalHeading('発注確定')
                ->modalDescription('承認済みの発注候補を確定し、入庫予定を作成します。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            // APPROVEDのバッチのみ表示
                            return WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                                ->distinct()
                                ->orderBy('batch_code', 'desc')
                                ->pluck('batch_code')
                                ->mapWithKeys(fn ($code) => [
                                    $code => \Carbon\Carbon::createFromFormat('YmdHis', $code)->format('Y/m/d H:i:s'),
                                ]);
                        })
                        ->required()
                        ->helperText('確定するバッチを選択してください'),
                ])
                ->action(function (array $data) {
                    $service = app(OrderExecutionService::class);

                    try {
                        $schedules = $service->confirmBatch($data['batch_code'], auth()->id());

                        if ($schedules->isEmpty()) {
                            Notification::make()
                                ->title('確定対象がありません')
                                ->body('選択したバッチに承認済みの発注候補がありません')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('発注を確定しました')
                            ->body("入庫予定 {$schedules->count()}件 を作成しました")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('エラーが発生しました')
                            ->body($e->getMessage())
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
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 発注確定待ち（APPROVED）に存在する倉庫を取得してタブを生成
        $warehouseIds = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        // デフォルト倉庫が発注確定待ちに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? Warehouse::find($userDefaultWarehouseId) : null;

        // プリセットビュー構築
        $views = [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
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
