<?php

namespace App\Filament\Resources\WmsPickingTasks\Widgets;

use App\Models\WmsPickingTask;
use Filament\Widgets\Widget;

class PickingCourseInfoWidget extends Widget
{
    public ?string $warehouseId = null;

    public ?string $deliveryCourseId = null;

    public ?string $shipmentDate = null;

    protected string $view = 'filament.widgets.picking-course-info-widget';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tasks = WmsPickingTask::query()
            ->with(['wave.waveSetting', 'deliveryCourse', 'warehouse', 'floor', 'pickingArea', 'picker'])
            ->when($this->warehouseId, fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->deliveryCourseId, fn ($query) => $query->where('delivery_course_id', $this->deliveryCourseId))
            ->when($this->shipmentDate, fn ($query) => $query->whereDate('shipment_date', $this->shipmentDate))
            ->whereIn('status', [
                WmsPickingTask::STATUS_PENDING,
                WmsPickingTask::STATUS_PICKING_READY,
            ])
            ->orderBy('floor_id')
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            return ['summary' => null];
        }

        $first = $tasks->first();
        $statusLabels = [
            WmsPickingTask::STATUS_PENDING => '未着手',
            WmsPickingTask::STATUS_PICKING_READY => 'ピッキング準備完了',
            WmsPickingTask::STATUS_PICKING => 'ピッキング中',
            WmsPickingTask::STATUS_COMPLETED => '完了',
            WmsPickingTask::STATUS_SHORTAGE => '欠品あり',
        ];

        $statusSummary = $tasks
            ->countBy('status')
            ->map(fn ($count, $status) => ($statusLabels[$status] ?? $status).': '.$count.'件')
            ->values()
            ->implode(' / ');

        $pickers = $tasks
            ->map(fn ($task) => $task->picker?->display_name)
            ->filter()
            ->unique()
            ->values();

        $unassignedCount = $tasks->whereNull('picker_id')->count();
        $pickerLabel = $pickers->isNotEmpty() ? $pickers->implode(', ') : '未割当';
        if ($unassignedCount > 0 && $pickers->isNotEmpty()) {
            $pickerLabel .= " / 未割当 {$unassignedCount}件";
        }

        return [
            'summary' => [
                'wave_no' => $tasks->pluck('wave.wave_no')->filter()->unique()->implode(', ') ?: '-',
                'wave_name' => $tasks->pluck('wave.waveSetting.name')->filter()->unique()->implode(', ') ?: '-',
                'shipping_date' => $this->shipmentDate ?: ($first->shipment_date?->format('Y-m-d') ?? '-'),
                'warehouse' => trim(($first->warehouse?->code ?? '').' '.($first->warehouse?->name ?? '-')),
                'floor' => $tasks->pluck('floor.name')->filter()->unique()->implode(', ') ?: '-',
                'picking_area' => $tasks->pluck('pickingArea.name')->filter()->unique()->implode(', ') ?: '-',
                'temperature_type' => $tasks->pluck('temperature_type')->filter()->map(fn ($type) => $type->label())->unique()->implode(', ') ?: '-',
                'delivery_course' => trim(($first->deliveryCourse?->code ?? '').' '.($first->deliveryCourse?->name ?? '-')),
                'picker' => $pickerLabel,
                'picker_assigned' => $unassignedCount === 0,
                'task_count' => $tasks->count(),
                'status_summary' => $statusSummary,
                'picking_start_time' => $tasks->pluck('wave.waveSetting.picking_start_time')->filter()->unique()->implode(', ') ?: '-',
                'started_at' => $tasks->pluck('started_at')->filter()->min()?->format('Y-m-d H:i') ?? '-',
                'completed_at' => $tasks->pluck('completed_at')->filter()->max()?->format('Y-m-d H:i') ?? '-',
            ],
        ];
    }
}
