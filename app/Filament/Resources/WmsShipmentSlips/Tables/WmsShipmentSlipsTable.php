<?php

namespace App\Filament\Resources\WmsShipmentSlips\Tables;

use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use App\Services\Print\PrintRequestService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Enums\PaginationOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class WmsShipmentSlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('delivery_course_code')
                    ->label('配送コード')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース名')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(20),

                TextColumn::make('wave.wave_no')
                    ->label('波動識別ID')
                    ->description(fn ($record) => $record->wave?->waveSetting?->name),

                TextColumn::make('shipment_date')
                    ->label('納品日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫'),

                TextColumn::make('grouped_status')
                    ->label('ステータス')
                    ->badge()
                    ->state(function ($record) {
                        $allCompleted = $record->grouped_tasks->every(fn ($task) => $task->status === 'COMPLETED');
                        $anyPicking = $record->grouped_tasks->contains(fn ($task) => $task->status === 'PICKING');

                        if ($allCompleted) {
                            return 'COMPLETED';
                        } elseif ($anyPicking) {
                            return 'PICKING';
                        }

                        return 'PENDING';
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'PENDING' => '待機中',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        default => $state ?? '-',
                    }),

                TextColumn::make('wave.print_count')
                    ->label('印刷回数')
                    ->suffix('回')
                    ->alignCenter(),

                TextColumn::make('task_count')
                    ->label('タスク数')
                    ->state(fn ($record) => $record->grouped_tasks->count())
                    ->suffix('件')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->relationship('deliveryCourse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('floor_id')
                    ->label('フロア')
                    ->relationship('floor', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('wms_picking_area_id')
                    ->label('ピッキングエリア')
                    ->relationship('pickingArea', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('is_restricted_area')
                    ->label('制限エリア')
                    ->options([
                        '1' => 'あり',
                        '0' => 'なし',
                    ]),
            ])
            ->recordAction('')
            ->recordActions([
                Action::make('print')
                    ->label(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? '印刷' : '強制印刷';
                    })
                    ->icon('heroicon-o-printer')
                    ->color(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? 'primary' : 'warning';
                    })
                    ->requiresConfirmation()
                    ->modalHeading(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? '出荷伝票印刷' : '強制印刷';
                    })
                    ->modalDescription(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        if (! $printability['can_print']) {
                            return new \Illuminate\Support\HtmlString(
                                self::buildPrintabilityErrorHtml($printability)
                            );
                        }

                        return 'この配送コースの伝票を印刷します。';
                    })
                    ->modalSubmitActionLabel(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        return $printability['can_print'] ? '印刷' : '強制印刷';
                    })
                    ->action(function (WmsPickingTask $record) {
                        $systemDate = ClientSetting::systemDate();

                        // 印刷可能性チェック
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->wave_id
                        );

                        // 印刷依頼を作成
                        $printService = app(PrintRequestService::class);
                        $result = $printService->createPrintRequest(
                            $record->delivery_course_id,
                            $systemDate->format('Y-m-d'),
                            $record->warehouse_id,
                            $record->wave_id
                        );

                        if (! $result['success']) {
                            Notification::make()
                                ->title('エラー')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        // 同じ配送コース・納品日・Waveのタスクをすべて取得
                        $query = WmsPickingTask::where('shipment_date', $systemDate)
                            ->where('delivery_course_id', $record->delivery_course_id);

                        if ($record->wave_id) {
                            $query->where('wave_id', $record->wave_id);
                        }

                        $tasksToUpdate = $query->get();

                        // print_requested_countをインクリメント
                        foreach ($tasksToUpdate as $task) {
                            $task->increment('print_requested_count');
                        }

                        // Waveの印刷回数をインクリメント
                        if ($record->wave) {
                            $record->wave->increment('print_count');
                        }

                        $title = $printability['can_print'] ? '印刷依頼' : '強制印刷依頼';
                        $notificationType = $printability['can_print'] ? 'success' : 'warning';

                        Notification::make()
                            ->title($title)
                            ->body('伝票印刷を依頼しました。（売上'.$result['earning_count'].'件、タスク'.$tasksToUpdate->count().'件）')
                            ->{$notificationType}()
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->checkIfRecordIsSelectableUsing(function (WmsPickingTask $record): bool {
                $systemDate = ClientSetting::systemDate();
                $approvalService = app(ShortageApprovalService::class);
                $printability = $approvalService->checkPrintability(
                    $record->delivery_course_id,
                    $systemDate->format('Y-m-d'),
                    $record->wave_id
                );
                return $printability['can_print'];
            })
            ->bulkActions([
                BulkAction::make('bulkPrint')
                    ->label('一括印刷')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('一括印刷')
                    ->modalDescription(function (Collection $records): \Illuminate\Support\HtmlString|string {
                        $systemDate = ClientSetting::systemDate();
                        $approvalService = app(ShortageApprovalService::class);

                        $printableCount = 0;
                        $forcePrintRecords = [];

                        foreach ($records as $record) {
                            $printability = $approvalService->checkPrintability(
                                $record->delivery_course_id,
                                $systemDate->format('Y-m-d'),
                                $record->wave_id
                            );

                            if ($printability['can_print']) {
                                $printableCount++;
                            } else {
                                $forcePrintRecords[] = [
                                    'course_code' => $record->delivery_course_code,
                                    'course_name' => $record->deliveryCourse?->name ?? '-',
                                    'error' => $printability['error_message'],
                                ];
                            }
                        }

                        if (empty($forcePrintRecords)) {
                            return "選択された {$records->count()} 件の配送コースの伝票を印刷します。";
                        }

                        // 強制印刷が必要なものがある場合
                        $html = '<div class="space-y-4">';

                        if ($printableCount > 0) {
                            $html .= '<div class="text-success-600 dark:text-success-400">';
                            $html .= "印刷可能: {$printableCount} 件";
                            $html .= '</div>';
                        }

                        $html .= '<div class="text-danger-600 dark:text-danger-400 font-medium">';
                        $html .= '以下の配送コースは強制印刷が必要なため、一括印刷できません:';
                        $html .= '</div>';

                        $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-40 overflow-y-auto">';
                        $html .= '<table class="w-full text-sm">';
                        $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
                        $html .= '<th class="pb-2">コード</th><th class="pb-2">配送コース名</th><th class="pb-2">理由</th>';
                        $html .= '</tr></thead><tbody>';

                        foreach ($forcePrintRecords as $fp) {
                            $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                            $html .= '<td class="py-1">' . e($fp['course_code']) . '</td>';
                            $html .= '<td class="py-1">' . e(mb_substr($fp['course_name'], 0, 15)) . '</td>';
                            $html .= '<td class="py-1 text-danger-600">' . e(mb_substr($fp['error'], 0, 20)) . '</td>';
                            $html .= '</tr>';
                        }

                        $html .= '</tbody></table>';
                        $html .= '</div>';

                        if ($printableCount > 0) {
                            $html .= '<div class="text-sm text-gray-600 dark:text-gray-400">';
                            $html .= "印刷可能な {$printableCount} 件のみ印刷されます。";
                            $html .= '</div>';
                        } else {
                            $html .= '<div class="text-sm text-danger-600 dark:text-danger-400">';
                            $html .= '印刷可能な配送コースがありません。';
                            $html .= '</div>';
                        }

                        $html .= '</div>';

                        return new \Illuminate\Support\HtmlString($html);
                    })
                    ->modalSubmitActionLabel('一括印刷')
                    ->action(function (Collection $records): void {
                        $systemDate = ClientSetting::systemDate();
                        $printService = app(PrintRequestService::class);
                        $approvalService = app(ShortageApprovalService::class);
                        $successCount = 0;
                        $skippedCount = 0;
                        $errorCount = 0;
                        $totalEarnings = 0;
                        $totalTasks = 0;

                        foreach ($records as $record) {
                            // 印刷可能かチェック（強制印刷が必要なものはスキップ）
                            $printability = $approvalService->checkPrintability(
                                $record->delivery_course_id,
                                $systemDate->format('Y-m-d'),
                                $record->wave_id
                            );

                            if (!$printability['can_print']) {
                                $skippedCount++;
                                continue;
                            }

                            try {
                                // 印刷依頼を作成
                                $result = $printService->createPrintRequest(
                                    $record->delivery_course_id,
                                    $systemDate->format('Y-m-d'),
                                    $record->warehouse_id,
                                    $record->wave_id
                                );

                                if ($result['success']) {
                                    // 同じ配送コース・納品日・Waveのタスクをすべて取得
                                    $query = WmsPickingTask::where('shipment_date', $systemDate)
                                        ->where('delivery_course_id', $record->delivery_course_id);

                                    if ($record->wave_id) {
                                        $query->where('wave_id', $record->wave_id);
                                    }

                                    $tasksToUpdate = $query->get();

                                    // print_requested_countをインクリメント
                                    foreach ($tasksToUpdate as $task) {
                                        $task->increment('print_requested_count');
                                    }

                                    // Waveの印刷回数をインクリメント
                                    if ($record->wave) {
                                        $record->wave->increment('print_count');
                                    }

                                    $successCount++;
                                    $totalEarnings += $result['earning_count'];
                                    $totalTasks += $tasksToUpdate->count();
                                } else {
                                    $errorCount++;
                                }
                            } catch (\Exception $e) {
                                $errorCount++;
                            }
                        }

                        if ($successCount === 0 && $skippedCount > 0) {
                            Notification::make()
                                ->title('印刷できません')
                                ->body("選択された {$skippedCount} 件はすべて強制印刷が必要です。個別に強制印刷してください。")
                                ->danger()
                                ->send();
                            return;
                        }

                        $message = "印刷依頼完了: {$successCount}件成功";
                        if ($skippedCount > 0) {
                            $message .= "、{$skippedCount}件スキップ（強制印刷必要）";
                        }
                        if ($errorCount > 0) {
                            $message .= "、{$errorCount}件失敗";
                        }
                        $message .= "（売上{$totalEarnings}件、タスク{$totalTasks}件）";

                        Notification::make()
                            ->title('一括印刷')
                            ->body($message)
                            ->success()
                            ->send();
                    }),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                // system_dateを取得
                $systemDate = ClientSetting::systemDate();

                // shipment_dateが営業日(system_date)のもののみ
                // 各配送コース・Wave IDごとに最初のレコードのみ取得（フロア、エリア、制限エリアは展開時に表示）
                $query->where('shipment_date', $systemDate)
                    ->whereIn('id', function ($subQuery) use ($systemDate) {
                        $subQuery->select(DB::raw('MIN(id)'))
                            ->from('wms_picking_tasks')
                            ->where('shipment_date', $systemDate)
                            ->groupBy('delivery_course_id', 'wave_id');
                    })
                    ->with(['deliveryCourse', 'warehouse', 'wave.waveSetting', 'floor', 'pickingArea']);
            })
            ->defaultSort('delivery_course_code', 'asc');
    }

    /**
     * グループ化されたタスクを取得する
     * ListWmsShipmentSlipsから呼び出される
     */
    public static function loadGroupedTasks(SupportCollection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $systemDate = ClientSetting::systemDate();

        // 全レコードの配送コース・Wave IDの組み合わせを取得
        $groupKeys = $records->map(function ($record) {
            return [
                'delivery_course_id' => $record->delivery_course_id,
                'wave_id' => $record->wave_id,
            ];
        })->unique(function ($item) {
            return $item['delivery_course_id'].'-'.($item['wave_id'] ?? 'null');
        });

        // 該当する全タスクを取得
        $allTasks = WmsPickingTask::where('shipment_date', $systemDate)
            ->where(function ($query) use ($groupKeys) {
                foreach ($groupKeys as $key) {
                    $query->orWhere(function ($q) use ($key) {
                        $q->where('delivery_course_id', $key['delivery_course_id']);
                        if ($key['wave_id'] !== null) {
                            $q->where('wave_id', $key['wave_id']);
                        } else {
                            $q->whereNull('wave_id');
                        }
                    });
                }
            })
            ->with(['floor', 'pickingArea'])
            ->get();

        // グループ化してレコードに割り当て
        $groupedTasks = $allTasks->groupBy(function ($task) {
            return $task->delivery_course_id.'-'.($task->wave_id ?? 'null');
        });

        foreach ($records as $record) {
            $key = $record->delivery_course_id.'-'.($record->wave_id ?? 'null');
            $record->grouped_tasks = $groupedTasks->get($key, collect());
        }
    }

    /**
     * 印刷不可理由のHTMLを生成
     */
    protected static function buildPrintabilityErrorHtml(array $printability): string
    {
        $html = '<div class="space-y-4">';

        // エラーメッセージ
        $html .= '<div class="text-danger-600 dark:text-danger-400 font-medium">';
        $html .= e($printability['error_message']);
        $html .= '</div>';

        // ピッキング未完了アイテム
        if (!empty($printability['incomplete_items'])) {
            $html .= '<div class="mt-4">';
            $html .= '<div class="font-medium text-sm text-gray-700 dark:text-gray-300 mb-2">ピッキング未完了アイテム:</div>';
            $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-40 overflow-y-auto">';
            $html .= '<table class="w-full text-sm">';
            $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
            $html .= '<th class="pb-2">商品コード</th><th class="pb-2">商品名</th><th class="pb-2">ステータス</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($printability['incomplete_items'] as $item) {
                $statusLabel = match ($item['status']) {
                    'PENDING' => '未着手',
                    'PICKING' => 'ピッキング中',
                    default => $item['status'],
                };
                $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                $html .= '<td class="py-1">' . e($item['item_code']) . '</td>';
                $html .= '<td class="py-1">' . e(mb_substr($item['item_name'], 0, 20)) . '</td>';
                $html .= '<td class="py-1"><span class="text-warning-600">' . e($statusLabel) . '</span></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div></div>';
        }

        // 在庫同期未完了の欠品
        if (!empty($printability['unsynced_shortages'])) {
            $html .= '<div class="mt-4">';
            $html .= '<div class="font-medium text-sm text-gray-700 dark:text-gray-300 mb-2">在庫同期未完了の欠品:</div>';
            $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-40 overflow-y-auto">';
            $html .= '<table class="w-full text-sm">';
            $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
            $html .= '<th class="pb-2">商品コード</th><th class="pb-2">商品名</th><th class="pb-2">欠品数</th><th class="pb-2">承認</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($printability['unsynced_shortages'] as $shortage) {
                $confirmedLabel = $shortage['is_confirmed'] ? '済' : '未';
                $confirmedClass = $shortage['is_confirmed'] ? 'text-success-600' : 'text-danger-600';
                $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                $html .= '<td class="py-1">' . e($shortage['item_code']) . '</td>';
                $html .= '<td class="py-1">' . e(mb_substr($shortage['item_name'], 0, 20)) . '</td>';
                $html .= '<td class="py-1">' . e($shortage['shortage_qty']) . '</td>';
                $html .= '<td class="py-1"><span class="' . $confirmedClass . '">' . e($confirmedLabel) . '</span></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div></div>';
        }

        $html .= '<div class="mt-4 text-sm text-gray-600 dark:text-gray-400">';
        $html .= 'ピッキングや欠品対応が完了していない状態でも、現状のまま印刷します。本当に実行しますか？';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
