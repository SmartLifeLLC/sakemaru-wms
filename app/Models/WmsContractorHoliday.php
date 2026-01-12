<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先臨時休業日
 *
 * @property int $id
 * @property int $contractor_id
 * @property \Carbon\Carbon $holiday_date
 * @property string|null $reason
 */
class WmsContractorHoliday extends WmsModel
{
    protected $table = 'wms_contractor_holidays';

    protected $fillable = [
        'contractor_id',
        'holiday_date',
        'reason',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * 指定発注先の指定日が休業日かどうか
     */
    public static function isHoliday(int $contractorId, Carbon|string $date): bool
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return self::where('contractor_id', $contractorId)
            ->where('holiday_date', $dateString)
            ->exists();
    }

    /**
     * 指定発注先の次の営業日を取得
     */
    public static function getNextBusinessDay(int $contractorId, Carbon $fromDate): Carbon
    {
        $date = $fromDate->copy();
        $maxIterations = 30;

        for ($i = 0; $i < $maxIterations; $i++) {
            if (! self::isHoliday($contractorId, $date)) {
                return $date;
            }
            $date->addDay();
        }

        return $fromDate;
    }

    /**
     * 指定期間の休業日を取得
     */
    public static function getHolidaysInPeriod(int $contractorId, Carbon $startDate, Carbon $endDate): array
    {
        return self::where('contractor_id', $contractorId)
            ->whereBetween('holiday_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('holiday_date')
            ->map(fn ($date) => $date->toDateString())
            ->toArray();
    }
}
