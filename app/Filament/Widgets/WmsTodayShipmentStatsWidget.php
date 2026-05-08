<?php

namespace App\Filament\Widgets;

use App\Models\Sakemaru\ClientSetting;
use App\Services\WmsStatsService;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class WmsTodayShipmentStatsWidget extends Widget
{
    protected string $view = 'filament.widgets.wms-today-shipment-stats-widget';

    protected int|string|array $columnSpan = 'full';

    public string $filterDate = '';

    public array $summary = [];

    public ?string $lastCalculatedAt = null;

    public ?string $loadError = null;

    public function mount(): void
    {
        $this->filterDate = $this->filterDate ?: ClientSetting::systemDateYMD();
        $this->loadStats();
    }

    #[On('filter-date-updated')]
    public function onFilterDateUpdated(string $filterDate): void
    {
        $this->filterDate = $filterDate;
        $this->loadStats();
    }

    public function loadStats(bool $force = false): void
    {
        $this->loadError = null;

        try {
            $date = Carbon::parse($this->filterDate);
            $statsService = app(WmsStatsService::class);
            $stats = $statsService->statsForWarehouses($date, null, $force);
            $this->summary = $statsService->summarize($date, null, false);
            $this->lastCalculatedAt = $stats
                ->pluck('last_calculated_at')
                ->filter()
                ->sortDesc()
                ->first()?->format('Y/m/d H:i');
        } catch (\Throwable $e) {
            report($e);
            $this->loadError = $e->getMessage();
            $this->summary = [];
            $this->lastCalculatedAt = null;
        }
    }
}
