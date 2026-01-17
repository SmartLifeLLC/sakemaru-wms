<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingCompleted\WmsIncomingCompletedResource;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingCompleted extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingCompletedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transmitPurchase')
                ->label('仕入データ登録')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading('仕入データ登録')
                ->modalDescription('入庫完了データを基幹システムの仕入キューに登録します。同一の倉庫・仕入先・入庫日ごとに1伝票としてまとめられます。登録後はデータの修正ができなくなります。')
                ->requiresConfirmation()
                ->modalSubmitActionLabel('登録')
                ->action(function () {
                    $transmissionService = app(IncomingTransmissionService::class);

                    try {
                        $result = $transmissionService->transmitConfirmedIncomings();

                        if ($result['success']) {
                            Notification::make()
                                ->title('仕入キューに登録しました')
                                ->body("キュー: {$result['queue_count']}件 / 入庫データ: {$result['schedule_count']}件")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('一部エラーが発生しました')
                                ->body("成功: {$result['schedule_count']}件 / エラー: ".count($result['errors']).'件')
                                ->warning()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('登録エラー')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)->exists()),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('confirmed_at', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'today' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('actual_arrival_date', today()))
                ->favorite()
                ->label('本日入庫'),

            'auto' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_source', 'AUTO'))
                ->favorite()
                ->label('自動発注'),

            'manual' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_source', 'MANUAL'))
                ->favorite()
                ->label('手動発注'),
        ];
    }
}
