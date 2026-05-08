<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminPage;
use App\Filament\Widgets\DashboardShortageAllocationsWidget;
use App\Filament\Widgets\DateFilterWidget;
use App\Filament\Widgets\OrderStatusWidget;
use App\Filament\Widgets\WmsTodayShipmentStatsWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class Dashboard extends AdminPage
{
    protected string $view = 'filament.pages.dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'ダッシュボード';

    protected static ?string $title = '';

    protected static ?string $slug = '/';

    protected static ?int $navigationSort = -2;

    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DateFilterWidget::class,
            WmsTodayShipmentStatsWidget::class,
            DashboardShortageAllocationsWidget::class,
            OrderStatusWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
