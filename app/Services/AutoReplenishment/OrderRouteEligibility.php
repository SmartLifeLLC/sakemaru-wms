<?php

namespace App\Services\AutoReplenishment;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsContractorSetting;
use App\Services\AutoOrder\OrderTransmissionService;

class OrderRouteEligibility
{
    public function contractorIds(string $channel): array
    {
        return match ($channel) {
            'EOS' => app(OrderTransmissionService::class)->jxOrderTargetContractorIds(),
            'LOGISTICS' => WmsContractorSetting::query()->where('transmission_type', TransmissionType::INTERNAL->value)
                ->pluck('contractor_id')->map(fn ($id) => (int) $id)->all(),
            default => [],
        };
    }

    public function assertEligible(string $channel, int $contractorId, ?array $eligibleIds = null): void
    {
        throw_unless(in_array($channel, ['EOS', 'LOGISTICS'], true) && in_array($contractorId, $eligibleIds ?? $this->contractorIds($channel), true), \RuntimeException::class,
            $channel === 'LOGISTICS'
                ? '物流発注に設定された発注先を選択してください。'
                : 'EOS送信が有効な発注先を選択してください。FAX発注は自動発注の対象外です。');
    }
}
