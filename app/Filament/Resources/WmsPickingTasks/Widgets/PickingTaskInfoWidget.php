<?php

namespace App\Filament\Resources\WmsPickingTasks\Widgets;

use App\Models\WmsPickingTask;
use Filament\Widgets\Widget;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemResults;

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
        if (!$pickingTaskId) {
            $pickingTaskId = $this->tableFilters['picking_task_id']['value'] ?? null;
        }

        if (!$pickingTaskId) {
            return ['task' => null];
        }

        $task = WmsPickingTask::with(['wave.waveSetting', 'deliveryCourse', 'warehouse', 'pickingArea'])->find($pickingTaskId);

        if (!$task) {
            return ['task' => null];
        }

        // Build wave display text (name and wave_no only)
        $waveText = '-';
        if ($task->wave) {
            $waveName = $task->wave->waveSetting->name ?? '';
            $waveNo = $task->wave->wave_no ?? '';
            
            $parts = array_filter([$waveName, $waveNo]);
            $waveText = !empty($parts) ? implode(' / ', $parts) : '-';
        }
        
        // Get shipping date separately
        $shippingDate = '-';
        if ($task->wave && $task->wave->shipping_date) {
            $shippingDate = $task->wave->shipping_date->format('Y-m-d');
        }

        return [
            'task' => $task,
            'waveText' => $waveText,
            'shippingDate' => $shippingDate,
            'deliveryCourse' => ($task->deliveryCourse->code ?? '') . ' ' . ($task->deliveryCourse->name ?? '-'),
            'warehouse' => ($task->warehouse->code ?? '') . ' ' . ($task->warehouse->name ?? '-'),
            'pickingArea' => $task->pickingArea->name ?? '-',
            'restrictedArea' => $task->is_restricted_area ? '制限エリア' : '通常エリア',
            'isRestricted' => $task->is_restricted_area,
        ];
    }
}
