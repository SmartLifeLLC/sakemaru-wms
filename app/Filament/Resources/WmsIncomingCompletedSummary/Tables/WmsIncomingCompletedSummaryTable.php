<?php

namespace App\Filament\Resources\WmsIncomingCompletedSummary\Tables;

use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\WmsOrderIncomingSchedule;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingCompletedSummaryTable
{
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-completed-summary-table sticky-actions'])
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('入荷倉庫')
                    ->searchable()
                    ->placeholder('-')
                    ->width('160px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(32)
                    ->tooltip(fn ($state) => $state)
                    ->grow(),

                TextColumn::make('summary_detail_count')
                    ->label('明細数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_item_count')
                    ->label('商品数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_slip_count')
                    ->label('伝票数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_expected_period')
                    ->label('予定日')
                    ->state(fn (WmsOrderIncomingSchedule $record): string => self::formatPeriod(
                        $record->summary_expected_from,
                        $record->summary_expected_until,
                    ))
                    ->alignCenter()
                    ->width('130px'),

                TextColumn::make('summary_actual_period')
                    ->label('入荷日')
                    ->state(fn (WmsOrderIncomingSchedule $record): string => self::formatPeriod(
                        $record->summary_actual_from,
                        $record->summary_actual_until,
                    ))
                    ->alignCenter()
                    ->width('130px'),

                TextColumn::make('summary_last_confirmed_at')
                    ->label('データ連携時刻')
                    ->formatStateUsing(fn ($state): string => self::formatDateTime($state))
                    ->alignCenter()
                    ->width('130px'),

                TextColumn::make('summary_transmission_state')
                    ->label('仕入連携')
                    ->state(fn (WmsOrderIncomingSchedule $record): string => self::transmissionStateLabel($record))
                    ->badge()
                    ->color(fn (WmsOrderIncomingSchedule $record): string => self::transmissionStateColor($record))
                    ->alignCenter()
                    ->width('110px'),

                TextColumn::make('summary_untransmitted_count')
                    ->label('未連携')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('summary_transmitted_count')
                    ->label('連携済')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px'),
            ])
            ->filters([
                static::warehouseFilter(),
                static::contractorFilter(),
                SelectFilter::make('purchase_transmission_state')
                    ->label('仕入連携')
                    ->options([
                        'untransmitted' => '未連携あり',
                        'transmitted' => '連携済みのみ',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'untransmitted' => $query->whereNull('purchase_queue_id'),
                        'transmitted' => $query->whereNotNull('purchase_queue_id'),
                        default => $query,
                    }),
                Filter::make('expected_arrival_date')
                    ->label('予定日')
                    ->form([
                        DatePicker::make('from')
                            ->label('予定日 From'),
                        DatePicker::make('until')
                            ->label('予定日 To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('expected_arrival_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('expected_arrival_date', '<=', $date))),
                Filter::make('confirmed_at')
                    ->label('データ連携時刻')
                    ->form([
                        DatePicker::make('from')
                            ->label('連携日 From'),
                        DatePicker::make('until')
                            ->label('連携日 To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('confirmed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('confirmed_at', '<=', $date))),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordAction('viewDetails')
            ->recordUrl(null)
            ->recordActions([
                Action::make('viewDetails')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (?WmsOrderIncomingSchedule $record): string => self::detailHeading($record))
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalContent(fn (?WmsOrderIncomingSchedule $record) => view(
                        'filament.components.incoming-completed-summary-detail',
                        [
                            'summary' => self::summaryData($record),
                            'details' => self::detailRows($record),
                        ],
                    )),
            ], position: RecordActionsPosition::BeforeColumns)
            ->defaultKeySort(false)
            ->defaultSort(
                fn (Builder $query): Builder => $query
                    ->orderByDesc('summary_last_confirmed_at')
                    ->orderBy('warehouse_id')
                    ->orderBy('contractor_id'),
                'desc'
            );
    }

    private static function detailHeading(?WmsOrderIncomingSchedule $record): string
    {
        if (! $record) {
            return '入荷完了明細';
        }

        $contractor = $record->contractor;
        $contractorName = $contractor
            ? "[{$contractor->code}]{$contractor->name}"
            : '発注先不明';

        return "入荷完了明細: {$contractorName}";
    }

    private static function summaryData(?WmsOrderIncomingSchedule $record): array
    {
        if (! $record) {
            return [
                'warehouse' => '-',
                'contractor' => '-',
                'detail_count' => 0,
                'item_count' => 0,
                'slip_count' => 0,
                'expected_period' => '-',
                'actual_period' => '-',
                'last_confirmed_at' => '-',
                'transmission_state' => '-',
            ];
        }

        return [
            'warehouse' => self::warehouseLabel($record),
            'contractor' => self::contractorLabel($record),
            'detail_count' => (int) ($record->summary_detail_count ?? 0),
            'item_count' => (int) ($record->summary_item_count ?? 0),
            'slip_count' => (int) ($record->summary_slip_count ?? 0),
            'expected_period' => self::formatPeriod($record->summary_expected_from, $record->summary_expected_until),
            'actual_period' => self::formatPeriod($record->summary_actual_from, $record->summary_actual_until),
            'last_confirmed_at' => self::formatDateTime($record->summary_last_confirmed_at),
            'transmission_state' => self::transmissionStateLabel($record),
        ];
    }

    private static function detailRows(?WmsOrderIncomingSchedule $record): array
    {
        if (! $record) {
            return [];
        }

        return WmsOrderIncomingSchedule::query()
            ->confirmed()
            ->withoutTransferSource()
            ->where('warehouse_id', $record->warehouse_id)
            ->where('contractor_id', $record->contractor_id)
            ->with(['warehouse', 'contractor', 'item', 'location', 'confirmedByUser', 'confirmedByPicker'])
            ->orderByDesc('confirmed_at')
            ->orderBy('slip_number')
            ->orderBy('id')
            ->get()
            ->map(fn (WmsOrderIncomingSchedule $detail): array => self::detailRow($detail))
            ->values()
            ->all();
    }

    private static function detailRow(WmsOrderIncomingSchedule $record): array
    {
        $quantities = self::quantityData($record);

        return [
            'id' => $record->id,
            'slip_number' => $record->slip_number ?: '-',
            'order_source' => self::orderSourceLabel($record),
            'order_date' => self::formatDate($record->order_date),
            'expected_arrival_date' => self::formatDate($record->expected_arrival_date),
            'actual_arrival_date' => self::formatDate($record->actual_arrival_date),
            'confirmed_at' => self::formatDateTime($record->confirmed_at),
            'item_code' => $record->item_code ?: $record->item?->code ?: '-',
            'search_code' => $record->search_code ?: '-',
            'item_name' => $record->item?->name ?: '-',
            'capacity_case' => self::numberOrDash($quantities['capacity_case']),
            'expected_case_quantity' => self::numberOrDash($quantities['expected_case_quantity']),
            'expected_piece_quantity' => self::numberOrDash($quantities['expected_piece_quantity']),
            'expected_total_piece_quantity' => self::numberOrDash($record->expected_piece_quantity),
            'received_total_piece_quantity' => self::numberOrDash($record->received_piece_quantity),
            'shortage_quantity' => self::numberOrDash($record->shortage_quantity),
            'expiration_date' => self::formatDate($record->expiration_date),
            'location' => self::locationLabel($record),
            'confirmed_by' => $record->confirmedByUser?->name ?? $record->confirmedByPicker?->name ?? '-',
            'purchase_state' => $record->purchase_queue_id ? '連携済' : '未連携',
            'purchase_slip_number' => $record->purchase_slip_number ?: '-',
        ];
    }

    private static function quantityData(WmsOrderIncomingSchedule $record): array
    {
        $capacity = max(1, (int) ($record->item?->capacity_case ?? 1));
        $quantity = (int) ($record->expected_quantity ?? 0);

        if ($record->quantity_type === QuantityType::CASE) {
            return [
                'capacity_case' => $capacity,
                'expected_case_quantity' => $quantity,
                'expected_piece_quantity' => 0,
            ];
        }

        if ($capacity <= 1) {
            return [
                'capacity_case' => $capacity,
                'expected_case_quantity' => 0,
                'expected_piece_quantity' => $quantity,
            ];
        }

        return [
            'capacity_case' => $capacity,
            'expected_case_quantity' => intdiv($quantity, $capacity),
            'expected_piece_quantity' => $quantity % $capacity,
        ];
    }

    private static function transmissionStateLabel(WmsOrderIncomingSchedule $record): string
    {
        $untransmitted = (int) ($record->summary_untransmitted_count ?? 0);
        $transmitted = (int) ($record->summary_transmitted_count ?? 0);

        if ($untransmitted > 0 && $transmitted > 0) {
            return '一部未連携';
        }

        if ($untransmitted > 0) {
            return '未連携';
        }

        return '連携済';
    }

    private static function transmissionStateColor(WmsOrderIncomingSchedule $record): string
    {
        return match (self::transmissionStateLabel($record)) {
            '未連携' => 'warning',
            '一部未連携' => 'info',
            default => 'success',
        };
    }

    private static function orderSourceLabel(WmsOrderIncomingSchedule $record): string
    {
        if ($record->isUnassignedJxReceived()) {
            return '不明';
        }

        $source = $record->order_source;

        if ($source instanceof OrderSource) {
            return match ($source) {
                OrderSource::AUTO => '発注',
                OrderSource::MANUAL => '手動',
                OrderSource::TRANSFER => '移動',
                OrderSource::RECEIVED => '受信',
            };
        }

        return OrderSource::tryFrom((string) $source)?->label() ?? '-';
    }

    private static function warehouseLabel(WmsOrderIncomingSchedule $record): string
    {
        return $record->warehouse
            ? "[{$record->warehouse->code}]{$record->warehouse->name}"
            : '-';
    }

    private static function contractorLabel(WmsOrderIncomingSchedule $record): string
    {
        return $record->contractor
            ? "[{$record->contractor->code}]{$record->contractor->name}"
            : '-';
    }

    private static function locationLabel(WmsOrderIncomingSchedule $record): string
    {
        if (! $record->location) {
            return '-';
        }

        return collect([$record->location->code1, $record->location->code2, $record->location->code3])
            ->filter(fn ($code): bool => filled($code))
            ->implode('-') ?: '-';
    }

    private static function formatPeriod($from, $until): string
    {
        $fromText = self::formatDate($from);
        $untilText = self::formatDate($until);

        if ($fromText === '-' && $untilText === '-') {
            return '-';
        }

        if ($fromText === $untilText) {
            return $fromText;
        }

        return "{$fromText} - {$untilText}";
    }

    private static function formatDate($value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return $value instanceof Carbon
                ? $value->format('Y/m/d')
                : Carbon::parse($value)->format('Y/m/d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function formatDateTime($value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            return $value instanceof Carbon
                ? $value->format('Y/m/d H:i')
                : Carbon::parse($value)->format('Y/m/d H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function numberOrDash($value): string
    {
        $number = (int) ($value ?? 0);

        return $number > 0 ? number_format($number) : '-';
    }
}
