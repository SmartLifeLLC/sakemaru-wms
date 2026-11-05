<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Event master maintenance: yearly proposals, approval, custom events. Approval is explicit; nothing auto-approves. */
class DemandEventService
{
    public function __construct(private DemandEventCalendar $calendar, private DemandEventHistoryReader $reader) {}

    /** Insert DRAFT proposals for one year (existing rows with the same type/start are kept). Returns the number inserted. */
    public function proposeYear(int $client, int $year, int $actorId): int
    {
        $proposals = $this->calendar->standardEvents($year, $this->reader->holidays($year));

        return DB::connection('sakemaru')->transaction(function () use ($client, $year, $actorId, $proposals) {
            $existing = WmsDemandEvent::where('client_id', $client)->whereIn('type', array_unique(array_column($proposals, 'type')))->get()
                ->map(fn ($e) => $e->type.'|'.$e->start_date->toDateString())->flip();
            $inserted = 0;
            foreach ($proposals as $proposal) {
                if (isset($existing[$proposal['type'].'|'.$proposal['start']])) {
                    continue;
                }
                WmsDemandEvent::create(['client_id' => $client, 'year' => $year, 'type' => $proposal['type'], 'name' => $proposal['name'],
                    'start_date' => $proposal['start'], 'end_date' => $proposal['end'], 'pre_days' => $proposal['pre_days'], 'post_days' => $proposal['post_days'],
                    'status' => 'DRAFT', 'created_by' => $actorId, 'updated_by' => $actorId]);
                $inserted++;
            }

            return $inserted;
        }, 3);
    }

    public function save(int $client, array $data, int $actorId, ?int $id = null): WmsDemandEvent
    {
        $values = Validator::make($data, [
            'type' => 'required|in:'.implode(',', DemandEventCalendar::TYPES), 'name' => 'required|string|max:60',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'pre_days' => 'nullable|integer|min:0|max:'.DemandEventCalendar::MAX_PRE_POST_DAYS, 'post_days' => 'nullable|integer|min:0|max:'.DemandEventCalendar::MAX_PRE_POST_DAYS, 'note' => 'nullable|string|max:255',
        ], [], ['type' => '種別', 'name' => '名称', 'start_date' => '開始日', 'end_date' => '終了日', 'pre_days' => '前日数', 'post_days' => '後日数', 'note' => '備考'])->validate();
        $normalized = $this->calendar->normalize([['type' => $values['type'], 'name' => $values['name'], 'start' => $values['start_date'], 'end' => $values['end_date'],
            'pre_days' => $values['pre_days'] ?? null, 'post_days' => $values['post_days'] ?? null]])[0];

        return DB::connection('sakemaru')->transaction(function () use ($client, $values, $normalized, $actorId, $id) {
            $event = $id ? WmsDemandEvent::where('client_id', $client)->lockForUpdate()->findOrFail($id) : new WmsDemandEvent(['client_id' => $client, 'created_by' => $actorId]);
            throw_if(WmsDemandEvent::where(['client_id' => $client, 'type' => $normalized['type'], 'start_date' => $normalized['start']])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(),
                \RuntimeException::class, '同じ種別・開始日のイベントが既にあります。');
            $event->fill(['year' => (int) substr($normalized['start'], 0, 4), 'type' => $normalized['type'], 'name' => $normalized['name'],
                'start_date' => $normalized['start'], 'end_date' => $normalized['end'], 'pre_days' => $normalized['pre_days'], 'post_days' => $normalized['post_days'],
                'note' => $values['note'] ?? null, 'updated_by' => $actorId]);
            // Editing an approved event sends it back to DRAFT so the change is reviewed.
            if ($event->exists && $event->status === 'APPROVED' && $event->isDirty(['type', 'start_date', 'end_date', 'pre_days', 'post_days'])) {
                $event->status = 'DRAFT';
                $event->approved_by = null;
                $event->approved_at = null;
            }
            $event->status ??= 'DRAFT';
            $event->save();

            return $event;
        }, 3);
    }

    public function setStatus(int $client, int $id, string $status, int $actorId): WmsDemandEvent
    {
        throw_unless(array_key_exists($status, WmsDemandEvent::STATUSES), \RuntimeException::class, 'イベントの状態を確認してください。');

        return DB::connection('sakemaru')->transaction(function () use ($client, $id, $status, $actorId) {
            $event = WmsDemandEvent::where('client_id', $client)->lockForUpdate()->findOrFail($id);
            $event->fill(['status' => $status, 'updated_by' => $actorId,
                'approved_by' => $status === 'APPROVED' ? $actorId : null, 'approved_at' => $status === 'APPROVED' ? now() : null]);
            if ($status === 'APPROVED') {
                // Approving accepts the factors shown with the event: candidate factors of its keys go into use.
                $keys = $this->calendar->normalize([$event->toCalendarEvent()])[0]['profile_keys'];
                if (app(DemandEventLearningService::class)->promote($client, $keys) > 0) {
                    $event->factor_trained_through = WmsDemandEventProfile::where(['client_id' => $client, 'stage' => WmsDemandEventProfile::ACTIVE])->whereIn('profile_key', $keys)->max('trained_through');
                }
            }
            $event->save();

            return $event;
        }, 3);
    }
}
