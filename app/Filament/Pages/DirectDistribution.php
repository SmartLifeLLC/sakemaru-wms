<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Pages\Concerns\RegistersOnlyForKuraWarehouse;
use App\Filament\Support\AdminPage;
use BackedEnum;

class DirectDistribution extends AdminPage
{
    use RegistersOnlyForKuraWarehouse;

    protected static string $permissionResource = 'direct-distribution';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected string $view = 'filament.pages.distribution-app';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::DIRECT_DISTRIBUTION->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::DIRECT_DISTRIBUTION->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::DIRECT_DISTRIBUTION->sort();
    }

    public function getTitle(): string
    {
        return EMenu::DIRECT_DISTRIBUTION->label();
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
        return 'direct';
    }
}
