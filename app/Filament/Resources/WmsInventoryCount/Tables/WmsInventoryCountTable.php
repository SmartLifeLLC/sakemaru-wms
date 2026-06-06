<?php

namespace App\Filament\Resources\WmsInventoryCount\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\WmsInventoryCount;
use App\Services\InventoryCount\InventoryCountService;
use App\Services\InventoryCount\InventoryInstructionSheetPdfService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsInventoryCountTable
{
    use HasOptimizedFilters;

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

                TextColumn::make('count_date')
                    ->label('棚卸し日')
                    ->date('Y/m/d')
                    ->sortable(),

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
                static::warehouseFilter(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        WmsInventoryCount::STATUS_DRAFT => '下書き',
                        WmsInventoryCount::STATUS_COUNTING => 'カウント中',
                        WmsInventoryCount::STATUS_CHECKED => '差異確認済',
                        WmsInventoryCount::STATUS_CONFIRMED => '確定済',
                        WmsInventoryCount::STATUS_CANCELLED => '取消',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                static::getInstructionSheetAction(),

                Action::make('view')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (WmsInventoryCount $record) => route('filament.admin.resources.wms-inventory-counts.view', $record)),
            ], position: RecordActionsPosition::AfterColumns)
            ->toolbarActions([
                static::getCreateAction(),
            ])
            ->extraAttributes(['class' => 'sticky-actions']);
    }

    protected static function getInstructionSheetAction(): Action
    {
        return Action::make('downloadInstructionSheet')
            ->label('指示書出力')
            ->icon('heroicon-o-clipboard-document-list')
            ->color('gray')
            ->visible(fn (WmsInventoryCount $record) => $record->status !== WmsInventoryCount::STATUS_CANCELLED)
            ->schema([
                ToggleButtons::make('item_scope')
                    ->label('出力範囲')
                    ->options(InventoryInstructionSheetPdfService::itemScopeOptions())
                    ->default(InventoryInstructionSheetPdfService::ITEM_SCOPE_TOP_50)
                    ->grouped()
                    ->inline()
                    ->required(),

                Select::make('category_ids')
                    ->label('中分類')
                    ->options(fn (WmsInventoryCount $record) => (new InventoryInstructionSheetPdfService)->getCategoryOptions($record))
                    ->multiple()
                    ->searchable()
                    ->placeholder('全て（未選択で全部門出力）'),
            ])
            ->modalHeading('指示書ダウンロード')
            ->modalDescription('中分類と出力範囲を選択して指示書をダウンロードします。未選択の場合は全部門が出力されます。')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ダウンロード')->color('danger'))
            ->modalCancelActionLabel('ダウンロードせず閉じる')
            ->action(function (WmsInventoryCount $record, array $data) {
                $categoryIds = ! empty($data['category_ids']) ? array_map('intval', $data['category_ids']) : null;
                $itemScope = (string) ($data['item_scope'] ?? InventoryInstructionSheetPdfService::ITEM_SCOPE_TOP_50);
                $pdfContent = (new InventoryInstructionSheetPdfService)->generate($record, $categoryIds, $itemScope);
                $filename = '棚卸し指示書_'.($record->count_no ?? 'unknown').'.pdf';

                return response()->streamDownload(
                    fn () => print ($pdfContent),
                    $filename,
                    ['Content-Type' => 'application/pdf']
                );
            });
    }

    protected static function getCreateAction(): Action
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

                Checkbox::make('force_close_existing')
                    ->label('同じ倉庫の未完了棚卸しをすべて強制終了して作成する')
                    ->helperText('倉庫別に有効な棚卸しは1件のみです。既存の下書き・カウント中・差異確認済は取消にします。'),
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
