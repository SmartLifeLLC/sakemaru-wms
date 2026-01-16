<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderIncomingSchedules\WmsOrderIncomingScheduleResource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Services\AutoOrder\OrderExecutionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderIncomingSchedules extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderIncomingScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createManual')
                ->label('手動入庫予定追加')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->modalHeading('手動入庫予定を追加')
                ->modalWidth('lg')
                ->schema([
                    Select::make('warehouse_id')
                        ->label('入庫倉庫')
                        ->options(fn () => Warehouse::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('item_id')
                        ->label('商品')
                        ->options(fn () => Item::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($i) => [$i->id => "[{$i->code}]{$i->name}"]))
                        ->searchable()
                        ->required(),

                    Select::make('contractor_id')
                        ->label('発注先')
                        ->options(fn () => Contractor::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"]))
                        ->searchable()
                        ->required(),

                    TextInput::make('expected_quantity')
                        ->label('予定数量')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Select::make('quantity_type')
                        ->label('数量タイプ')
                        ->options([
                            'PIECE' => 'バラ',
                            'CASE' => 'ケース',
                            'CARTON' => 'ボール',
                        ])
                        ->default('PIECE')
                        ->required(),

                    DatePicker::make('expected_arrival_date')
                        ->label('入庫予定日')
                        ->required()
                        ->default(now()->addDays(3)),

                    TextInput::make('order_number')
                        ->label('発注番号')
                        ->maxLength(50),

                    Textarea::make('note')
                        ->label('備考')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $service = app(OrderExecutionService::class);

                    try {
                        $service->createManualIncomingSchedule([
                            'warehouse_id' => $data['warehouse_id'],
                            'item_id' => $data['item_id'],
                            'contractor_id' => $data['contractor_id'],
                            'expected_quantity' => $data['expected_quantity'],
                            'quantity_type' => $data['quantity_type'],
                            'expected_arrival_date' => $data['expected_arrival_date'],
                            'order_number' => $data['order_number'] ?? null,
                            'note' => $data['note'] ?? null,
                        ], auth()->id());

                        Notification::make()
                            ->title('入庫予定を追加しました')
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
                ->with([
                    'warehouse',
                    'item',
                    'contractor',
                    'supplier',
                ])
                ->orderBy('expected_arrival_date', 'asc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'pending' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', IncomingScheduleStatus::PENDING))
                ->favorite()
                ->label('未入庫')
                ->default(),

            'partial' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', IncomingScheduleStatus::PARTIAL))
                ->favorite()
                ->label('一部入庫'),

            'not_completed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    IncomingScheduleStatus::PENDING,
                    IncomingScheduleStatus::PARTIAL,
                ]))
                ->favorite()
                ->label('未完了'),

            'confirmed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', IncomingScheduleStatus::CONFIRMED))
                ->favorite()
                ->label('入庫完了'),

            'all' => PresetView::make()
                ->favorite()
                ->label('全て'),
        ];
    }
}
