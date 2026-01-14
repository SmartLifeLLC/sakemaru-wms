<?php

namespace App\Filament\Resources\WmsPickingTasks\Widgets;

use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemResults;
use App\Models\WmsPickingTask;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;

class PickingTaskInfoWidget extends Widget
{
    use InteractsWithPageTable;

    public ?int $pickingTaskId = null;

    protected string $view = 'filament.widgets.picking-task-info-widget';

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return ListWmsPickingItemResults::class;
    }

    protected function getViewData(): array
    {
        // Use the property passed from the page
        $pickingTaskId = $this->pickingTaskId;

        // Fallback: Try tableFilters
        if (! $pickingTaskId) {
            $pickingTaskId = $this->tableFilters['picking_task_id']['value'] ?? null;
        }

        if (! $pickingTaskId) {
            return ['task' => null];
        }

        $task = WmsPickingTask::with(['wave.waveSetting', 'deliveryCourse', 'warehouse', 'floor', 'pickingArea', 'picker'])->find($pickingTaskId);

        if (! $task) {
            return ['task' => null];
        }

        // Get wave number
        $waveNo = $task->wave?->wave_no ?? '-';

        // Get wave name
        $waveName = $task->wave?->waveSetting?->name ?? '-';

        // Get shipping date
        $shippingDate = '-';
        if ($task->wave && $task->wave->shipping_date) {
            $shippingDate = $task->wave->shipping_date->format('Y-m-d');
        }

        // Get picking start time from wave settings
        $pickingStartTime = '-';
        if ($task->wave && $task->wave->waveSetting && $task->wave->waveSetting->picking_start_time) {
            $pickingStartTime = $task->wave->waveSetting->picking_start_time;
        }

        // Get picking started/completed times
        $startedAt = $task->started_at ? $task->started_at->format('Y-m-d H:i') : '-';
        $completedAt = $task->completed_at ? $task->completed_at->format('Y-m-d H:i') : '-';

        // Combine picking area with restricted area
        $pickingAreaDisplay = $task->pickingArea->name ?? '-';
        if ($task->is_restricted_area) {
            $pickingAreaDisplay .= ' (制限エリア)';
        }

        return [
            'task' => $task,
            'waveNo' => $waveNo,
            'waveName' => $waveName,
            'shippingDate' => $shippingDate,
            'deliveryCourse' => ($task->deliveryCourse->code ?? '').' '.($task->deliveryCourse->name ?? '-'),
            'warehouse' => ($task->warehouse->code ?? '').' '.($task->warehouse->name ?? '-'),
            'floor' => $task->floor?->name ?? '-',
            'picker' => $task->picker?->display_name ?? '未割当',
            'pickerAssigned' => $task->picker_id !== null,
            'pickingArea' => $pickingAreaDisplay,
            'temperatureType' => $task->temperature_type?->label() ?? '-',
            'temperatureColor' => $task->temperature_type?->color() ?? 'gray',
            'restrictedArea' => $task->is_restricted_area ? '制限エリア' : '通常エリア',
            'isRestricted' => $task->is_restricted_area,
            'pickingStartTime' => $pickingStartTime,
            'startedAt' => $startedAt,
            'completedAt' => $completedAt,
        ];
    }
}
