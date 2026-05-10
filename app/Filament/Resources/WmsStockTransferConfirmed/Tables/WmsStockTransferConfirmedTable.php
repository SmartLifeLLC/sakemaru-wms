<?php

namespace App\Filament\Resources\WmsStockTransferConfirmed\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Support\StockTransferSlipHistory;
use App\Models\Sakemaru\StockTransfer;
use App\Models\Sakemaru\StockTransferQueue;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsStockTransferConfirmedTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'stock-transfer-confirmed-table sticky-actions'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->state(fn ($record): string => $record->batch_code ?? static::batchCodeFromNote($record->note) ?? '-')
                    ->searchable(query: fn ($query, string $search) => $query->where('note', 'like', "%{$search}%"))
                    ->copyable()
                    ->width('140px'),

                TextColumn::make('candidate_creator_name')
                    ->label('担当者')
                    ->placeholder('システム')
                    ->searchable()
                    ->width('100px'),

                TextColumn::make('created_at')
                    ->label('確定日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('95px'),

                TextColumn::make('process_date')
                    ->label('処理日')
                    ->date('m/d')
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('delivered_date')
                    ->label('納品日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('status')
                    ->label('連携')
                    ->state(fn ($record): ?string => $record->queue_status)
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'BEFORE' => '未処理',
                        'PROCESSING' => '処理中',
                        'FINISHED' => '完了',
                        default => $state ?? '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'FINISHED' => 'success',
                        'PROCESSING' => 'info',
                        'BEFORE' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('picking_status')
                    ->label('出荷')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'BEFORE' => '未処理',
                        'BEFORE_PICKING' => 'ピッキング前',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品',
                        'COMPLETED' => 'ピッキング完了',
                        'SHIPPED' => '出荷済',
                        default => $state ?? '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'SHIPPED' => 'success',
                        'COMPLETED' => 'info',
                        'SHORTAGE' => 'danger',
                        'PICKING', 'BEFORE_PICKING' => 'warning',
                        default => 'gray',
                    })
                    ->width('90px'),

                TextColumn::make('transfer_number')
                    ->label('移動伝票番号')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->alignEnd()
                    ->width('95px'),

                TextColumn::make('from_warehouse_name')
                    ->label('移動元倉庫')
                    ->state(fn ($record): string => static::warehouseLabel($record->from_warehouse_code, $record->from_warehouse_name))
                    ->searchable()
                    ->width('150px'),

                TextColumn::make('to_warehouse_name')
                    ->label('移動先倉庫')
                    ->state(fn ($record): string => static::warehouseLabel($record->to_warehouse_code, $record->to_warehouse_name))
                    ->searchable()
                    ->width('150px'),

                TextColumn::make('delivery_course_name')
                    ->label('配送コース')
                    ->placeholder('-')
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('item_count')
                    ->label('商品数')
                    ->state(fn ($record): int => (int) $record->item_count)
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('error_message')
                    ->label('エラー')
                    ->state(fn ($record): ?string => $record->error_message)
                    ->limit(40)
                    ->placeholder('-')
                    ->tooltip(fn ($record): ?string => $record->error_message)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('transfer_number')
                    ->label('移動伝票番号')
                    ->schema([
                        TextInput::make('transfer_number')
                            ->label('移動伝票番号')
                            ->placeholder('移動伝票番号を入力'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        filled($data['transfer_number'] ?? null),
                        fn ($q) => $q->where(
                            'transfer_number',
                            'like',
                            '%'.mb_convert_kana((string) $data['transfer_number'], 'as').'%'
                        ),
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        if (! filled($data['transfer_number'] ?? null)) {
                            return null;
                        }

                        return '移動伝票番号: '.$data['transfer_number'];
                    }),

                Filter::make('candidate_creator_name')
                    ->label('担当者')
                    ->schema([
                        TextInput::make('candidate_creator_name')
                            ->label('担当者')
                            ->placeholder('担当者名を入力'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        filled($data['candidate_creator_name'] ?? null),
                        fn ($q) => $q->where(
                            'candidate_creator_name',
                            'like',
                            '%'.mb_convert_kana((string) $data['candidate_creator_name'], 'as').'%'
                        ),
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        if (! filled($data['candidate_creator_name'] ?? null)) {
                            return null;
                        }

                        return '担当者: '.$data['candidate_creator_name'];
                    }),

                SelectFilter::make('status')
                    ->label('連携状態')
                    ->options([
                        'BEFORE' => '未処理',
                        'PROCESSING' => '処理中',
                        'FINISHED' => '完了',
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $status) => $q->where('queue_status', $status),
                    )),

                SelectFilter::make('from_warehouse_id')
                    ->label('移動元倉庫')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::warehouseOptions($search)),

                SelectFilter::make('to_warehouse_id')
                    ->label('移動先倉庫')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::warehouseOptions($search)),

                SelectFilter::make('process_date')
                    ->form([
                        DatePicker::make('process_date')
                            ->label('処理日'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['process_date'] ?? null,
                        fn ($q, $date) => $q->whereDate('process_date', $date),
                    )),

                SelectFilter::make('delivered_date')
                    ->label('納品日')
                    ->form([
                        DatePicker::make('delivered_date')
                            ->label('納品日'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['delivered_date'] ?? null,
                        fn ($q, $date) => $q->whereDate('delivered_date', $date),
                    )),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('openCoreTransfer')
                    ->label('基幹伝票')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->tooltip('基幹伝票')
                    ->visible(fn ($record): bool => filled($record->stock_transfer_id))
                    ->url(
                        fn ($record): string => config('app.core_url')."/stocks/inventory/transfer/form/{$record->stock_transfer_id}",
                        shouldOpenInNewTab: true,
                    ),

                Action::make('viewItems')
                    ->label('商品リスト')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->tooltip('商品リスト')
                    ->modalHeading(fn ($record): string => '移動伝票履歴 '.($record->batch_code ?? "キューID:{$record->queue_id}"))
                    ->modalWidth('6xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalContent(fn ($record) => view(
                        'filament.components.stock-transfer-slip-history',
                        static::resolveSlipHistory($record),
                    )),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function warehouseOptions(string $search): array
    {
        $search = mb_convert_kana($search, 'as');

        return Warehouse::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Warehouse $warehouse) => [$warehouse->id => "[{$warehouse->code}]{$warehouse->name}"])
            ->toArray();
    }

    private static function batchCodeFromNote(?string $note): ?string
    {
        preg_match('/バッチ:([0-9]+)/u', (string) $note, $matches);

        return $matches[1] ?? null;
    }

    private static function warehouseLabel(?string $code, ?string $name): string
    {
        if (! $code && ! $name) {
            return '-';
        }

        return '['.($code ?? '-').']'.($name ?? '-');
    }

    private static function resolveSlipHistory($record): array
    {
        if ($record->stock_transfer_id) {
            $stockTransfer = StockTransfer::query()
                ->with('trade')
                ->findOrFail($record->stock_transfer_id);

            return StockTransferSlipHistory::resolveForTransfer($stockTransfer);
        }

        $queue = StockTransferQueue::query()->findOrFail($record->queue_id);

        return StockTransferSlipHistory::resolveForQueue($queue);
    }
}
