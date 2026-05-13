<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\Sakemaru\User;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderCandidateAuditLog;
use App\Services\AutoOrder\OrderCancellationService;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\OrderTransmissionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

                TextColumn::make('setting_safety_stock')
                    ->label('発注点')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record)['safety_stock'])
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('60px'),

                TextColumn::make('setting_max_stock')
                    ->label('最大発注点')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record)['max_stock'])
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('setting_min_stock')
                    ->label('最低在庫数')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record)['min_stock'])
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('setting_auto_order_quantity')
                    ->label('自動発注数')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record)['auto_order_quantity'])
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('75px'),

                TextColumn::make('setting_is_auto_order')
                    ->label('自動発注')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record)['is_auto_order'] ? 'ON' : 'OFF')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ON' ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('70px'),

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

                static::confirmedByFilter(),

                static::confirmedDateFilter(),

                Filter::make('executed_at_range')
                    ->label('実行時刻')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('executed_from')
                                ->label('開始'),
                            DateTimePicker::make('executed_until')
                                ->label('終了'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['executed_from'] ?? null, fn (Builder $q, $date) => $q
                                ->where('batch_code', '>=', \Carbon\Carbon::parse($date)->format('YmdHis')))
                            ->when($data['executed_until'] ?? null, fn (Builder $q, $date) => $q
                                ->where('batch_code', '<=', \Carbon\Carbon::parse($date)->endOfMinute()->format('YmdHis').'999'));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['executed_from'] ?? null) {
                            $indicators[] = '実行時刻開始: '.\Carbon\Carbon::parse($data['executed_from'])->format('Y/m/d H:i');
                        }
                        if ($data['executed_until'] ?? null) {
                            $indicators[] = '実行時刻終了: '.\Carbon\Carbon::parse($data['executed_until'])->format('Y/m/d H:i');
                        }

                        return $indicators;
                    }),

                Filter::make('expected_arrival_date_range')
                    ->label('入荷予定')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('arrival_from')
                                ->label('開始日'),
                            DatePicker::make('arrival_until')
                                ->label('終了日'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['arrival_from'] ?? null, fn (Builder $q, $date) => $q->where('expected_arrival_date', '>=', $date))
                            ->when($data['arrival_until'] ?? null, fn (Builder $q, $date) => $q->where('expected_arrival_date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['arrival_from'] ?? null) {
                            $indicators[] = '入荷予定開始: '.\Carbon\Carbon::parse($data['arrival_from'])->format('Y/m/d');
                        }
                        if ($data['arrival_until'] ?? null) {
                            $indicators[] = '入荷予定終了: '.\Carbon\Carbon::parse($data['arrival_until'])->format('Y/m/d');
                        }

                        return $indicators;
                    }),

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
                        $orderSettings = WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record);

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
                                            'safetyStock' => $orderSettings['safety_stock'],
                                            'maxStock' => $orderSettings['max_stock'],
                                            'minStock' => $orderSettings['min_stock'],
                                            'autoOrderQuantity' => $orderSettings['auto_order_quantity'],
                                            'isAutoOrder' => $orderSettings['is_auto_order'],
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
                    BulkAction::make('bulkGenerateOrderDataFiles')
                        ->label('FAX / MAIL / CSV データ生成')
                        ->icon('heroicon-o-document-plus')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('発注データを生成')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件から、FAX / MAIL / CSV 用の発注データを生成します。確定済み以外の候補は除外されます。1000件を超える場合は条件を絞ってください。")
                        ->modalSubmitActionLabel('データ生成')
                        ->modalCancelActionLabel('生成せず閉じる')
                        ->action(function (Collection $records) {
                            if ($records->count() > 1000) {
                                Notification::make()
                                    ->title('選択件数が多すぎます')
                                    ->body('1000件以内になるように、倉庫・仕入先・実行CDなどで絞り込んでから再実行してください。')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $candidateIds = $records->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $result = app(OrderDataFileService::class)
                                ->generateCsvFilesForCandidates($candidateIds);

                            $fileCount = $result['total_files'] ?? count($result['files'] ?? []);
                            $totalOrders = collect($result['files'] ?? [])->sum('order_count');

                            if ($fileCount > 0) {
                                Notification::make()
                                    ->title('発注データを生成しました')
                                    ->body("生成ファイル {$fileCount}件 / 発注 {$totalOrders}件")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('生成対象がありません')
                                    ->body($result['message'] ?? '選択した候補に確定済みの発注候補がありません。')
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($result['errors'])) {
                                Notification::make()
                                    ->title(count($result['errors']).'件のエラーが発生しました')
                                    ->body(implode("\n", array_slice($result['errors'], 0, 5)))
                                    ->danger()
                                    ->send();
                            }

                            $faxErrors = collect($result['files'] ?? [])
                                ->filter(fn (array $file): bool => filled($file['fax_error'] ?? null))
                                ->map(fn (array $file): string => ($file['contractor_name'] ?? '発注先不明').': '.$file['fax_error'])
                                ->values()
                                ->all();

                            if (! empty($faxErrors)) {
                                Notification::make()
                                    ->title(count($faxErrors).'件のFAX生成エラーが発生しました')
                                    ->body(implode("\n", array_slice($faxErrors, 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulkGenerateJxFiles')
                        ->label('JXファイル生成')
                        ->icon('heroicon-o-document-arrow-up')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('JXファイル生成（送信しない）')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件からJXファイルを生成します。送信はされません。生成後「発注データファイル」画面から送信してください。")
                        ->modalSubmitActionLabel('JXファイル生成')
                        ->modalCancelActionLabel('生成せず閉じる')
                        ->action(function (Collection $records) {
                            $candidateIds = $records->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $result = app(OrderTransmissionService::class)
                                ->generateJxFilesForCandidateIds($candidateIds);

                            $fileCount = count($result['files'] ?? []);
                            $totalOrders = $result['total_orders'] ?? 0;

                            if ($fileCount > 0) {
                                $documentIds = collect($result['files'])->pluck('document_id')->filter()->implode(', ');
                                Notification::make()
                                    ->title("JXファイルを生成しました（{$fileCount}件）")
                                    ->body("発注数: {$totalOrders} / 伝票ID: {$documentIds}\n「発注データファイル」画面の送信前タブから送信してください。")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('生成対象がありません')
                                    ->body($result['errors'][0] ?? '確定済みの発注候補がありません')
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($result['errors'])) {
                                Notification::make()
                                    ->title(count($result['errors']).'件のエラー')
                                    ->body(implode("\n", array_slice($result['errors'], 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

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

    private static function confirmedByFilter(): SelectFilter
    {
        return SelectFilter::make('confirmed_by')
            ->label('確定者')
            ->searchable()
            ->default(auth()->id())
            ->options(fn () => self::buildConfirmedByOptions())
            ->getSearchResultsUsing(fn (string $search) => self::buildConfirmedByOptions($search))
            ->query(function (Builder $query, array $data) {
                if (blank($data['value'])) {
                    return;
                }

                $query->whereIn((new WmsOrderCandidate)->getTable().'.id', WmsOrderCandidateAuditLog::query()
                    ->where('action', WmsOrderCandidateAuditLog::ACTION_CONFIRMED)
                    ->where('performed_by', $data['value'])
                    ->select('order_candidate_id'));
            });
    }

    private static function confirmedDateFilter(): Filter
    {
        return Filter::make('confirmed_date')
            ->label('確定日')
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('confirmed_from')
                        ->label('開始日')
                        ->default(today()),
                    DatePicker::make('confirmed_until')
                        ->label('終了日')
                        ->default(today()),
                ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $confirmedLogQuery = WmsOrderCandidateAuditLog::query()
                    ->where('action', WmsOrderCandidateAuditLog::ACTION_CONFIRMED)
                    ->when($data['confirmed_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                    ->when($data['confirmed_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
                    ->select('order_candidate_id');

                return $query->whereIn((new WmsOrderCandidate)->getTable().'.id', $confirmedLogQuery);
            })
            ->indicateUsing(function (array $data): array {
                $indicators = [];
                if ($data['confirmed_from'] ?? null) {
                    $indicators[] = '確定日開始: '.\Carbon\Carbon::parse($data['confirmed_from'])->format('Y/m/d');
                }
                if ($data['confirmed_until'] ?? null) {
                    $indicators[] = '確定日終了: '.\Carbon\Carbon::parse($data['confirmed_until'])->format('Y/m/d');
                }

                return $indicators;
            });
    }

    private static function buildConfirmedByOptions(?string $search = null): array
    {
        $query = User::query()
            ->whereIn('id', fn ($q) => $q
                ->select('performed_by')
                ->from((new WmsOrderCandidateAuditLog)->getTable())
                ->where('action', WmsOrderCandidateAuditLog::ACTION_CONFIRMED)
                ->whereNotNull('performed_by')
                ->distinct());

        if ($search) {
            $search = mb_convert_kana($search, 'as');
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => "[{$u->code}]{$u->name}"])
            ->toArray();
    }
}
