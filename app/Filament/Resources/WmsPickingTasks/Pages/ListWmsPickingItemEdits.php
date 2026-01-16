<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Widgets\PickingTaskInfoWidget;
use App\Filament\Resources\WmsPickingTasks\WmsPickingItemEditResource;
use App\Filament\Widgets\PendingTasksWidget;
use App\Models\WmsAdminOperationLog;
use App\Models\WmsPickingTask;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListWmsPickingItemEdits extends ListRecords
{
    use AdvancedTables;
    use ExposesTableToWidgets;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemEditResource::class;

    protected static ?string $title = 'ピッキング詳細';

    // View labels as constants for reuse
    public const VIEW_LABEL_ALL = '全体';

    public const VIEW_LABEL_SHORTAGE = '引き当て欠品あり';

    // URLのクエリパラメータ ?picking_task_id=123 を自動的にこの変数にバインドします
    #[Url(as: 'picking_task_id')]
    public ?string $pickingTaskId = null;

    public function mount(): void
    {
        parent::mount();

        // Get picking_task_id from URL parameters
        $this->pickingTaskId = request()->input('tableFilters.picking_task_id.value');
    }

    public function getHeading(): string
    {
        if ($this->pickingTaskId) {
            $task = WmsPickingTask::find($this->pickingTaskId);

            if ($task) {
                $statusLabel = match ($task->status) {
                    WmsPickingTask::STATUS_PENDING => '未着手',
                    WmsPickingTask::STATUS_PICKING_READY => 'ピッキング準備完了',
                    WmsPickingTask::STATUS_PICKING => 'ピッキング中',
                    WmsPickingTask::STATUS_COMPLETED => '完了',
                    'SHORTAGE' => '欠品あり',
                    'CANCELLED' => 'キャンセル',
                    default => $task->status,
                };

                return "ピッキング詳細（{$statusLabel}）";
            }
        }

        return 'ピッキング詳細';
    }

    /**
     * Get header background color styles based on task status
     */
    public function getExtraBodyAttributes(): array
    {
        if ($this->pickingTaskId) {
            $task = WmsPickingTask::find($this->pickingTaskId);

            if ($task) {
                $bgColor = match ($task->status) {
                    WmsPickingTask::STATUS_PENDING => '#fef3c7', // yellow-100
                    WmsPickingTask::STATUS_PICKING_READY => '#d1fae5', // green-100
                    WmsPickingTask::STATUS_PICKING => '#dbeafe', // blue-100
                    WmsPickingTask::STATUS_COMPLETED => '#f3f4f6', // gray-100
                    'SHORTAGE' => '#fee2e2', // red-100
                    default => '#ffffff',
                };

                return [
                    'style' => "background-color: {$bgColor}",
                ];
            }
        }

        return [];
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            \Filament\Actions\Action::make('back_to_list')
                ->label('一覧に戻る')
                ->icon('heroicon-o-arrow-left')
                ->url('/admin/wms-picking-waitings')
                ->color('gray'),
        ];

        // Add picker assignment action if we have a picking task ID
        if ($this->pickingTaskId) {
            $task = WmsPickingTask::find($this->pickingTaskId);

            // PENDING状態: ピッキング準備完了モーダル（担当者選択 + ステータス変更）
            if ($task && $task->status === WmsPickingTask::STATUS_PENDING) {
                $actions[] = \Filament\Actions\Action::make('picking_ready')
                    ->label('ピッキング準備完了')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->modalHeading('ピッキング準備完了')
                    ->modalDescription('担当者を選択してピッキング準備を完了します')
                    ->modalSubmitActionLabel('ピッキング準備完了')
                    ->modalCancelActionLabel('キャンセル')
                    ->fillForm(function () use ($task) {
                        return [
                            'warehouse_filter' => $task?->warehouse_id,
                            'picker_id' => $task?->picker_id,
                        ];
                    })
                    ->form(function () use ($task) {
                        return [
                            Select::make('warehouse_filter')
                                ->label('倉庫で絞り込み')
                                ->options(\App\Models\Sakemaru\Warehouse::where('is_active', true)->pluck('name', 'id'))
                                ->default($task?->warehouse_id)
                                ->live(),
                            Select::make('picker_id')
                                ->label('担当者')
                                ->required()
                                ->options(function ($get) {
                                    $query = \App\Models\WmsPicker::query();
                                    if ($warehouseFilter = $get('warehouse_filter')) {
                                        $query->where('default_warehouse_id', $warehouseFilter);
                                    }

                                    return $query->orderBy('code')->get()->pluck('display_name', 'id');
                                })
                                ->searchable()
                                ->helperText('ピッキングを担当するピッカーを選択してください'),
                        ];
                    })
                    ->action(function (array $data) {
                        $task = WmsPickingTask::find($this->pickingTaskId);

                        if (! $task) {
                            Notification::make()
                                ->title('エラー')
                                ->body('タスクが見つかりません')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($task->status !== WmsPickingTask::STATUS_PENDING) {
                            Notification::make()
                                ->title('エラー')
                                ->body('このタスクは既にピッキング準備完了または完了しています')
                                ->danger()
                                ->send();

                            return;
                        }

                        $oldPickerId = $task->picker_id;
                        $oldStatus = $task->status;
                        $newPickerId = $data['picker_id'];

                        // Update picker AND status to PICKING_READY
                        $task->update([
                            'picker_id' => $newPickerId,
                            'status' => WmsPickingTask::STATUS_PICKING_READY,
                        ]);

                        // Log the operation
                        WmsAdminOperationLog::log(
                            EWMSLogOperationType::ASSIGN_PICKER,
                            [
                                'target_type' => EWMSLogTargetType::PICKING_TASK,
                                'target_id' => $task->id,
                                'picking_task_id' => $task->id,
                                'wave_id' => $task->wave_id,
                                'picker_id_before' => $oldPickerId,
                                'picker_id_after' => $newPickerId,
                                'status_before' => $oldStatus,
                                'status_after' => WmsPickingTask::STATUS_PICKING_READY,
                                'operation_note' => 'ピッキング準備完了',
                            ]
                        );

                        Notification::make()
                            ->title('ピッキング準備が完了しました')
                            ->success()
                            ->send();
                    })
                    ->after(function () {
                        // Reload page to show updated status and pending tasks widget
                        $this->js('window.location.reload()');
                    });
            }

            // PICKING_READY状態: ピッキング準備取消ボタン
            if ($task && $task->status === WmsPickingTask::STATUS_PICKING_READY) {
                $actions[] = \Filament\Actions\Action::make('cancel_preparation')
                    ->label('ピッキング準備取消')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('ピッキング準備取消')
                    ->modalDescription('ピッキング準備を取り消してもよろしいですか？担当者の割り当ては維持されます。')
                    ->modalSubmitActionLabel('取り消し')
                    ->action(function () {
                        $task = WmsPickingTask::find($this->pickingTaskId);

                        if (! $task) {
                            Notification::make()
                                ->title('エラー')
                                ->body('タスクが見つかりません')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($task->status !== WmsPickingTask::STATUS_PICKING_READY) {
                            Notification::make()
                                ->title('エラー')
                                ->body('準備完了状態のタスクのみ取り消しできます')
                                ->danger()
                                ->send();

                            return;
                        }

                        $oldStatus = $task->status;

                        // Revert status back to PENDING (keep picker_id)
                        $task->update([
                            'status' => WmsPickingTask::STATUS_PENDING,
                        ]);

                        // Log the operation
                        WmsAdminOperationLog::log(
                            EWMSLogOperationType::ASSIGN_PICKER,
                            [
                                'target_type' => EWMSLogTargetType::PICKING_TASK,
                                'target_id' => $task->id,
                                'picking_task_id' => $task->id,
                                'wave_id' => $task->wave_id,
                                'status_before' => $oldStatus,
                                'status_after' => WmsPickingTask::STATUS_PENDING,
                                'operation_note' => 'ピッキング準備取り消し',
                            ]
                        );

                        Notification::make()
                            ->title('ピッキング準備を取り消しました')
                            ->success()
                            ->send();
                    })
                    ->after(function () {
                        // Reload page to show updated status and button
                        $this->js('window.location.reload()');
                    });
            }
        }

        return $actions;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PickingTaskInfoWidget::make(['pickingTaskId' => $this->pickingTaskId]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getFooterWidgets(): array
    {
        if (! $this->pickingTaskId) {
            return [];
        }

        $task = WmsPickingTask::find($this->pickingTaskId);

        if (! $task) {
            return [];
        }

        // Show pending tasks widget for PENDING and PICKING_READY statuses
        // so users can navigate to next task after completing preparation
        if (! in_array($task->status, [WmsPickingTask::STATUS_PENDING, WmsPickingTask::STATUS_PICKING_READY])) {
            return [];
        }

        return [
            PendingTasksWidget::make([
                'warehouseId' => $task->warehouse_id,
                'currentTaskId' => (int) $this->pickingTaskId,
            ]),
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading($this->getEmptyStateHeading())
            ->emptyStateDescription('');
    }

    protected function getEmptyStateHeading(): string
    {
        $activeView = $this->activeView ?? 'default';

        return match ($activeView) {
            'default' => '「'.self::VIEW_LABEL_ALL.'」のデータはありません',
            'shortage' => '「'.self::VIEW_LABEL_SHORTAGE.'」のデータはありません',
            default => 'データが見つかりません',
        };
    }

    public function getPresetViews(): array
    {
        return [
            'default' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query)->favorite()->label(self::VIEW_LABEL_ALL)->default(),
            'shortage' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->where('has_soft_shortage', true))->favorite()->label(self::VIEW_LABEL_SHORTAGE),
        ];
    }
}
