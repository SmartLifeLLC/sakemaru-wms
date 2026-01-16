<?php

namespace App\Filament\Resources\WarehouseStockTransferDeliveryCourses\Pages;

use App\Filament\Resources\WarehouseStockTransferDeliveryCourses\WarehouseStockTransferDeliveryCourseResource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\Sakemaru\WarehouseStockTransferDeliveryCourse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListWarehouseStockTransferDeliveryCourses extends ListRecords
{
    protected static string $resource = WarehouseStockTransferDeliveryCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('新規作成')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->modalHeading('移動配送コース設定を追加')
                ->modalWidth('lg')
                ->schema([
                    Select::make('from_warehouse_id')
                        ->label('移動元倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('to_warehouse_id')
                        ->label('移動先倉庫')
                        ->options(fn () => Warehouse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('delivery_course_id')
                        ->label('配送コース')
                        ->options(fn () => DeliveryCourse::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"]))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data) {
                    // 同じ組み合わせが既に存在するかチェック
                    $exists = WarehouseStockTransferDeliveryCourse::where('from_warehouse_id', $data['from_warehouse_id'])
                        ->where('to_warehouse_id', $data['to_warehouse_id'])
                        ->exists();

                    if ($exists) {
                        Notification::make()
                            ->title('エラー')
                            ->body('この倉庫の組み合わせは既に登録されています')
                            ->danger()
                            ->send();

                        return;
                    }

                    WarehouseStockTransferDeliveryCourse::create([
                        'from_warehouse_id' => $data['from_warehouse_id'],
                        'to_warehouse_id' => $data['to_warehouse_id'],
                        'delivery_course_id' => $data['delivery_course_id'],
                        'creator_id' => auth()->id() ?? 0,
                        'last_updater_id' => auth()->id() ?? 0,
                    ]);

                    Notification::make()
                        ->title('作成しました')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fromWarehouse.name')
                    ->label('移動元倉庫')
                    ->state(fn ($record) => $record->fromWarehouse
                        ? "[{$record->fromWarehouse->code}] {$record->fromWarehouse->name}"
                        : '-')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('toWarehouse.name')
                    ->label('移動先倉庫')
                    ->state(fn ($record) => $record->toWarehouse
                        ? "[{$record->toWarehouse->code}] {$record->toWarehouse->name}"
                        : '-')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->state(fn ($record) => $record->deliveryCourse
                        ? "[{$record->deliveryCourse->code}] {$record->deliveryCourse->name}"
                        : '-')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
            ])
            ->defaultSort('from_warehouse_id', 'asc')
            ->recordActions([
                TableAction::make('edit')
                    ->label('編集')
                    ->icon('heroicon-o-pencil')
                    ->color('gray')
                    ->modalHeading('移動配送コース設定を編集')
                    ->modalWidth('lg')
                    ->fillForm(fn ($record) => [
                        'from_warehouse_id' => $record->from_warehouse_id,
                        'to_warehouse_id' => $record->to_warehouse_id,
                        'delivery_course_id' => $record->delivery_course_id,
                    ])
                    ->schema([
                        Select::make('from_warehouse_id')
                            ->label('移動元倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"]))
                            ->searchable()
                            ->required()
                            ->disabled(),

                        Select::make('to_warehouse_id')
                            ->label('移動先倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"]))
                            ->searchable()
                            ->required()
                            ->disabled(),

                        Select::make('delivery_course_id')
                            ->label('配送コース')
                            ->options(fn () => DeliveryCourse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'delivery_course_id' => $data['delivery_course_id'],
                            'last_updater_id' => auth()->id() ?? 0,
                        ]);

                        Notification::make()
                            ->title('更新しました')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->label('削除')
                    ->requiresConfirmation(),
            ]);
    }
}
