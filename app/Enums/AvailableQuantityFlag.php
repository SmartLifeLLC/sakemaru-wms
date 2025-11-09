<?php

namespace App\Enums;

/**
 * Bitmask flags for location available quantity types
 *
 * Used in locations.available_quantity_flags for fast filtering
 */
enum AvailableQuantityFlag: int
{
    case CASE = 1;      // 0001
    case PIECE = 2;     // 0010
    case CARTON = 4;    // 0100
    case UNKNOWN = 8;   // 1000

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::CASE => 'ケース',
            self::PIECE => 'バラ',
            self::CARTON => 'カートン',
            self::UNKNOWN => '未設定',
        };
    }

    /**
     * Get all available flags except UNKNOWN
     */
    public static function available(): array
    {
        return [self::CASE, self::PIECE, self::CARTON];
    }

    /**
     * Convert array of flags to bitmask value
     *
     * @param array<AvailableQuantityFlag> $flags
     * @return int Bitmask value
     */
    public static function toBitmask(array $flags): int
    {
        return array_reduce(
            $flags,
            fn(int $carry, self $flag) => $carry | $flag->value,
            0
        );
    }

    /**
     * Convert bitmask value to array of flags
     *
     * @param int $bitmask
     * @return array<AvailableQuantityFlag>
     */
    public static function fromBitmask(int $bitmask): array
    {
        $flags = [];

        foreach (self::cases() as $flag) {
            if (($bitmask & $flag->value) !== 0) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * Check if bitmask supports given flag
     */
    public static function supports(int $bitmask, self $flag): bool
    {
        return ($bitmask & $flag->value) !== 0;
    }

    /**
     * Validate bitmask (UNKNOWN cannot be combined with other flags)
     */
    public static function isValid(int $bitmask): bool
    {
        // UNKNOWN (8) cannot be combined with other flags
        if (($bitmask & self::UNKNOWN->value) !== 0) {
            return $bitmask === self::UNKNOWN->value;
        }

        // Must be a valid combination of CASE, PIECE, CARTON
        $maxValid = self::CASE->value | self::PIECE->value | self::CARTON->value; // 7
        return $bitmask > 0 && $bitmask <= $maxValid;
    }

    /**
     * Get SQL WHERE clause for filtering locations by quantity type
     *
     * @param string $quantityType 'CASE' | 'PIECE' | 'CARTON'
     * @return string SQL condition
     */
    public static function getSqlCondition(string $quantityType): string
    {
        $flag = match (strtoupper($quantityType)) {
            'CASE' => self::CASE,
            'PIECE' => self::PIECE,
            'CARTON' => self::CARTON,
            default => throw new \InvalidArgumentException("Invalid quantity type: {$quantityType}"),
        };

        return "(available_quantity_flags & {$flag->value}) != 0";
    }
}