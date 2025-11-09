<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class FloorPlanEditor extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = Heroicon::OutlinedMap;

    protected string $view = 'filament.pages.floor-plan-editor';

    protected static \UnitEnum|string|null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::MASTER->label();
    }

    public static function getNavigationLabel(): string
    {
        return '倉庫フロアプラン';
    }

    public static function getNavigationSort(): ?int
    {
        return 50;
    }

    public function getTitle(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return true;
    }
}
