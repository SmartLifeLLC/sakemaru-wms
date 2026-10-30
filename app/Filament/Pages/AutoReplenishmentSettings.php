<?php

namespace App\Filament\Pages;

class AutoReplenishmentSettings extends AutoReplenishmentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $slug = 'auto-order/settings';

    protected static ?string $navigationLabel = '商品別発注設定';

    protected static ?int $navigationSort = 1;
}
