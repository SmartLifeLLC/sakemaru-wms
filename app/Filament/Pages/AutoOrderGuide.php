<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use BackedEnum;
use App\Filament\Support\AdminPage;
use Filament\Support\Icons\Heroicon;

class AutoOrderGuide extends AdminPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = '発注解説';

    protected static ?string $slug = 'order-guide';

    public function getTitle(): string
    {
        return '';
    }

    public function getHeading(): string
    {
        return '';
    }

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.order-guide';

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::GUIDE_ORDER->label();
    }
}
