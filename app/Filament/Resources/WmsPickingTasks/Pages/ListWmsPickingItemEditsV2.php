<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Widgets\PickingCourseInfoWidget;
use App\Filament\Resources\WmsPickingTasks\WmsPickingItemEditV2Resource;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAdminOperationLog;
use App\Models\WmsPicker;
use App\Models\WmsPickingTask;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class ListWmsPickingItemEditsV2 extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemEditV2Resource::class;

    protected static ?string $title = 'ピッキング詳細 v2';

    public const VIEW_LABEL_ALL = '全体';

    public const VIEW_LABEL_SHORTAGE = '引き当て欠品あり';

    #[Url(as: 'warehouse_id')]
    public ?string $warehouseId = null;

    #[Url(as: 'delivery_course_id')]
    public ?string $deliveryCourseId = null;

    #[Url(as: 'shipment_date')]
    public ?string $shipmentDate = null;

    public function mount(): void
    {
        parent::mount();

        $this->warehouseId = request()->input('warehouse_id');
        $this->deliveryCourseId = request()->input('delivery_course_id');
        $this->shipmentDate = request()->input('shipment_date');
    }

    public function getHeading(): string
    {
        $course = $this->deliveryCourseId ? DeliveryCourse::find($this->deliveryCourseId) : null;
        $warehouse = $this->warehouseId ? Warehouse::find($this->warehouseId) : null;

        $parts = array_filter([
            $this->shipmentDate,
            $warehouse ? "[{$warehouse->code}] {$warehouse->name}" : null,
            $course ? "[{$course->code}] {$course->name}" : null,
        ]);

        return 'ピッキング詳細 v2'.($parts ? '（'.implode(' / ', $parts).'）' : '');
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('back_to_course_list')
                ->label('一覧に戻る')
                ->icon('heroicon-o-arrow-left')
                ->url('/admin/wms-picking-waitings-v2')
                ->color('gray'),
        ];

        $pendingCount = $this->courseTasksQuery()
            ->where('status', WmsPickingTask::STATUS_PENDING)
            ->count();

        if ($pendingCount > 0) {
            $actions[] = Action::make('picking_ready')
                ->label('ピッキング準備完了')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->modalHeading('ピッキング準備完了')
                ->modalDescription("この配送コースの未着手タスク {$pendingCount} 件をピッキング準備完了にします。")
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('出庫準備完了')->color('danger'))
                ->modalCancelActionLabel('出庫準備せず閉じる')
                ->fillForm(fn () => [
                    'warehouse_filter' => $this->warehouseId,
                    'picker_id' => $this->courseTasksQuery()->whereNotNull('picker_id')->value('picker_id'),
                ])
                ->form([
                    Select::make('warehouse_filter')
                        ->label('倉庫で絞り込み')
                        ->options(Warehouse::where('is_active', true)->pluck('name', 'id'))
                        ->default($this->warehouseId)
                        ->live(),
                    Select::make('picker_id')
                        ->label('担当者')
                        ->required()
                        ->options(function ($get) {
                            $query = WmsPicker::query();
                            if ($warehouseFilter = $get('warehouse_filter')) {
                                $query->where('default_warehouse_id', $warehouseFilter);
                            }

                            return $query->orderBy('code')->get()->pluck('display_name', 'id');
                        })
                        ->searchable()
                        ->helperText('この配送コースの未着手タスクに割り当てるピッカーを選択してください'),
                ])
                ->action(function (array $data) {
                    $tasks = $this->courseTasksQuery()
                        ->where('status', WmsPickingTask::STATUS_PENDING)
                        ->get();

                    if ($tasks->isEmpty()) {
                        Notification::make()
                            ->title('対象なし')
                            ->body('未着手タスクがありません')
                            ->warning()
                            ->send();

                        return;
                    }

                    DB::connection('sakemaru')->transaction(function () use ($tasks, $data) {
                        foreach ($tasks as $task) {
                            $oldPickerId = $task->picker_id;
                            $oldStatus = $task->status;

                            $task->update([
                                'picker_id' => $data['picker_id'],
                                'status' => WmsPickingTask::STATUS_PICKING_READY,
                            ]);

                            WmsAdminOperationLog::log(
                                EWMSLogOperationType::ASSIGN_PICKER,
                                [
                                    'target_type' => EWMSLogTargetType::PICKING_TASK,
                                    'target_id' => $task->id,
                                    'picking_task_id' => $task->id,
                                    'wave_id' => $task->wave_id,
                                    'picker_id_before' => $oldPickerId,
                                    'picker_id_after' => $data['picker_id'],
                                    'status_before' => $oldStatus,
                                    'status_after' => WmsPickingTask::STATUS_PICKING_READY,
                                    'operation_note' => '配送コース単位ピッキング準備完了',
                                ]
                            );
                        }
                    });

                    Notification::make()
                        ->title('ピッキング準備が完了しました')
                        ->body($tasks->count().'件のタスクを更新しました')
                        ->success()
                        ->send();
                })
                ->after(fn () => $this->js('window.location.reload()'));
        }

        $readyCount = $this->courseTasksQuery()
            ->where('status', WmsPickingTask::STATUS_PICKING_READY)
            ->count();

        if ($readyCount > 0) {
            $actions[] = Action::make('cancel_preparation')
                ->label('ピッキング準備取消')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('ピッキング準備取消')
                ->modalDescription("この配送コースの準備完了タスク {$readyCount} 件を未着手に戻します。担当者の割り当ては維持されます。")
                ->modalSubmitActionLabel('取り消し')
                ->action(function () {
                    $tasks = $this->courseTasksQuery()
                        ->where('status', WmsPickingTask::STATUS_PICKING_READY)
                        ->get();

                    if ($tasks->isEmpty()) {
                        Notification::make()
                            ->title('対象なし')
                            ->body('準備完了タスクがありません')
                            ->warning()
                            ->send();

                        return;
                    }

                    DB::connection('sakemaru')->transaction(function () use ($tasks) {
                        foreach ($tasks as $task) {
                            $oldStatus = $task->status;

                            $task->update([
                                'status' => WmsPickingTask::STATUS_PENDING,
                            ]);

                            WmsAdminOperationLog::log(
                                EWMSLogOperationType::ASSIGN_PICKER,
                                [
                                    'target_type' => EWMSLogTargetType::PICKING_TASK,
                                    'target_id' => $task->id,
                                    'picking_task_id' => $task->id,
                                    'wave_id' => $task->wave_id,
                                    'status_before' => $oldStatus,
                                    'status_after' => WmsPickingTask::STATUS_PENDING,
                                    'operation_note' => '配送コース単位ピッキング準備取り消し',
                                ]
                            );
                        }
                    });

                    Notification::make()
                        ->title('ピッキング準備を取り消しました')
                        ->body($tasks->count().'件のタスクを更新しました')
                        ->success()
                        ->send();
                })
                ->after(fn () => $this->js('window.location.reload()'));
        }

        return $actions;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PickingCourseInfoWidget::make([
                'warehouseId' => $this->warehouseId,
                'deliveryCourseId' => $this->deliveryCourseId,
                'shipmentDate' => $this->shipmentDate,
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
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
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query)
                ->favorite()
                ->label(self::VIEW_LABEL_ALL)
                ->default(),
            'shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('wms_picking_item_results.has_soft_shortage', true))
                ->favorite()
                ->label(self::VIEW_LABEL_SHORTAGE),
        ];
    }

    private function courseTasksQuery(): Builder
    {
        return WmsPickingTask::query()
            ->when($this->warehouseId, fn (Builder $query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->deliveryCourseId, fn (Builder $query) => $query->where('delivery_course_id', $this->deliveryCourseId))
            ->when($this->shipmentDate, fn (Builder $query) => $query->whereDate('shipment_date', $this->shipmentDate));
    }
}
