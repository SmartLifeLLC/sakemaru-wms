<?php

namespace App\Services\AutoOrder;

use App\Models\WmsContractorSetting;
use Illuminate\Support\Facades\DB;

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
        if ($rows === []) {
            return [];
        }

        $notes = $this->forContractors(array_column($rows, 'contractor_id'));
        $supplierNotes = $this->forSuppliers(array_column($rows, 'supplier_id'));

        return array_map(function (array $row) use ($notes, $supplierNotes): array {
            $contractorNote = $notes[(int) ($row['contractor_id'] ?? 0)] ?? '';
            $supplierNote = collect($supplierNotes[(int) ($row['supplier_id'] ?? 0)] ?? [])
                ->reject(fn (string $note): bool => $note === trim($contractorNote))
                ->implode("\n");

            return array_merge($row, [
                'contractor_order_conditions' => $contractorNote,
                'supplier_order_conditions' => $supplierNote,
            ]);
        }, $rows);
    }

    /** @return array<int, array<int, string>> */
    private function forSuppliers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        // 仕入先ID・発注先ID・取引先CDは別物。発注先の仕入先関連から条件を取得する。
        return DB::connection('sakemaru')->table('contractors as c')
            ->join('suppliers as s', 's.id', '=', 'c.supplier_id')
            ->join('wms_contractor_settings as settings', 'settings.contractor_id', '=', 'c.id')
            ->whereColumn('c.client_id', 's.client_id')
            ->where('c.client_id', config('client.id', config('app.client_id')))
            ->whereIn('c.supplier_id', $ids)
            ->where('c.is_active', true)
            ->whereNotNull('settings.order_conditions')
            ->orderBy('c.code')
            ->get(['c.supplier_id', 'settings.order_conditions'])
            ->groupBy('supplier_id')
            ->map(fn ($rows): array => $rows->pluck('order_conditions')
                ->map(fn ($note): string => trim((string) $note))
                ->filter(fn (string $note): bool => $note !== '')->unique()->values()->all())
            ->all();
    }
}
