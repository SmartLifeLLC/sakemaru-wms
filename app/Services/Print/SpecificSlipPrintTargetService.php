<?php

namespace App\Services\Print;

use App\Models\Sakemaru\ClientPrinterDriver;
use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SpecificSlipPrintTargetService
{
    public function collectForRecord(WmsPickingTask $record): Collection
    {
        return $this->collect(
            (int) $record->delivery_course_id,
            $record->shipment_date instanceof \DateTimeInterface
                ? $record->shipment_date->format('Y-m-d')
                : (string) $record->shipment_date,
            (int) $record->warehouse_id,
            $record->wave_id ? (int) $record->wave_id : null,
        );
    }

    public function collect(
        int $deliveryCourseId,
        string $shipmentDate,
        int $warehouseId,
        ?int $waveId = null,
    ): Collection {
        $taskIds = WmsPickingTask::query()
            ->where('delivery_course_id', $deliveryCourseId)
            ->where('shipment_date', $shipmentDate)
            ->when($waveId, fn ($query) => $query->where('wave_id', $waveId))
            ->pluck('id')
            ->all();

        if (empty($taskIds)) {
            return collect();
        }

        $systemDate = ClientSetting::systemDateYMD();
        $currentDetails = DB::connection('sakemaru')->table('buyer_details')
            ->selectRaw('buyer_id, slip_type_id, ROW_NUMBER() OVER (PARTITION BY buyer_id ORDER BY start_date DESC) AS rn')
            ->where('start_date', '<=', $systemDate);

        $rows = DB::connection('sakemaru')->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->join('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->join('trades as et', 'e.trade_id', '=', 'et.id')
            ->leftJoin('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->joinSub($currentDetails, 'bd', function ($join) {
                $join->on('e.buyer_id', '=', 'bd.buyer_id');
            })
            ->join('slip_types as st', 'bd.slip_type_id', '=', 'st.id')
            ->whereIn('pir.picking_task_id', $taskIds)
            ->where('pir.ordered_qty', '>', 0)
            ->where('bd.rn', 1)
            ->where('st.category', 2)
            ->where('e.is_active', true)
            ->where('et.is_active', true)
            ->where(function ($query) {
                $query->whereNull('ti.id')
                    ->orWhere('ti.is_active', true);
            })
            ->select([
                'e.id as earning_id',
                'e.client_id',
                'e.warehouse_id',
                'st.id as slip_type_id',
                'st.name as slip_type_name',
                'st.printer_key',
            ])
            ->orderByRaw('COALESCE(l.floor_id, 999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->orderBy('e.id')
            ->get();

        return $rows
            ->groupBy(fn ($row) => ((int) $row->slip_type_id).'|'.((int) $row->warehouse_id))
            ->map(function (Collection $group) use ($warehouseId) {
                $first = $group->first();
                $printerKey = $this->normalizePrinterKey($first->printer_key);
                $clientId = (int) $first->client_id;
                $resolvedWarehouseId = (int) ($first->warehouse_id ?: $warehouseId);
                $slipTypeId = (int) $first->slip_type_id;
                $earningIds = $group->pluck('earning_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $printerInfo = $this->resolvePrinterInfo(
                    $clientId,
                    $resolvedWarehouseId,
                    $printerKey,
                );

                return [
                    'client_id' => $clientId,
                    'warehouse_id' => $resolvedWarehouseId,
                    'slip_type_id' => $slipTypeId,
                    'slip_type_name' => (string) $first->slip_type_name,
                    'printer_key' => $printerKey,
                    'confirmation_key' => $this->confirmationKey($clientId, $resolvedWarehouseId, $slipTypeId, $printerKey),
                    'earning_ids' => $earningIds,
                    'earning_count' => count($earningIds),
                    'has_active_printer' => $printerInfo['has_active_printer'],
                    'printer_names' => $printerInfo['printer_names'],
                    'printer_display_name' => $printerInfo['printer_display_name'],
                    'can_print_continuously' => $printerInfo['can_print_continuously'],
                    'requires_paper_confirmation' => $printerInfo['requires_paper_confirmation'],
                    'can_print' => $printerKey !== null && $printerInfo['has_active_printer'],
                    'disabled_reason' => $this->disabledReason($printerKey, $printerInfo['has_active_printer']),
                ];
            })
            ->values()
            ->map(function (array $group, int $index) {
                $group['print_order'] = $index + 1;

                return $group;
            });
    }

    private function resolvePrinterInfo(int $clientId, int $warehouseId, ?string $printerKey): array
    {
        if (! $printerKey) {
            return [
                'has_active_printer' => false,
                'printer_names' => [],
                'printer_display_name' => null,
                'can_print_continuously' => true,
                'requires_paper_confirmation' => false,
            ];
        }

        $baseQuery = ClientPrinterDriver::query()
            ->where('client_id', $clientId)
            ->where('printer_key', $printerKey)
            ->where('is_active', true);

        $warehousePrinters = (clone $baseQuery)
            ->where('warehouse_id', $warehouseId)
            ->get();

        $printers = $warehousePrinters->isNotEmpty()
            ? $warehousePrinters
            : $baseQuery->get();

        if ($printers->isEmpty()) {
            return [
                'has_active_printer' => false,
                'printer_names' => [],
                'printer_display_name' => null,
                'can_print_continuously' => true,
                'requires_paper_confirmation' => false,
            ];
        }

        $printerNames = $printers
            ->map(fn (ClientPrinterDriver $printer) => filled($printer->user_name) ? $printer->user_name : $printer->display_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $canPrintContinuously = ! $printers->contains(
            fn (ClientPrinterDriver $printer) => $printer->can_print_continuously === false
        );

        return [
            'has_active_printer' => true,
            'printer_names' => $printerNames,
            'printer_display_name' => $printerNames === [] ? null : implode(' / ', $printerNames),
            'can_print_continuously' => $canPrintContinuously,
            'requires_paper_confirmation' => ! $canPrintContinuously,
        ];
    }

    private function disabledReason(?string $printerKey, bool $hasActivePrinter): ?string
    {
        if (! $printerKey) {
            return '専用伝票のプリンター未設定';
        }

        if (! $hasActivePrinter) {
            return '対応する有効プリンターなし';
        }

        return null;
    }

    private function normalizePrinterKey(?string $printerKey): ?string
    {
        $printerKey = trim((string) $printerKey);

        return $printerKey === '' ? null : $printerKey;
    }

    private function confirmationKey(int $clientId, int $warehouseId, int $slipTypeId, ?string $printerKey): string
    {
        return implode('_', [
            $clientId,
            $warehouseId,
            $slipTypeId,
            substr(sha1((string) $printerKey), 0, 10),
        ]);
    }
}
