<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class AutoOrderGuide extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = '自動発注ガイド';

    protected static ?string $slug = 'auto-order-guide';

    public function getTitle(): string
    {
        return '';
    }

    public function getHeading(): string
    {
        return '';
    }

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.auto-order-guide';

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::AUTO_ORDER->label();
    }
}
