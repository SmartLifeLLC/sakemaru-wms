<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * 祝日マスタ
 *
 * @property int $id
 * @property \Carbon\Carbon $holiday_date
 * @property string $holiday_name
 */
class WmsNationalHoliday extends WmsModel
{
    protected $table = 'wms_national_holidays';

    protected $fillable = [
        'holiday_date',
        'holiday_name',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    /**
     * 指定日が祝日かどうか
     */
    public static function isHoliday(Carbon $date): bool
    {
        return self::where('holiday_date', $date->toDateString())->exists();
    }

    /**
     * 指定日の祝日名を取得
     */
    public static function getHolidayName(Carbon $date): ?string
    {
        return self::where('holiday_date', $date->toDateString())->value('holiday_name');
    }

    /**
     * 指定年の祝日を取得
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('holiday_date', $year);
    }

    /**
     * 指定期間の祝日を取得
     */
    public function scopeForPeriod(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('holiday_date', [$startDate->toDateString(), $endDate->toDateString()]);
    }

    /**
     * 日本の祝日データを生成（指定年）
     */
    public static function generateJapaneseHolidays(int $year): array
    {
        $holidays = [];

        // 固定祝日
        $fixedHolidays = [
            ['01-01', '元日'],
            ['02-11', '建国記念の日'],
            ['02-23', '天皇誕生日'],
            ['04-29', '昭和の日'],
            ['05-03', '憲法記念日'],
            ['05-04', 'みどりの日'],
            ['05-05', 'こどもの日'],
            ['08-11', '山の日'],
            ['11-03', '文化の日'],
            ['11-23', '勤労感謝の日'],
        ];

        foreach ($fixedHolidays as [$monthDay, $name]) {
            $holidays[] = [
                'holiday_date' => "{$year}-{$monthDay}",
                'holiday_name' => $name,
            ];
        }

        // 成人の日（1月第2月曜日）
        $holidays[] = [
            'holiday_date' => self::getNthWeekday($year, 1, 1, 2),
            'holiday_name' => '成人の日',
        ];

        // 海の日（7月第3月曜日）
        $holidays[] = [
            'holiday_date' => self::getNthWeekday($year, 7, 1, 3),
            'holiday_name' => '海の日',
        ];

        // 敬老の日（9月第3月曜日）
        $holidays[] = [
            'holiday_date' => self::getNthWeekday($year, 9, 1, 3),
            'holiday_name' => '敬老の日',
        ];

        // スポーツの日（10月第2月曜日）
        $holidays[] = [
            'holiday_date' => self::getNthWeekday($year, 10, 1, 2),
            'holiday_name' => 'スポーツの日',
        ];

        // 春分の日（3月20日または21日）
        $holidays[] = [
            'holiday_date' => "{$year}-03-".self::getVernalEquinoxDay($year),
            'holiday_name' => '春分の日',
        ];

        // 秋分の日（9月22日または23日）
        $holidays[] = [
            'holiday_date' => "{$year}-09-".self::getAutumnalEquinoxDay($year),
            'holiday_name' => '秋分の日',
        ];

        return $holidays;
    }

    /**
     * 第N週の指定曜日の日付を取得
     */
    private static function getNthWeekday(int $year, int $month, int $dayOfWeek, int $nth): string
    {
        $date = Carbon::create($year, $month, 1);
        $count = 0;

        while ($count < $nth) {
            if ($date->dayOfWeek === $dayOfWeek) {
                $count++;
                if ($count === $nth) {
                    return $date->format('Y-m-d');
                }
            }
            $date->addDay();
        }

        return $date->format('Y-m-d');
    }

    /**
     * 春分の日を計算
     */
    private static function getVernalEquinoxDay(int $year): string
    {
        $day = (int) (20.8431 + 0.242194 * ($year - 1980) - (int) (($year - 1980) / 4));

        return str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /**
     * 秋分の日を計算
     */
    private static function getAutumnalEquinoxDay(int $year): string
    {
        $day = (int) (23.2488 + 0.242194 * ($year - 1980) - (int) (($year - 1980) / 4));

        return str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }
}
