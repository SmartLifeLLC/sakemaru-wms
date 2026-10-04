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
     * @return array<int, int>
     */
    private static function intList(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $values))));
    }
}
