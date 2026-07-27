<?php

namespace App\Filament\Resources\WmsJxUnknownIncomingSlips\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Services\AutoOrder\IncomingReceiveService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WmsJxUnknownIncomingSlipsTable
{
    private const REVIEW_ERROR_CODES = [
        'EOS_ASSIGNMENT_NOT_FOUND',
        'EOS_UNASSIGNED_RECEIVED_SCHEDULE_CREATED',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'wms-jx-unknown-incoming-slips-table sticky-actions'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'file.contractor',
                    'details.matchedItem',
                    'importErrors',
                ])
                ->withCount([
                    'details',
                    'details as item_missing_count' => fn (Builder $query): Builder => $query
                        ->whereNull('matched_item_id'),
                ])
                ->whereHas('file', fn (Builder $query): Builder => $query->where('format_type', 'JX'))
                ->where(function (Builder $query): void {
                    $query
                        ->where('match_status', 'NO_ASSIGNMENT')
                        ->orWhereHas('importErrors', fn (Builder $errorQuery): Builder => $errorQuery
                            ->whereIn('error_code', self::REVIEW_ERROR_CODES)
                            ->where(function (Builder $resolvedQuery): void {
                                $resolvedQuery
                                    ->whereNull('is_resolved')
                                    ->orWhere('is_resolved', false);
                            }));
                })
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('file.created_at')
                    ->label('取込日時')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->width('120px'),

                TextColumn::make('slip_number')
                    ->label('受信伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->width('130px'),

                TextColumn::make('b_shop_code')
                    ->label('店舗CD')
                    ->searchable()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('b_shop_name')
                    ->label('店舗名')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(18)
                    ->tooltip(fn ($state) => $state),

                TextColumn::make('b_contractor_code')
                    ->label('先方仕入先CD')
                    ->searchable()
                    ->placeholder('-')
                    ->width('110px'),

                TextColumn::make('file.contractor.name')
                    ->label('受信仕入先')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(18)
                    ->tooltip(fn ($state) => $state),

                TextColumn::make('b_delivery_date')
                    ->label('納品日')
                    ->formatStateUsing(fn (?string $state): string => self::formatJxDate($state))
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('match_status')
                    ->label('状態')
                    ->formatStateUsing(fn (?string $state): string => self::matchStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'NO_ASSIGNMENT' => 'warning',
                        'NOT_FOUND' => 'danger',
                        default => 'gray',
                    })
                    ->width('100px'),

                TextColumn::make('details_count')
                    ->label('明細')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('item_missing_count')
                    ->label('商品不明')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->width('90px'),

                TextColumn::make('shortage_count')
                    ->label('欠品')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('issue_codes')
                    ->label('確認内容')
                    ->state(fn (WmsIncomingReceivedSlip $record): string => $record->importErrors
                        ->pluck('error_code')
                        ->unique()
                        ->implode(' / '))
                    ->placeholder('-')
                    ->limit(42)
                    ->tooltip(fn (WmsIncomingReceivedSlip $record): string => $record->importErrors
                        ->pluck('error_message')
                        ->unique()
                        ->implode("\n")),

                TextColumn::make('file.status')
                    ->label('取込状態')
                    ->formatStateUsing(fn (?string $state): string => self::fileStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        WmsIncomingReceivedFile::STATUS_PENDING => 'warning',
                        WmsIncomingReceivedFile::STATUS_MATCHED => 'info',
                        WmsIncomingReceivedFile::STATUS_APPLIED => 'success',
                        WmsIncomingReceivedFile::STATUS_ERROR => 'danger',
                        WmsIncomingReceivedFile::STATUS_SKIPPED => 'gray',
                        default => 'gray',
                    })
                    ->width('90px'),

                TextColumn::make('file.received_message_id')
                    ->label('JXメッセージID')
                    ->searchable()
                    ->placeholder('-')
                    ->limit(28)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('match_status')
                    ->label('状態')
                    ->options([
                        'NO_ASSIGNMENT' => '割当なし',
                        'NOT_FOUND' => '該当なし',
                    ]),

                Filter::make('has_item_missing')
                    ->label('商品不明あり')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'details',
                        fn (Builder $detailQuery): Builder => $detailQuery->whereNull('matched_item_id')
                    )),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')
                            ->label('取込日 From'),
                        DatePicker::make('until')
                            ->label('取込日 To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->whereHas('file', function (Builder $fileQuery) use ($data): Builder {
                            return $fileQuery
                                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date));
                        });
                    }),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetails')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (WmsIncomingReceivedSlip $record): string => "伝票番号不明: {$record->slip_number}")
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->infolist(fn (WmsIncomingReceivedSlip $record): array => self::detailInfolist($record)),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('confirmUnknownIncoming')
                        ->label('選択を入荷完了として取込')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('選択した伝票番号不明データを入荷完了として取込')
                        ->modalDescription(fn (Collection $records): string => "選択した {$records->count()} 伝票の数量あり明細から入荷完了データを作成します。伝票番号は先方の受信伝票番号を使用します。仕入キュー作成は行いません。")
                        ->modalSubmitActionLabel('入荷完了として取込')
                        ->modalCancelActionLabel('取込せず閉じる')
                        ->action(function (Collection $records): void {
                            $service = app(IncomingReceiveService::class);
                            $created = 0;
                            $updated = 0;
                            $skipped = 0;
                            $scheduleIds = [];
                            $errors = [];

                            foreach ($records->sortBy('id') as $record) {
                                try {
                                    $result = $service->confirmUnassignedJxSlip($record, auth()->id());
                                    $created += $result['created'];
                                    $updated += $result['updated'];
                                    $skipped += $result['skipped'];
                                    $scheduleIds = array_merge($scheduleIds, $result['schedule_ids']);
                                } catch (\Throwable $throwable) {
                                    $errors[] = "伝票ID {$record->id}: {$throwable->getMessage()}";
                                }
                            }

                            $scheduleCount = count(array_unique($scheduleIds));
                            $body = "入荷完了データ: {$scheduleCount}件 / 新規: {$created}件 / 更新: {$updated}件 / 既存: {$skipped}件";

                            if ($errors !== []) {
                                $body .= "\n失敗: ".count($errors).'件';
                                $body .= "\n".collect($errors)->take(5)->implode("\n");
                            }

                            $notification = Notification::make()
                                ->title($errors === [] ? '入荷完了として取込しました' : '一部の取込に失敗しました')
                                ->body($body);

                            ($errors === [] ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function detailInfolist(WmsIncomingReceivedSlip $record): array
    {
        $record->loadMissing(['file.contractor', 'details.matchedItem', 'importErrors']);

        return [
            Section::make('受信伝票')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('slip_number')
                            ->label('受信伝票番号')
                            ->state($record->slip_number ?? '-')
                            ->copyable(),
                        TextEntry::make('b_shop_code')
                            ->label('店舗CD')
                            ->state($record->b_shop_code ?? '-'),
                        TextEntry::make('b_shop_name')
                            ->label('店舗名')
                            ->state($record->b_shop_name ?? '-'),
                        TextEntry::make('b_delivery_date')
                            ->label('納品日')
                            ->state(self::formatJxDate($record->b_delivery_date)),
                        TextEntry::make('b_contractor_code')
                            ->label('先方仕入先CD')
                            ->state($record->b_contractor_code ?? '-'),
                        TextEntry::make('file_contractor')
                            ->label('受信仕入先')
                            ->state($record->file?->contractor?->name ?? '-'),
                        TextEntry::make('match_status')
                            ->label('状態')
                            ->state(self::matchStatusLabel($record->match_status))
                            ->badge()
                            ->color($record->match_status === 'NO_ASSIGNMENT' ? 'warning' : 'danger'),
                        TextEntry::make('file_status')
                            ->label('取込状態')
                            ->state(self::fileStatusLabel($record->file?->status)),
                    ]),
                ]),
            Section::make('確認内容')
                ->schema([
                    View::make('filament.components.jx-unknown-incoming-slip-errors')
                        ->viewData([
                            'errors' => $record->importErrors
                                ->sortBy('id')
                                ->values(),
                        ]),
                ])
                ->collapsible(),
            Section::make('受信明細')
                ->schema([
                    View::make('filament.components.jx-unknown-incoming-slip-detail-table')
                        ->viewData([
                            'details' => self::detailRows($record),
                        ]),
                ]),
        ];
    }

    private static function detailRows(WmsIncomingReceivedSlip $record): array
    {
        return $record->details
            ->sortBy('d_line_number')
            ->map(fn ($detail): array => [
                'line' => $detail->d_line_number,
                'item_code' => $detail->d_item_code ?: '-',
                'matched_item_code' => $detail->matchedItem?->code ?: '-',
                'product_name' => $detail->d_product_name ?: '-',
                'jan_code' => $detail->d_jan_code ?: '-',
                'pack_quantity' => $detail->d_pack_quantity,
                'case_quantity' => $detail->d_case_quantity,
                'piece_quantity' => $detail->d_piece_quantity,
                'total_quantity' => $detail->total_quantity,
                'unit_price' => is_numeric($detail->d_unit_price) ? number_format(((float) $detail->d_unit_price) / 100, 2) : '-',
                'amount' => is_numeric($detail->d_amount) ? number_format(((float) $detail->d_amount) / 100, 2) : '-',
                'match_status' => self::matchStatusLabel($detail->match_status),
                'is_shortage' => $detail->is_shortage ? '欠品' : '',
            ])
            ->values()
            ->all();
    }

    private static function formatJxDate(?string $state): string
    {
        if (! $state) {
            return '-';
        }

        try {
            $value = trim($state);
            if (preg_match('/^\d{8}$/', $value)) {
                return Carbon::createFromFormat('Ymd', $value)->format('Y-m-d');
            }

            if (preg_match('/^\d{6}$/', $value)) {
                return Carbon::createFromFormat('ymd', $value)->format('Y-m-d');
            }
        } catch (\Throwable) {
            return $state;
        }

        return $state;
    }

    private static function matchStatusLabel(?string $status): string
    {
        return match ($status) {
            'UNMATCHED' => '未照合',
            'NO_ASSIGNMENT' => '割当なし',
            'MATCHED' => '照合済み',
            'PARTIAL' => '一部欠品',
            'SHORTAGE' => '欠品',
            'NOT_FOUND' => '該当なし',
            default => $status ?: '-',
        };
    }

    private static function fileStatusLabel(?string $status): string
    {
        return match ($status) {
            WmsIncomingReceivedFile::STATUS_PENDING => '未照合',
            WmsIncomingReceivedFile::STATUS_MATCHED => '照合済み',
            WmsIncomingReceivedFile::STATUS_APPLIED => '適用済み',
            WmsIncomingReceivedFile::STATUS_ERROR => 'エラー',
            WmsIncomingReceivedFile::STATUS_SKIPPED => '対象外',
            default => $status ?: '-',
        };
    }
}
