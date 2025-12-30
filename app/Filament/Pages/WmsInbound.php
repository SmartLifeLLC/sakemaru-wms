<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WmsInbound extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected string $view = 'filament.pages.wms-inbound';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::INBOUND_DASHBOARD->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::INBOUND_DASHBOARD->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::INBOUND_DASHBOARD->sort();
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
}
