<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminPage;
use Filament\Support\Icons\Heroicon;

class ModalShowcase extends AdminPage
{
    protected static \BackedEnum|string|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'モーダルショーケース';

    protected static ?string $title = 'モーダルデザイン ショーケース';

    protected static \UnitEnum|string|null $navigationGroup = '開発ツール';

    protected static ?int $navigationSort = 999;

    protected string $view = 'filament.pages.modal-showcase';
}
