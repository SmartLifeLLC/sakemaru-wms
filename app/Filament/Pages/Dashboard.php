<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardShortageAllocationsWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Dashboard extends Page
{
    protected string $view = 'filament.pages.dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'ダッシュボード';

    protected static ?string $title = 'ダッシュボード';

    protected static ?string $slug = '/';

    protected static ?int $navigationSort = -2;

    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DashboardShortageAllocationsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
