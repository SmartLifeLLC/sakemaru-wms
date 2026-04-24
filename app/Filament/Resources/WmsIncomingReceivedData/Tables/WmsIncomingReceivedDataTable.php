<?php

namespace App\Filament\Resources\WmsIncomingReceivedData\Tables;

use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Models\WmsIncomingImportError;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingReceiveService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsIncomingReceivedDataTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-received-table sticky-actions'])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('50px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '未照合',
                        'MATCHED' => '照合済み',
                        'APPLIED' => '適用済み',
                        'ERROR' => 'エラー',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'MATCHED' => 'info',
                        'APPLIED' => 'success',
                        'ERROR' => 'danger',
                        default => 'gray',
                    })
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('format_type')
                    ->label('形式')
                    ->badge()
                    ->color('gray')
                    ->width('60px'),

                TextColumn::make('filename')
                    ->label('ファイル名')
                    ->searchable()
                    ->limit(40)
                    ->width('250px'),

                TextColumn::make('a_company_name')
                    ->label('送信元')
                    ->searchable()
                    ->placeholder('-')
                    ->width('150px'),

                TextColumn::make('parsed_slip_count')
                    ->label('伝票数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('parsed_detail_count')
                    ->label('明細数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('a_created_date')
                    ->label('データ作成日')
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('has_finet_wrapper')
                    ->label('FINET')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'あり' : 'なし')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->width('70px'),

                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(30)
                    ->placeholder('-')
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('取込日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('100px'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未照合',
                        'MATCHED' => '照合済み',
                        'APPLIED' => '適用済み',
                        'ERROR' => 'エラー',
                    ]),

                SelectFilter::make('format_type')
                    ->label('形式')
                    ->options([
                        'JX' => 'JX',
                        'CSV' => 'CSV',
                    ]),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewSlips')
                    ->label('伝票一覧')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn ($record) => "受信伝票一覧 (ID: {$record->id})")
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->infolist(function ($record): array {
                        $file = $record->load('slips.details');
                        $slips = $file->slips;

                        if ($slips->isEmpty()) {
                            return [
                                Section::make('伝票なし')
                                    ->schema([
                                        TextEntry::make('empty')
                                            ->label('')
                                            ->state('この受信ファイルに伝票データはありません。'),
                                    ]),
                            ];
                        }

                        $sections = [];

                        foreach ($slips as $index => $slip) {
                            $statusLabel = match ($slip->match_status) {
                                'UNMATCHED' => '未照合',
                                'MATCHED' => '照合済み',
                                'PARTIAL' => '一部欠品',
                                'SHORTAGE' => '欠品',
                                'NOT_FOUND' => '該当なし',
                                default => $slip->match_status,
                            };
                            $statusIcon = match ($slip->match_status) {
                                'MATCHED' => 'heroicon-o-check-circle',
                                'PARTIAL', 'SHORTAGE' => 'heroicon-o-exclamation-triangle',
                                'NOT_FOUND' => 'heroicon-o-x-circle',
                                default => 'heroicon-o-question-mark-circle',
                            };

                            $detailRows = $slip->details->map(function ($detail) {
                                $matchLabel = match ($detail->match_status) {
                                    'MATCHED' => '一致',
                                    'PARTIAL' => '一部欠品',
                                    'SHORTAGE' => '欠品',
                                    'EXTRA' => '対象外',
                                    default => '-',
                                };

                                return [
                                    'line' => $detail->d_line_number,
                                    'item_code' => $detail->d_item_code ?? '-',
                                    'product_name' => $detail->d_product_name ?? '-',
                                    'jan_code' => $detail->d_jan_code ?? '-',
                                    'case_qty' => $detail->d_case_quantity,
                                    'piece_qty' => $detail->d_piece_quantity,
                                    'total_qty' => $detail->total_quantity,
                                    'expected_qty' => $detail->expected_quantity ?? '-',
                                    'match_status' => $matchLabel,
                                    'is_shortage' => $detail->is_shortage ? '欠品' : '',
                                ];
                            })->toArray();

                            $sections[] = Section::make("伝票 #{$slip->slip_number}")
                                ->description("{$statusLabel} | 明細: {$slip->details->count()}件".($slip->shortage_count > 0 ? " | 欠品: {$slip->shortage_count}件" : ''))
                                ->icon($statusIcon)
                                ->collapsed($index > 2)
                                ->schema([
                                    Grid::make(4)->schema([
                                        TextEntry::make("slip_{$slip->id}_status")
                                            ->label('照合ステータス')
                                            ->state($statusLabel)
                                            ->badge()
                                            ->color(match ($slip->match_status) {
                                                'MATCHED' => 'success',
                                                'PARTIAL' => 'warning',
                                                'SHORTAGE' => 'danger',
                                                'NOT_FOUND' => 'danger',
                                                default => 'gray',
                                            }),
                                        TextEntry::make("slip_{$slip->id}_order_date")
                                            ->label('発注日')
                                            ->state($slip->b_order_date ?? '-'),
                                        TextEntry::make("slip_{$slip->id}_delivery_date")
                                            ->label('納品日')
                                            ->state($slip->b_delivery_date ?? '-'),
                                        TextEntry::make("slip_{$slip->id}_contractor")
                                            ->label('取引先CD')
                                            ->state($slip->b_contractor_code ?? '-'),
                                    ]),
                                    View::make('filament.components.incoming-received-detail-table')
                                        ->viewData(['details' => $detailRows]),
                                ]);
                        }

                        return $sections;
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),

                Action::make('matchAndApply')
                    ->label('照合・適用')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->visible(fn ($record) => in_array($record->status, ['PENDING', 'MATCHED']))
                    ->requiresConfirmation()
                    ->modalHeading('照合・適用')
                    ->modalDescription('受信データを入荷予定と照合し、結果を適用します。')
                    ->action(function ($record) {
                        $service = app(IncomingReceiveService::class);

                        try {
                            $matchResult = $service->matchWithSchedules($record);

                            // 照合後に自動適用
                            $record->refresh();
                            $applyResult = ['applied' => 0, 'errors' => []];
                            if ($record->status === 'MATCHED') {
                                $applyResult = $service->applyMatched($record);
                            }

                            $body = "照合: {$matchResult['matched']}件";
                            if ($matchResult['shortage'] > 0) {
                                $body .= " / 欠品: {$matchResult['shortage']}件";
                            }
                            if ($matchResult['unmatched'] > 0) {
                                $body .= " / 未一致: {$matchResult['unmatched']}件";
                            }
                            if ($applyResult['applied'] > 0) {
                                $body .= " / 適用: {$applyResult['applied']}件";
                            }

                            Notification::make()
                                ->title('照合・適用が完了しました')
                                ->body($body)
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('照合・適用エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('deleteWithSchedules')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('受信データを削除')
                    ->modalDescription(function ($record) {
                        $scheduleCount = WmsOrderIncomingSchedule::where('order_source', OrderSource::RECEIVED)
                            ->whereIn('slip_number', $record->slips()->pluck('slip_number'))
                            ->count();

                        return $scheduleCount > 0
                            ? "この受信データと関連する入荷予定 {$scheduleCount}件 を物理削除します。この操作は元に戻せません。"
                            : 'この受信データを物理削除します。この操作は元に戻せません。';
                    })
                    ->action(function ($record) {
                        $slipNumbers = $record->slips()->pluck('slip_number')->toArray();

                        // 関連する入荷予定を物理削除（RECEIVED由来のもの）
                        $deletedSchedules = 0;
                        if (! empty($slipNumbers)) {
                            $deletedSchedules = WmsOrderIncomingSchedule::where('order_source', OrderSource::RECEIVED)
                                ->whereIn('slip_number', $slipNumbers)
                                ->delete();
                        }

                        // エラーレコード削除
                        WmsIncomingImportError::where('received_file_id', $record->id)->delete();

                        // 明細 → 伝票 → ファイルの順で削除
                        foreach ($record->slips as $slip) {
                            $slip->details()->delete();
                        }
                        $record->slips()->delete();
                        $record->delete();

                        Notification::make()
                            ->title('削除しました')
                            ->body("受信データと関連入荷予定 {$deletedSchedules}件 を削除しました")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
