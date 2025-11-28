<?php

namespace App\Filament\Resources\WmsShipmentSlips\Tables;

use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use App\Services\Print\PrintRequestService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class WmsShipmentSlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // 1. 常時表示される行のコンテンツ（ヘッダー部分）
                Split::make([
                    TextColumn::make('delivery_course_code')
                        ->label('配送コード')
                        ->searchable()
                        ->sortable()
                        ->weight('bold'),

                    TextColumn::make('deliveryCourse.name')
                        ->label('配送コース名')
                        ->searchable()
                        ->sortable()
                        ->grow(),

                    Stack::make([
                        TextColumn::make('wave.waveSetting.name')
                            ->label('ウェーブ')
                            ->icon('heroicon-m-arrow-path')
                            ->size('sm'),
                        TextColumn::make('wave.wave_no')
                            ->label('Wave No')
                            ->prefix('No.')
                            ->size('sm')
                            ->color('gray'),
                    ])->space(1),

                    Stack::make([
                        TextColumn::make('shipment_date')
                            ->label('納品日')
                            ->date('Y-m-d')
                            ->icon('heroicon-m-calendar')
                            ->size('sm'),
                        TextColumn::make('warehouse.name')
                            ->label('倉庫')
                            ->size('sm')
                            ->color('gray'),
                    ])->space(1),

                    TextColumn::make('grouped_status')
                        ->label('ステータス')
                        ->badge()
                        ->state(function ($record) {
                            // グループ内の全タスクのステータスを集計
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
                        ->label('印刷')
                        ->icon('heroicon-m-printer')
                        ->suffix('回')
                        ->alignEnd(),
                ]),

                // 2. クリックで展開されるアコーディオン部分
                Panel::make([
                    Stack::make([
                        TextColumn::make('grouped_tasks_summary')
                            ->label('エリア別タスク')
                            ->html()
                            ->state(function ($record) {
                                $tasks = $record->grouped_tasks;

                                if ($tasks->isEmpty()) {
                                    return '<span class="text-gray-400">タスクなし</span>';
                                }

                                $html = '<div class="divide-y divide-gray-200 dark:divide-gray-700">';

                                foreach ($tasks as $task) {
                                    $floorName = $task->floor?->name ?? '-';
                                    $areaName = $task->pickingArea?->name ?? '-';
                                    $isRestricted = $task->is_restricted_area;
                                    $status = $task->status;
                                    $printCount = $task->print_requested_count ?? 0;

                                    // ステータスバッジの色
                                    $statusColor = match ($status) {
                                        'PENDING' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                                        'PICKING' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                                        'COMPLETED' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300',
                                    };
                                    $statusLabel = match ($status) {
                                        'PENDING' => '待機中',
                                        'PICKING' => 'ピッキング中',
                                        'COMPLETED' => '完了',
                                        default => $status ?? '-',
                                    };

                                    // 制限エリアバッジ
                                    $restrictedBadge = $isRestricted
                                        ? '<span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-300 ml-2">制限エリア</span>'
                                        : '';

                                    $html .= <<<HTML
                                    <div class="py-2 flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-sm font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                                                {$floorName}
                                            </span>
                                            <span class="inline-flex items-center rounded-md bg-primary-100 px-2 py-1 text-sm font-medium text-primary-700 dark:bg-primary-900 dark:text-primary-300">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                                {$areaName}
                                            </span>
                                            {$restrictedBadge}
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm text-gray-500 dark:text-gray-400">印刷: {$printCount}回</span>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$statusColor}">
                                                {$statusLabel}
                                            </span>
                                        </div>
                                    </div>
                                    HTML;
                                }

                                $html .= '</div>';

                                return $html;
                            }),
                    ])->space(2),
                ])->collapsible(),
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
                            return $printability['error_message']."\n\nピッキングや欠品対応が完了していない状態でも、現状のまま印刷します。本当に実行しますか？";
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
            ->bulkActions([
                BulkAction::make('bulkPrint')
                    ->label('一括印刷')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('一括印刷')
                    ->modalDescription(fn (Collection $records): string => "選択された {$records->count()} 件の配送コースの伝票を印刷します。"
                    )
                    ->modalSubmitActionLabel('一括印刷')
                    ->action(function (Collection $records): void {
                        $systemDate = ClientSetting::systemDate();
                        $printService = app(PrintRequestService::class);
                        $successCount = 0;
                        $errorCount = 0;
                        $totalEarnings = 0;
                        $totalTasks = 0;

                        foreach ($records as $record) {
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

                        $message = "印刷依頼完了: {$successCount}件成功";
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
}
