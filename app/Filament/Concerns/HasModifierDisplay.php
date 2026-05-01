<?php

namespace App\Filament\Concerns;

use App\Models\Sakemaru\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

trait HasModifierDisplay
{
    protected static function modifierColumn(): TextColumn
    {
        return TextColumn::make('modifier_display_name')
            ->label('担当者')
            ->state(fn ($record) => mb_substr($record->modifier_display_name, 0, 6))
            ->badge()
            ->color(fn ($record) => $record->modified_by ? 'info' : 'gray')
            ->width('70px')
            ->alignCenter();
    }

    protected static function modifierFilter(): SelectFilter
    {
        return SelectFilter::make('modified_by')
            ->label('担当者')
            ->searchable()
            ->options(fn () => self::buildModifierOptions())
            ->getSearchResultsUsing(fn (string $search) => self::buildModifierOptions($search))
            ->query(function ($query, array $data) {
                if (blank($data['value'])) {
                    return;
                }
                if ($data['value'] === '0') {
                    $query->whereNull('modified_by');
                } else {
                    $query->where('modified_by', $data['value']);
                }
            });
    }

    private static function buildModifierOptions(?string $search = null): array
    {
        $query = User::query()
            ->whereIn('id', fn ($q) => $q
                ->select('modified_by')
                ->from(static::getFilterModelTable())
                ->whereNotNull('modified_by')
                ->distinct());

        if ($search) {
            $search = mb_convert_kana($search, 'as');
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        $results = $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => "[{$u->code}]{$u->name}"])
            ->toArray();

        if (! $search || str_contains('システム', $search)) {
            $results = ['0' => 'システム'] + $results;
        }

        return $results;
    }

    abstract protected static function getFilterModelTable(): string;
}
