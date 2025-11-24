<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Filament\Resources\DeliveryCourseChangeResource\Pages;
use App\Models\Sakemaru\Trade;
use App\Services\DeliveryCourseChangeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryCourseChangeResource extends Resource
{
    protected static ?string $model = Trade::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::DELIVERY_COURSE_CHANGE->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::DELIVERY_COURSE_CHANGE->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::DELIVERY_COURSE_CHANGE->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // PENDING状態のpicking_taskに紐づくtradesを取得
        return Trade::query()
            ->join('wms_picking_item_results as pir', 'trades.id', '=', 'pir.trade_id')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('partners as p', 'trades.partner_id', '=', 'p.id')
            ->join('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->where('pt.status', 'PENDING')
            ->select([
                'trades.id',
                'trades.serial_id',
                'trades.partner_id',
                'p.code as partner_code',
                'p.name as partner_name',
                'dc.id as current_course_id',
                'dc.code as current_course_code',
                'dc.name as current_course_name',
                'pt.warehouse_id',
                'pt.shipment_date',
            ])
            ->distinct();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_id')
                    ->label('伝票番号')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('partner_code')
                    ->label('得意先コード')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('partner_name')
                    ->label('得意先名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('current_course_code')
                    ->label('配送コースコード')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('current_course_name')
                    ->label('配送コース名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('shipment_date')
                    ->label('出荷日')
                    ->date('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('partner_id')
                    ->label('得意先')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('partners')
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($partner) => [$partner->id => "[{$partner->code}] {$partner->name}"])
                            ->toArray();
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('changeDeliveryCourse')
                    ->label('配送コース変更')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Select::make('new_course_id')
                            ->label('変更先配送コース')
                            ->options(function ($record) {
                                // 倉庫に紐づく配送コースを取得
                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $record->warehouse_id)
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(function ($course) {
                                        return [$course->id => "{$course->code} - {$course->name}"];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(DeliveryCourseChangeService::class);

                        try {
                            $result = $service->changeDeliveryCourse($record->id, $data['new_course_id']);

                            Notification::make()
                                ->title('配送コース変更完了')
                                ->success()
                                ->body("伝票番号 {$record->serial_id} の配送コースを変更しました。")
                                ->send();

                            return $result;
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('配送コース変更失敗')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();

                            throw $e;
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('配送コース変更確認')
                    ->modalDescription('この伝票の配送コースを変更します。よろしいですか？')
                    ->modalSubmitActionLabel('変更する'),
            ])
            ->bulkActions([
                BulkAction::make('bulkChangeDeliveryCourse')
                    ->label('一括配送コース変更')
                    ->icon('heroicon-o-arrow-path')
                    ->fillForm(function (Collection $records) {
                        // Use the first record's warehouse_id to pre-load courses
                        $firstWarehouseId = $records->first()?->warehouse_id;
                        return [
                            'warehouse_id' => $firstWarehouseId,
                        ];
                    })
                    ->form([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(\App\Models\Sakemaru\Warehouse::where('is_active', true)->pluck('name', 'id'))
                            ->disabled()
                            ->dehydrated(true)
                            ->hidden(),
                        Select::make('new_course_id')
                            ->label('変更先配送コース')
                            ->options(function ($get) {
                                $warehouseId = $get('warehouse_id');
                                if (!$warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $warehouseId)
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(function ($course) {
                                        return [$course->id => "{$course->code} - {$course->name}"];
                                    })
                                    ->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $service = app(DeliveryCourseChangeService::class);
                        $tradeIds = $records->pluck('id')->toArray();

                        try {
                            $result = $service->bulkChangeDeliveryCourse($tradeIds, $data['new_course_id']);

                            Notification::make()
                                ->title('一括配送コース変更完了')
                                ->success()
                                ->body("{$result['success_count']}件の配送コースを変更しました。")
                                ->send();

                            if ($result['failure_count'] > 0) {
                                Notification::make()
                                    ->title('一部変更失敗')
                                    ->warning()
                                    ->body("{$result['failure_count']}件の変更に失敗しました。")
                                    ->send();
                            }

                            return $result;
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('一括配送コース変更失敗')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();

                            throw $e;
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('一括配送コース変更確認')
                    ->modalDescription('選択した伝票の配送コースを一括で変更します。よろしいですか？')
                    ->modalSubmitActionLabel('一括変更する')
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryCourseChanges::route('/'),
        ];
    }
}
