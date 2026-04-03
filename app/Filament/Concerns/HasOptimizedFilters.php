<?php

namespace App\Filament\Concerns;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Filament\Tables\Filters\SelectFilter;

trait HasOptimizedFilters
{
    protected static function warehouseFilter(): SelectFilter
    {
        return SelectFilter::make('warehouse_id')
            ->label('倉庫')
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');

                return Warehouse::query()
                    ->where('is_active', true)
                    ->where(fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orderBy('code')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                    ->toArray();
            });
    }

    protected static function contractorFilter(): SelectFilter
    {
        return SelectFilter::make('contractor_id')
            ->label('発注先')
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');

                return Contractor::query()
                    ->where(fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orderBy('code')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                    ->toArray();
            });
    }

    protected static function supplierFilter(): SelectFilter
    {
        return SelectFilter::make('supplier_id')
            ->label('仕入先')
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');

                return Supplier::query()
                    ->with('partner')
                    ->whereHas('partner', fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn ($s) => [$s->id => "[{$s->partner?->code}]{$s->partner?->name}"])
                    ->toArray();
            });
    }

    protected static function batchCodeFilter(string $modelClass): SelectFilter
    {
        return SelectFilter::make('batch_code')
            ->label('実行CD')
            ->options(fn () => $modelClass::query()
                ->select('batch_code')
                ->distinct()
                ->orderByDesc('batch_code')
                ->limit(50)
                ->pluck('batch_code', 'batch_code')
                ->toArray())
            ->searchable();
    }

    protected static function statusFilter(string $enumClass): SelectFilter
    {
        return SelectFilter::make('status')
            ->label('ステータス')
            ->options(collect($enumClass::cases())->mapWithKeys(fn ($s) => [
                $s->value => $s->label(),
            ]));
    }
}
