<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WmsOutbound extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected string $view = 'filament.pages.wms-outbound';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::OUTBOUND_DASHBOARD->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::OUTBOUND_DASHBOARD->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::OUTBOUND_DASHBOARD->sort();
    }

    public function getTitle(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\WmsOutboundOverview::class,
        ];
    }
}
