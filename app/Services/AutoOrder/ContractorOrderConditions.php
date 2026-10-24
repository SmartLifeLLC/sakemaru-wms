<?php

namespace App\Services\AutoOrder;

use App\Models\WmsContractorSetting;

class ContractorOrderConditions
{
    public function forContractors(array $ids): array
    {
        return WmsContractorSetting::query()
            ->whereIn('contractor_id', array_values(array_unique(array_filter($ids))))
            ->pluck('order_conditions', 'contractor_id')
            ->map(fn ($note): string => (string) $note)
            ->all();
    }

    public function attach(array $rows): array
    {
        $notes = $this->forContractors(array_column($rows, 'contractor_id'));

        return array_map(fn (array $row): array => array_merge($row, [
            'contractor_order_conditions' => $notes[(int) ($row['contractor_id'] ?? 0)] ?? '',
        ]), $rows);
    }
}
