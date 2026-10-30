<?php

namespace App\Services\SundryInventoryCount;

use Carbon\CarbonImmutable;

/**
 * 棚卸し（雑貨）の設定値。config/wms_sundry_inventory.php を読み、未設定時は既定値を返す。
 */
class SundryInventorySettings
{
    private const DEFAULT_CATEGORY_CODES = [2040, 2041, 2043, 2044, 2047, 2051, 2052];

    private const DEFAULT_AMOUNT_CATEGORY_CODES = [1004];

    private const DEFAULT_OPENING_DATE = '2026-05-05';

    private const PLUG_SUBCATEGORY_CODES = [3110, 3240, 3330, 3520, 3810, 3811];

    private const WEIGHED_ITEM_CODES = [
        129002, 129003, 129005, 129004, 129007, 129006, 129019, 129008,
        129010, 129009, 129012, 129011, 129014, 129013, 129015, 129016,
        129017, 129018, 129020, 129022, 129021, 129023, 129024,
    ];

    private const WEIGHED_LOSS_RATE = 0.04;

    /**
     * @return array<int, int>
     */
    public static function defaultCategoryCodes(): array
    {
        return self::intList(config('wms_sundry_inventory.default_category_codes', self::DEFAULT_CATEGORY_CODES));
    }

    /**
     * 金額で棚卸しする大分類（在庫管理なし商品）の既定コード。
     *
     * @return array<int, int>
     */
    public static function defaultAmountCategoryCodes(): array
    {
        return self::intList(config('wms_sundry_inventory.default_amount_category_codes', self::DEFAULT_AMOUNT_CATEGORY_CODES));
    }

    public static function defaultOpeningDate(): string
    {
        $date = (string) (config('wms_sundry_inventory.default_opening_date') ?: self::DEFAULT_OPENING_DATE);

        return CarbonImmutable::parse($date)->toDateString();
    }

    /**
     * @return array<int, int>
     */
    public static function plugSubcategoryCodes(): array
    {
        return self::intList(config('wms_sundry_inventory.plug_subcategory_codes', self::PLUG_SUBCATEGORY_CODES));
    }

    /**
     * 量り売りの棚卸し対象商品（商品CD）。並びが出力順。
     *
     * @return array<int, int>
     */
    public static function weighedItemCodes(): array
    {
        return self::intList(config('wms_sundry_inventory.weighed_item_codes', self::WEIGHED_ITEM_CODES));
    }

    /**
     * 量り売りのロス率（期間売上数量に対する割合）。
     */
    public static function weighedLossRate(): float
    {
        $rate = config('wms_sundry_inventory.weighed_loss_rate');

        return is_numeric($rate) && (float) $rate >= 0 ? (float) $rate : self::WEIGHED_LOSS_RATE;
    }

    /**
     * @return array<int, int>
     */
    private static function intList(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $values))));
    }
}
