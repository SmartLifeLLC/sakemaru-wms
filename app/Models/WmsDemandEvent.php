<?php

namespace App\Models;

/** Demand event (年末年始・お盆・GW・連休・任意期間). Only APPROVED rows affect recommendations. */
class WmsDemandEvent extends WmsModel
{
    public const STATUSES = ['DRAFT' => '素案', 'APPROVED' => '承認済み', 'DISABLED' => '無効'];

    protected $guarded = [];

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'approved_at' => 'datetime',
        'pre_days' => 'integer', 'post_days' => 'integer', 'year' => 'integer'];

    /** Row shape consumed by DemandEventCalendar. */
    public function toCalendarEvent(): array
    {
        return ['id' => (int) $this->id, 'type' => $this->type, 'name' => $this->name,
            'start' => $this->start_date->toDateString(), 'end' => $this->end_date->toDateString(),
            'pre_days' => (int) $this->pre_days, 'post_days' => (int) $this->post_days];
    }
}
