<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/** Search the same active JAN/own-code records used by Hana product search. */
final class ItemSearchCodes
{
    public function terms(string $input): array
    {
        return array_values(array_unique(preg_split('/[\s,、，\/／]+/u', mb_convert_kana($input, 'as'), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    public function apply(Builder|EloquentBuilder $query, int $clientId, string $input, string $itemColumn): void
    {
        $terms = $this->terms($input);
        if ($terms === []) {
            return;
        }

        // EXISTS keeps products unique even when several registered codes match.
        $query->whereExists(function (Builder $codes) use ($clientId, $terms, $itemColumn) {
            $codes->selectRaw('1')->from('item_search_information as search_codes')
                ->whereColumn('search_codes.item_id', $itemColumn)
                ->where('search_codes.client_id', $clientId)->where('search_codes.is_active', true)
                ->where(function (Builder $matches) use ($terms) {
                    foreach ($terms as $term) {
                        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
                        $matches->orWhereRaw("search_codes.search_string LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
                    }
                });
        });
    }
}
