<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Pages\Concerns\RegistersOnlyForKuraWarehouse;
use App\Filament\Support\AdminPage;
use BackedEnum;

class DistributionAdjustment extends AdminPage
{
    use RegistersOnlyForKuraWarehouse;

    protected static string $permissionResource = 'distribution-adjustment';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected string $view = 'filament.pages.distribution-app';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::DISTRIBUTION_ADJUSTMENT->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::DISTRIBUTION_ADJUSTMENT->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::DISTRIBUTION_ADJUSTMENT->sort();
    }

    public function getTitle(): string
    {
        return EMenu::DISTRIBUTION_ADJUSTMENT->label();
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
        return 'allocation';
    }
}
