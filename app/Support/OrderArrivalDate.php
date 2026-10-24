<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

final class OrderArrivalDate
{
    public static function maximum(?Carbon $basis = null): string
    {
        return ($basis ?? now())->copy()->startOfDay()->addMonthsNoOverflow(6)->subDay()->toDateString();
    }

    public static function normalizeForOrder(string $input, ?Carbon $basis = null): string
    {
        $basis = ($basis ?? now())->copy()->startOfDay();
        $date = self::normalize($input, $basis);
        if ($date < $basis->toDateString()) {
            throw new InvalidArgumentException('入荷予定日に過去日は指定できません。');
        }
        if ($date > self::maximum($basis)) {
            throw new InvalidArgumentException('入荷予定日は6か月以上先にできません。');
        }

        return $date;
    }

    public static function normalize(string $input, ?Carbon $basis = null): string
    {
        $input = trim(mb_convert_kana($input, 'as'));
        $basis ??= now();
        if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $input, $parts)) {
            [, $year, $month, $day] = $parts;
        } elseif (preg_match('/^\d{1,8}$/', $input)) {
            $year = $basis->year;
            $month = $basis->month;
            $day = substr($input, -2);
            if (in_array(strlen($input), [3, 4], true)) {
                $month = substr($input, 0, -2);
            } elseif (in_array(strlen($input), [6, 8], true)) {
                $year = strlen($input) === 6 ? 2000 + (int) substr($input, 0, 2) : substr($input, 0, 4);
                $month = substr($input, -4, 2);
            } elseif (strlen($input) > 2) {
                $day = 0;
            }
        } else {
            throw new InvalidArgumentException('入荷予定日を日・月日・年月日の数字、または年月日を区切った形式で入力してください。');
        }

        if ((int) $year < 1000 || (int) $year > 9999 || ! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidArgumentException('入荷予定日に存在しない日付が入力されています。年月日を確認してください。');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
