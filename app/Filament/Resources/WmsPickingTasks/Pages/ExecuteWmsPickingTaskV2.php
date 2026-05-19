<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Resources\WmsPickingTasks\WmsPickingWaitingV2Resource;

class ExecuteWmsPickingTaskV2 extends ExecuteWmsPickingTask
{
    protected static string $resource = WmsPickingWaitingV2Resource::class;

    protected string $view = 'filament.resources.wms-picking-tasks.pages.execute-wms-picking-task';

    protected function allowsOverPickedQuantity(): bool
    {
        return true;
    }

    protected function canAdjustPlannedQuantity(): bool
    {
        return true;
    }

    protected function getBackUrl(): string
    {
        return WmsPickingWaitingV2Resource::getUrl('index');
    }
}
