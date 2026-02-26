<?php

namespace App\Filament\Resources\ExpirationAlerts;

use App\Enums\EMenu;
use App\Filament\Resources\ExpirationAlerts\Pages\ListExpirationAlerts;
use App\Filament\Resources\ExpirationAlerts\Tables\ExpirationAlertsTable;
use App\Models\Sakemaru\RealStockLot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class ExpirationAlertResource extends Resource
{
    protected static ?string $model = RealStockLot::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'expiration-alerts';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::EXPIRATION_ALERTS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::EXPIRATION_ALERTS->label();
    }

    public static function getModelLabel(): string
    {
        return '賞味期限アラート';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::EXPIRATION_ALERTS->sort();
    }

    public static function table(Table $table): Table
    {
        return ExpirationAlertsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpirationAlerts::route('/'),
        ];
    }
}
