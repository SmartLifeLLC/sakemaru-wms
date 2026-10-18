<?php

namespace App\Filament\Pages;

class AutoReplenishmentChecklist extends AutoReplenishmentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'auto-order/checklist';

    protected static string $permissionResource = 'auto-replenishment-checklist';

    protected static string $screen = 'checklist';

    protected static ?string $navigationLabel = '自動発注確認リスト';

    protected static ?int $navigationSort = 2;
}
