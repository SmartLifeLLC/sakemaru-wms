<?php

namespace App\Services\AutoReplenishment;

use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Support\Facades\DB;

/** Advisory only. Never changes a saved route, quantity or execution decision. */
class MasterRouteWarnings
{
    /** A read-only notice before editing a related master, including OFF policies. */
    public function masterEditNotice(int $clientId, string $kind, array $master): array
    {
        $scope = match ($kind) {
            'item' => ['warehouse_id' => $master['warehouse_id'] ?? 0, 'item_id' => $master['item_id'] ?? 0],
            'contractor' => ['contractor_id' => $master['contractor_id'] ?? 0],
            'delivery' => ['source_warehouse_id' => $master['from_warehouse_id'] ?? 0, 'warehouse_id' => $master['to_warehouse_id'] ?? 0, 'channel' => 'LOGISTICS'],
        };
        $description = match ($kind) {
            'item' => '商品・倉庫・発注先・仕入先を変更すると、保存済みの自動発注設定と差異が生じます。',
            'contractor' => '有効状態・供給倉庫・送受信設定・リードタイム・納品曜日などの変更は、自動発注の経路・日付・送信に影響します。',
            'delivery' => '配送コース・ピッキングリードタイムの変更は、自動発注の入荷日・出庫日に影響します。',
        };
        $affectedCount = $clientId > 0 && ! in_array(0, $scope, true)
            && $kind !== 'contractor'
            ? DB::connection('sakemaru')->table('wms_item_reorder_policies')->where('client_id', $clientId)->where($scope)->count()
            : 0;
        $directCount = $affectedCount;
        $aggregationSourceCount = 0;
        if ($kind === 'contractor' && $clientId > 0 && (int) $scope['contractor_id'] > 0) {
            $contractorId = (int) $scope['contractor_id'];
            // Use the same one-step mapping (including legacy fallbacks) as JX generation.
            $mapping = app(OrderTransmissionService::class)->getGenerator()?->getTransmissionContractorMapping() ?? [];
            $sourceIds = array_keys(array_filter($mapping, fn ($targetId) => (int) $targetId === $contractorId));
            $counts = DB::connection('sakemaru')->table('wms_item_reorder_policies')
                ->where('client_id', $clientId)
                ->where(function ($query) use ($contractorId, $sourceIds): void {
                    $query->where('contractor_id', $contractorId);
                    if ($sourceIds !== []) {
                        $query->orWhere(fn ($source) => $source->where('channel', 'EOS')->whereIn('contractor_id', $sourceIds));
                    }
                })
                ->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN contractor_id = ? THEN 1 ELSE 0 END), 0) AS direct', [$contractorId])
                ->first();
            $affectedCount = (int) $counts->total;
            $directCount = (int) $counts->direct;
            $aggregationSourceCount = $affectedCount - $directCount;
        }

        return compact('affectedCount', 'directCount', 'aggregationSourceCount', 'description');
    }

    public function context(int $clientId, int $warehouseId, array $itemIds): array
    {
        $db = DB::connection('sakemaru');

        return [
            'relations' => $db->table('item_contractors as ic')
                ->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
                ->leftJoin('wms_contractor_settings as cs', 'cs.contractor_id', '=', 'c.id')
                ->where(['ic.client_id' => $clientId, 'ic.warehouse_id' => $warehouseId, 'c.client_id' => $clientId])
                ->whereIn('ic.item_id', $itemIds)
                ->get(['ic.item_id', 'ic.contractor_id', 'ic.supplier_id', 'c.is_active', 'cs.transmission_type', 'cs.supply_warehouse_id'])
                ->map(fn ($row) => (array) $row)->all(),
            'courses' => $db->table('warehouse_stock_transfer_delivery_courses')->where('to_warehouse_id', $warehouseId)
                ->get(['from_warehouse_id', 'delivery_course_id'])->map(fn ($row) => (array) $row)->all(),
        ];
    }

    public function forRoute(int $clientId, int $warehouseId, array $route): array
    {
        if (empty($route['item_id']) || empty($route['contractor_id'])) {
            return [];
        }

        return $this->compare($route, $this->context($clientId, $warehouseId, [(int) $route['item_id']]));
    }

    public function compare(array $route, array $context): array
    {
        $warnings = [];
        $relations = array_values(array_filter($context['relations'], fn ($row) => (int) $row['item_id'] === (int) $route['item_id']
            && (int) $row['contractor_id'] === (int) $route['contractor_id']
            && (int) $row['supplier_id'] === (int) ($route['supplier_id'] ?? 0)));
        if (count($relations) !== 1) {
            $warnings[] = '発注先・仕入先が現在の商品発注管理と一致しないか、重複しています。保存済みの発注先を確認してください。';
        } else {
            $relation = $relations[0];
            $type = $route['channel'] === 'LOGISTICS' ? 'INTERNAL' : 'JX_FINET';
            if (! $relation['is_active'] || ($route['channel'] !== 'FAX' && $relation['transmission_type'] !== $type)) {
                $warnings[] = '発注先の有効状態・送信方式が商品別設定と異なります。発注先の設定を確認してください。';
            }
            if ($route['channel'] === 'LOGISTICS' && (int) $relation['supply_warehouse_id'] !== (int) ($route['source_warehouse_id'] ?? 0)) {
                $warnings[] = '移動元倉庫が現在の発注先設定と異なります。自動発注は保存済みの移動元を使うため、運用担当者に確認してください。';
            }
        }
        if ($route['channel'] === 'LOGISTICS') {
            $courses = array_values(array_filter($context['courses'], fn ($row) => (int) $row['from_warehouse_id'] === (int) ($route['source_warehouse_id'] ?? 0)));
            if (count($courses) !== 1 || (int) $courses[0]['delivery_course_id'] !== (int) ($route['delivery_course_id'] ?? 0)) {
                $warnings[] = '配送コースが現在の移動配送コース設定と異なります。入荷日・出庫日に影響するため、運用担当者に確認してください。';
            }
        }

        return $warnings;
    }
}
