<?php

namespace App\Filament\Resources\WmsInventoryCount\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsInventoryCount;
use App\Services\InventoryCount\InventoryCountService;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use App\Services\InventoryCount\InventoryInstructionSheetPdfService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WmsInventoryCountTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('count_no')
                    ->label('棚卸しNo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse_name')
                    ->label('倉庫')
                    ->sortable(),

                TextColumn::make('memo')
                    ->label('メモ')
                    ->limit(40)
                    ->placeholder('-'),

                TextColumn::make('count_date')
                    ->label('棚卸し日')
                    ->date('Y/m/d')
                    ->sortable(),

                TextColumn::make('snapshot_taken_at')
                    ->label('在庫取得(開始)')
                    ->dateTime('m/d H:i')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('ending_stock_taken_at')
                    ->label('在庫取得(終了)')
                    ->dateTime('m/d H:i')
                    ->placeholder('-'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (WmsInventoryCount $record) => $record->status_label)
                    ->color(fn (WmsInventoryCount $record) => $record->status_color),

                TextColumn::make('progress')
                    ->label('進捗')
                    ->state(function (WmsInventoryCount $record) {
                        $total = $record->items()->count();
                        if ($total === 0) {
                            return '-';
                        }
                        $counted = $record->items()->whereNotNull('first_count_quantity')->count();

                        return "{$counted}/{$total}";
                    }),

                TextColumn::make('createdByUser.name')
                    ->label('作成者')
                    ->placeholder('システム'),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->multiple()
                    ->options(WmsInventoryCount::statusFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $statuses = $data['values'] ?? [];

                        return WmsInventoryCount::applyDisplayStatusFilter($query, $statuses);
                    }),
            ])
            ->defaultSort(fn (Builder $query): Builder => static::applyDefaultOrder($query))
            ->recordActions([
                static::getInstructionSheetAction(),

                Action::make('view')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (WmsInventoryCount $record) => route('filament.admin.resources.wms-inventory-counts.view', $record)),
            ], position: RecordActionsPosition::AfterColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    static::getBulkUncountedListPdfAction(),
                ]),
            ])
            ->extraAttributes(['class' => 'sticky-actions']);
    }

    protected static function statusFilterOptions(): array
    {
        return WmsInventoryCount::statusFilterOptions();
    }

    protected static function defaultStatusFilterValues(): array
    {
        return WmsInventoryCount::defaultStatusFilterValues();
    }

    public static function applyDefaultOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('count_date')
            ->orderByDesc('id');
    }

    protected static function getInstructionSheetAction(): Action
    {
        return Action::make('downloadInstructionSheet')
            ->label('指示書出力')
            ->icon('heroicon-o-clipboard-document-list')
            ->color('gray')
            ->extraAttributes(['class' => 'font-bold'])
            ->visible(fn (WmsInventoryCount $record) => $record->status !== WmsInventoryCount::STATUS_CANCELLED)
            ->schema([
                Select::make('category_ids')
                    ->label('中分類')
                    ->options(fn (WmsInventoryCount $record) => (new InventoryInstructionSheetPdfService)->getCategoryOptions($record))
                    ->multiple()
                    ->searchable()
                    ->placeholder('全て（未選択で全部門出力）'),
            ])
            ->modalHeading('指示書ダウンロード')
            ->modalDescription('中分類を選択して指示書をダウンロードします。未選択の場合は全部門が出力されます。')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ダウンロード')->color('danger'))
            ->modalCancelActionLabel('ダウンロードせず閉じる')
            ->action(function (WmsInventoryCount $record, array $data) {
                $categoryIds = ! empty($data['category_ids']) ? array_map('intval', $data['category_ids']) : null;
                $pdfContent = (new InventoryInstructionSheetPdfService)->generate($record, $categoryIds, InventoryInstructionSheetPdfService::ITEM_SCOPE_ALL, true);
                $filename = '棚卸し指示書_'.($record->count_no ?? 'unknown').'.pdf';

                return response()->streamDownload(
                    fn () => print ($pdfContent),
                    $filename,
                    ['Content-Type' => 'application/pdf']
                );
            });
    }

    protected static function getBulkUncountedListPdfAction(): BulkAction
    {
        return BulkAction::make('downloadBulkUncountedListPdf')
            ->label('未PDF出力')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading('選択棚卸しの未PDF出力')
            ->modalDescription(function (Collection $records): string {
                $total = $records->count();
                $draftCount = $records
                    ->filter(fn (WmsInventoryCount $record): bool => $record->status === WmsInventoryCount::STATUS_DRAFT)
                    ->count();
                $targetCount = $total - $draftCount;

                return "選択: {$total}件 / 出力対象: {$targetCount}件"
                    .($draftCount > 0 ? "（下書き{$draftCount}件は除外）" : '');
            })
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('未PDF出力')->color('danger'))
            ->modalCancelActionLabel('出力せず閉じる')
            ->schema([
                Select::make('round')
                    ->label('入力回')
                    ->options([
                        1 => '1回目',
                        2 => '2回目',
                        3 => '3回目',
                    ])
                    ->default(1)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data) {
                $targetRecords = $records
                    ->filter(fn (WmsInventoryCount $record): bool => $record->status !== WmsInventoryCount::STATUS_DRAFT)
                    ->values();

                if ($targetRecords->isEmpty()) {
                    Notification::make()
                        ->warning()
                        ->title('出力対象の棚卸しがありません')
                        ->body('下書き以外の棚卸しを選択してください。')
                        ->send();

                    return null;
                }

                try {
                    $round = (int) ($data['round'] ?? 1);
                    $pdfContent = (new InventoryDiffListPdfService)->generateUncountedForCounts($targetRecords, $round);
                    $filename = '棚卸未カウント_複数_'.$round.'回目_'.now()->format('YmdHis').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('未PDFを生成できません')
                        ->body($e->getMessage())
                        ->send();

                    return null;
                }
            });
    }

    public static function getCreateAction(): Action
    {
        return Action::make('createInventoryCount')
            ->label('棚卸し作成')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('棚卸し作成')
            ->modalWidth('lg')
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(
                fn ($action) => $action->makeModalSubmitAction('submit', [])
                    ->label('作成')
                    ->color('danger')
            )
            ->modalCancelActionLabel('作成せず閉じる')
            ->schema([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => \App\Models\Sakemaru\Warehouse::query()
                        ->where('is_active', true)
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                DatePicker::make('count_date')
                    ->label('棚卸し日')
                    ->default(now())
                    ->required(),

                Textarea::make('memo')
                    ->label('メモ')
                    ->rows(3),
            ])
            ->action(function (array $data) {
                try {
                    $service = new InventoryCountService;
                    $count = $service->create($data);
                    $snapshotCount = $service->takeSnapshot($count);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('棚卸しを作成できません')
                        ->body($e->getMessage())
                        ->send();

                    return null;
                }

                Notification::make()
                    ->success()
                    ->title('棚卸しを作成しました')
                    ->body("スナップショット: {$snapshotCount}件")
                    ->send();

                return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $count);
            });
    }
}
