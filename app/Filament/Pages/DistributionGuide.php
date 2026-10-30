<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use App\Filament\Pages\Concerns\RegistersOnlyForKuraWarehouse;
use App\Filament\Support\AdminPage;
use BackedEnum;

class DistributionGuide extends AdminPage
{
    use RegistersOnlyForKuraWarehouse;

    protected static string $permissionResource = 'distribution-adjustment';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = '分配解説';

    protected static ?string $slug = 'distribution-guide';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.distribution-guide';

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::DISTRIBUTION->label();
    }

    public function getTitle(): string
    {
        return '分配解説';
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }
}
