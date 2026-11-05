<?php

namespace App\Services\AutoOrder;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 商品を各種コード（JAN・自社コード・単品CD など）で探すための共通処理。
 *
 * （新）外部発注の発注候補検索と同じ探し方をする:
 * - スペース・カンマ・スラッシュ区切りで複数コードを指定できる
 * - 完全一致する商品があればそれを優先し、無いコードだけ部分一致で探す
 * - 検索コード（item_search_information）に加えて、
 *   入数情報（item_quantity_information）の単品CD・自社CD・入数CD も対象にする
 */
class ItemCodeSearchService
{
    /**
     * 入数CD を完全一致の対象にする最小桁数（短い数字で大量に当たるのを防ぐ）。
     */
    private const MIN_EXACT_QUANTITY_CODE_LENGTH = 4;

    /**
     * 入力文字列を検索コードの配列にする（全角→半角、区切り文字で分割、重複除去）。
     *
     * @return array<int, string>
     */
    public function normalizeDelimitedSearchCodes(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        $normalized = mb_convert_kana($value, 'as');

        return collect(preg_split('/[\s,、，\/／]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn ($code): string => trim((string) $code))
            ->filter()
            ->uniqueStrict()
            ->values()
            ->all();
    }

    /**
     * 完全一致で見つかった商品ID と、完全一致が無く部分一致に回すコードに分ける。
     *
     * @param  array<int, string>  $searchCodes
     * @param  callable(Builder): void|null  $eligibleItemScope  検索対象にできる商品の条件（画面側の絞り込みと同じもの）
     * @return array{0: array<int>, 1: array<int, string>}
     */
    public function resolveExactCodeSearches(array $searchCodes, ?callable $eligibleItemScope = null): array
    {
        if ($searchCodes === []) {
            return [[], []];
        }

        $searches = collect($searchCodes)
            ->map(function (string $searchCode): array {
                $variants = [$searchCode];

                // 13桁で入力された場合は、先頭の 0 を除いたコードでも探す
                if (strlen($searchCode) === 13) {
                    $variants[] = ltrim($searchCode, '0') ?: '0';
                }

                return [
                    'search_code' => $searchCode,
                    'variants' => array_values(array_unique($variants)),
                    'item_ids' => [],
                ];
            })
            ->values()
            ->all();
        $allVariants = collect($searches)->pluck('variants')->flatten()->uniqueStrict()->values()->all();
        $distinctiveQuantityCodeVariants = collect($allVariants)
            ->filter(fn (string $code): bool => strlen($code) >= self::MIN_EXACT_QUANTITY_CODE_LENGTH)
            ->values()
            ->all();

        $searchInformationMatches = DB::connection('sakemaru')
            ->table('item_search_information')
            ->whereIn('search_string', $allVariants)
            ->get(['item_id', 'search_string']);
        $quantityInformationMatches = DB::connection('sakemaru')
            ->table('item_quantity_information')
            ->where(function ($query) use ($allVariants, $distinctiveQuantityCodeVariants): void {
                $query
                    ->whereIn('product_code', $allVariants)
                    ->orWhereIn('own_code', $allVariants);

                if ($distinctiveQuantityCodeVariants !== []) {
                    $query->orWhereIn('quantity_code', $distinctiveQuantityCodeVariants);
                }
            })
            ->get(['item_id', 'product_code', 'own_code', 'quantity_code']);

        foreach ($searchInformationMatches as $match) {
            foreach ($searches as &$search) {
                if (in_array((string) $match->search_string, $search['variants'], true)) {
                    $search['item_ids'][] = (int) $match->item_id;
                }
            }
            unset($search);
        }

        foreach ($quantityInformationMatches as $match) {
            $matchedValues = array_map('strval', array_filter([
                $match->product_code,
                $match->own_code,
                $match->quantity_code,
            ], fn ($value): bool => filled($value)));

            foreach ($searches as &$search) {
                if (array_intersect($matchedValues, $search['variants']) !== []) {
                    $search['item_ids'][] = (int) $match->item_id;
                }
            }
            unset($search);
        }

        $matchedItemIds = collect($searches)->pluck('item_ids')->flatten()->unique()->values()->all();
        $eligibleExactItemIds = $matchedItemIds === []
            ? []
            : Item::query()
                ->whereIn('items.id', $matchedItemIds)
                ->when($eligibleItemScope !== null, fn (Builder $query) => $eligibleItemScope($query))
                ->pluck('items.id')
                ->map(fn ($itemId): int => (int) $itemId)
                ->all();

        foreach ($searches as &$search) {
            $search['item_ids'] = array_values(array_intersect($search['item_ids'], $eligibleExactItemIds));
        }
        unset($search);

        $exactItemIds = collect($searches)
            ->pluck('item_ids')
            ->flatten()
            ->map(fn ($itemId): int => (int) $itemId)
            ->unique()
            ->values()
            ->all();
        $partialSearchCodes = collect($searches)
            ->filter(fn (array $search): bool => $search['item_ids'] === [])
            ->pluck('search_code')
            ->values()
            ->all();

        return [$exactItemIds, $partialSearchCodes];
    }

    /**
     * 部分一致の条件を OR で足す（検索コード・単品CD・自社CD・入数CD）。
     *
     * @param  Builder<Item>  $query
     */
    public function orWherePartialCodeMatches(Builder $query, string $searchCode): void
    {
        $like = "%{$searchCode}%";

        $query->orWhereHas('item_search_information', function ($query) use ($searchCode, $like): void {
            $query
                ->where('search_string', 'like', $like)
                ->orWhereRaw('LPAD(search_string, 13, "0") = ?', [$searchCode]);
        })->orWhereExists(function ($query) use ($searchCode, $like): void {
            $query
                ->selectRaw('1')
                ->from('item_quantity_information as iqi')
                ->whereColumn('iqi.item_id', 'items.id')
                ->where(function ($query) use ($searchCode, $like): void {
                    $query
                        ->where('iqi.product_code', 'like', $like)
                        ->orWhere('iqi.own_code', 'like', $like)
                        ->orWhere('iqi.quantity_code', 'like', $like)
                        ->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$searchCode])
                        ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$searchCode]);
                });
        });
    }
}
