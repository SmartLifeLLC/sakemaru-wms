<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use BackedEnum;

class StoreDistributionManagement extends AdminPage
{
    protected static string $permissionResource = 'store-distribution-management';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected string $view = 'filament.pages.distribution-app';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::STORE_DISTRIBUTION_MANAGEMENT->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::STORE_DISTRIBUTION_MANAGEMENT->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::STORE_DISTRIBUTION_MANAGEMENT->sort();
    }

    public function getTitle(): string
    {
        return EMenu::STORE_DISTRIBUTION_MANAGEMENT->label();
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function mount(): void
    {
        ini_set('memory_limit', '512M');
    }

    public function getDistributionInitialTab(): string
    {
        return 'store-view';
    }
}
