<?php

namespace App\Services\AutoReplenishment;

use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Support\Facades\DB;

class JxRouteFingerprint
{
    /** Persist only a hash of routing versions, never JX credentials. */
    public function fingerprint(int $contractorId, bool $lock = false): string
    {
        $db = DB::connection('sakemaru');
        $mapping = app(OrderTransmissionService::class)->getGenerator()?->getTransmissionContractorMapping() ?? [];
        $target = (int) ($mapping[$contractorId] ?? $contractorId);
        $settings = $db->table('wms_order_jx_settings')->where('contractor_id', $target)->where('is_active', true)
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->get(['id', 'contractor_id', 'updated_at']);
        throw_if($settings->isEmpty(), \RuntimeException::class, '有効なJX経路がありません。');
        $route = $db->table('wms_contractor_settings')->whereIn('contractor_id', [$contractorId, $target])
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->get(['id', 'contractor_id', 'transmission_contractor_id', 'updated_at']);

        return hash('sha256', json_encode([$target, $settings->all(), $route->all()], JSON_THROW_ON_ERROR));
    }
}
