<?php

namespace App\Filament\Support\Tables\Columns;

use App\Enums\QuantityType;
use Filament\Tables\Columns\TextColumn;

class QuantityTypeColumn
{
    /**
     * Create a standardized quantity type column with badge display
     *
     * @param  string  $columnName  The column name (e.g., 'qty_type_at_order', 'ordered_qty_type')
     * @param  string  $label  The column label (default: '受注単位')
     * @param  bool  $isToggledHiddenByDefault  Whether the column is hidden by default
     */
    public static function make(
        string $columnName = 'qty_type_at_order',
        string $label = '受注単位',
        bool $isToggledHiddenByDefault = false
    ): TextColumn {
        return TextColumn::make($columnName)
            ->label($label)
            ->formatStateUsing(fn (?string $state): string => $state ? (QuantityType::tryFrom($state)?->name() ?? $state) : '-')
            ->badge()
            ->color(fn (?string $state): string => match ($state) {
                'CASE' => 'success',
                'PIECE' => 'info',
                default => 'gray',
            })
            ->alignment('center')
            ->toggleable(isToggledHiddenByDefault: $isToggledHiddenByDefault);
    }
}
