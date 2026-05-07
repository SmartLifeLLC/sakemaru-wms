<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderCancellationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class WmsOrderConfirmedTable
{
    use HasExportAction;
    use HasModifierDisplay;
    use HasOptimizedFilters;

    protected static function getFilterModelTable(): string
    {
        return (new WmsOrderCandidate)->getTable();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-confirmed-table sticky-actions'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->sortable()
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('executed_at')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        try {
                            return \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))
                                ->format('m月d日 H時i分');
                        } catch (\Exception $e) {
                            return '-';
                        }
                    })
                    ->width('110px'),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('item.packaging')
                    ->label('規格')
                    ->alignCenter()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('order_quantity')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('lot_status')
                    ->label('ロット')
                    ->badge()
                    ->color(fn (LotStatus $state): string => match ($state) {
                        LotStatus::RAW => 'gray',
                        LotStatus::APPLIED => 'success',
                        LotStatus::BLOCKED => 'danger',
                        LotStatus::NEED_APPROVAL => 'warning',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_manually_modified')
                    ->label('手動修正')
                    ->state(fn ($record) => $record->is_manually_modified ? '修正済' : '-')
                    ->toggleable(isToggledHiddenByDefault: true),

                static::modifierColumn(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::CONFIRMED->value => CandidateStatus::CONFIRMED->label(),
                        CandidateStatus::EXECUTED->value => CandidateStatus::EXECUTED->label(),
                    ])
                    ->default(CandidateStatus::CONFIRMED->value),

                static::warehouseFilter(),

                static::contractorFilter(),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注確定詳細')
                    ->modalWidth('4xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->infolist(function (?WmsOrderCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        $item = $record->item;

                        return [
                            Grid::make(2)
                                ->schema([
                                    View::make('filament.components.order-confirmed-detail-left')
                                        ->viewData([
                                            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('Y/m/d H:i'),
                                            'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                            'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                            'itemCode' => $item?->code ?? '-',
                                            'itemName' => $item?->name ?? '-',
                                            'expectedArrivalDate' => $record->expected_arrival_date
                                                ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                                : '-',
                                        ])
                                        ->columnSpan(1),

                                    View::make('filament.components.order-confirmed-detail-right')
                                        ->viewData([
                                            'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                            'orderQuantity' => $record->order_quantity ?? 0,
                                            'status' => $record->status->label(),
                                            'statusColor' => $record->status->color(),
                                            'transmittedAt' => $record->transmitted_at
                                                ? $record->transmitted_at->format('Y/m/d H:i')
                                                : null,
                                        ])
                                        ->columnSpan(1),
                                ]),
                        ];
                    }),

                Action::make('cancelConfirmation')
                    ->label('確定取消')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (?WmsOrderCandidate $record): bool => in_array($record?->status, [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED]))
                    ->modalHeading('発注確定を取消')
                    ->modalDescription(fn ($record) => "[{$record->item?->code}]{$record->item?->name} の発注確定を取消し、承認済みに戻します。関連する入庫予定も削除されます。")
                    ->extraModalWindowAttributes(['class' => 'incoming-cancel-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('確定を取消')->color('danger'))
                    ->modalCancelActionLabel('取消せず閉じる')
                    ->schema([
                        Textarea::make('reason')
                            ->label('取消理由')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(OrderCancellationService::class);

                        try {
                            $deletedSchedules = $service->cancelConfirmation(
                                $record,
                                auth()->id(),
                                $data['reason']
                            );

                            Notification::make()
                                ->title('発注確定を取消しました')
                                ->body("入庫予定 {$deletedSchedules}件を削除しました。ステータスを承認済みに戻しました。")
                                ->warning()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkCancelConfirmation')
                        ->label('確定を一括取消')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->modalHeading('発注確定を一括取消')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件の発注確定を取消し、承認済みに戻します。関連する入庫予定も削除されます。")
                        ->extraModalWindowAttributes(['class' => 'incoming-cancel-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('一括取消')->color('danger'))
                        ->modalCancelActionLabel('取消せず閉じる')
                        ->schema([
                            Textarea::make('reason')
                                ->label('取消理由')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(OrderCancellationService::class);
                            $confirmed = $records->filter(fn ($r) => in_array($r->status, [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED]));

                            if ($confirmed->isEmpty()) {
                                Notification::make()
                                    ->title('取消可能な候補がありません')
                                    ->body('選択した候補は取消できません。')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $successCount = 0;
                            $totalDeletedSchedules = 0;
                            $errors = [];

                            foreach ($confirmed as $candidate) {
                                try {
                                    $deleted = $service->cancelConfirmation(
                                        $candidate,
                                        auth()->id(),
                                        $data['reason']
                                    );
                                    $successCount++;
                                    $totalDeletedSchedules += $deleted;
                                } catch (\Exception $e) {
                                    $errors[] = "[{$candidate->item?->code}] {$e->getMessage()}";
                                }
                            }

                            if ($successCount > 0) {
                                Notification::make()
                                    ->title("{$successCount}件の発注確定を取消しました")
                                    ->body("入庫予定 {$totalDeletedSchedules}件を削除しました。")
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($errors)) {
                                Notification::make()
                                    ->title(count($errors).'件でエラーが発生')
                                    ->body(implode("\n", array_slice($errors, 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('batch_code', 'desc');
    }
}
