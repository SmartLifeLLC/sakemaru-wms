<?php

namespace App\Services\AutoReplenishment;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsContractorSetting;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Support\Facades\DB;

class OrderRouteEligibility
{
    /** FAX is an editor-only reference route, never an executable route. */
    public function referenceContractorIds(int $clientId): array
    {
        return DB::connection('sakemaru')->table('contractors')->where(['client_id' => $clientId, 'is_active' => 1])
            ->whereNotIn('id', array_unique(array_merge($this->contractorIds('EOS'), $this->contractorIds('LOGISTICS'))))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function assertReferenceEligible(int $clientId, int $contractorId): void
    {
        throw_unless(in_array($contractorId, $this->referenceContractorIds($clientId), true), \RuntimeException::class,
            '参考計算の発注先が変更されています。商品を選び直してください。');
    }

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
