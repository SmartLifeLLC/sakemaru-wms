<?php

namespace App\Filament\Pages;

class AutoReplenishmentHistory extends AutoReplenishmentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'auto-order/history';

    protected static string $permissionResource = 'auto-replenishment-history';

    protected static string $screen = 'history';

    protected static ?string $navigationLabel = '自動発注履歴';

    protected static ?int $navigationSort = 3;
}
