<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const GROUP_NO = 'WG-20260518-RHWXGR0L';

    public function up(): void
    {
        $this->patchToGroupShippingDate();
    }

    public function down(): void
    {
        $group = $this->findWaveGroup();
        if (! $group) {
            return;
        }

        $conditions = json_decode($group->conditions ?? '[]', true) ?: [];
        $originalDate = collect($conditions['shipping_dates'] ?? [])
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->sort()
            ->first();

        if (! $originalDate) {
            return;
        }

        $this->patchDates($group->id, $originalDate);
    }

    private function patchToGroupShippingDate(): void
    {
        $group = $this->findWaveGroup();
        if (! $group) {
            return;
        }

        $this->patchDates($group->id, (string) $group->shipping_date);
    }

    private function findWaveGroup(): ?object
    {
        return DB::connection('sakemaru')
            ->table('wms_wave_groups')
            ->where('group_no', self::GROUP_NO)
            ->first(['id', 'shipping_date', 'conditions']);
    }

    private function patchDates(int $waveGroupId, string $shippingDate): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $shippingDate)) {
            return;
        }

        DB::connection('sakemaru')->transaction(function () use ($waveGroupId, $shippingDate): void {
            DB::connection('sakemaru')
                ->table('wms_waves as ww')
                ->join('wms_wave_settings as ws', 'ws.id', '=', 'ww.wms_wave_setting_id')
                ->join('delivery_courses as dc', 'dc.id', '=', 'ws.delivery_course_id')
                ->join('warehouses as wh', 'wh.id', '=', 'dc.warehouse_id')
                ->where('ww.wave_group_id', $waveGroupId)
                ->where('ww.status', 'PENDING')
                ->update([
                    'ww.shipping_date' => $shippingDate,
                    'ww.wave_no' => DB::raw("CONCAT('W', LPAD(wh.code, 3, '0'), '-C', dc.code, '-', DATE_FORMAT('{$shippingDate}', '%Y%m%d'), '-', ww.id)"),
                    'ww.updated_at' => now(),
                ]);

            DB::connection('sakemaru')
                ->table('wms_picking_tasks as pt')
                ->join('wms_waves as ww', 'ww.id', '=', 'pt.wave_id')
                ->where('ww.wave_group_id', $waveGroupId)
                ->whereIn('pt.status', ['PENDING', 'PICKING_READY'])
                ->update([
                    'pt.shipment_date' => $shippingDate,
                    'pt.updated_at' => now(),
                ]);
        });
    }
};
