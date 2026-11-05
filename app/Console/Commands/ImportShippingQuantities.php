<?php

namespace App\Console\Commands;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\ShippingQuantityService;
use App\Services\AutoReplenishment\ShippingQuantitySet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Import a reviewed WMS-linked actual-shipment extract; never connect to another DB. */
class ImportShippingQuantities extends Command
{
    protected $signature = 'wms:import-shipping-quantities {client} {source} {file} {--actor=0} {--apply} {--bind-off-policies : Apply to matching OFF logistics settings without raising their point, target or maximum}';

    protected $description = '実出庫から作成した商品別出荷数量候補を取込（初期状態は確認のみ）';

    public function handle(): int
    {
        try {
            $client = (int) $this->argument('client');
            $source = (int) $this->argument('source');
            $file = $this->argument('file');
            throw_unless(is_file($file) && filesize($file) <= 10000000, \RuntimeException::class, '10MB以下の出荷実績JSONファイルを指定してください。');
            $input = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            throw_unless(($input['client_id'] ?? null) === $client && ($input['source_warehouse_id'] ?? null) === $source
                && is_array($input['profiles'] ?? null) && array_is_list($input['profiles']) && count($input['profiles']) <= 5000,
                \RuntimeException::class, '取込ファイルの荷主・移動元倉庫・商品一覧を確認してください。');
            $db = DB::connection('sakemaru');
            throw_unless($db->table('warehouses')->where(['id' => $source, 'client_id' => $client, 'is_active' => 1, 'is_virtual' => 0])->exists(), \RuntimeException::class, '移動元倉庫が無効です。');
            $set = app(ShippingQuantitySet::class);
            $service = app(ShippingQuantityService::class);
            $items = $db->table('items')->where('client_id', $client)->whereIn('id', array_column($input['profiles'], 'item_id'))
                ->get(['id', 'capacity_case', 'is_active', 'is_ended'])->keyBy('id');
            $profiles = [];
            $skipped = [];
            foreach ($input['profiles'] as $profile) {
                $id = $profile['item_id'] ?? null;
                throw_unless(is_int($id) && $id > 0 && ! isset($profiles[$id]), \RuntimeException::class, '商品IDの重複・不正値があります。');
                $item = $items->get($id);
                if (! $item || ! $item->is_active || $item->is_ended) {
                    $skipped[] = $id;

                    continue;
                }
                throw_unless((int) $item->capacity_case === ($profile['capacity_case'] ?? null), \RuntimeException::class, '商品ID '.$id.' のケース入数が実績と異なります。');
                $profile['quantities'] = $set->normalize($profile['quantities'] ?? null);
                throw_unless(($profile['basis']['method'] ?? '') === 'wms-linked-actual-shipments-v1', \RuntimeException::class, 'WMS発注に紐づく実出庫の根拠を確認してください。');
                $profiles[$id] = $profile;
            }
            $result = ['status' => $this->option('apply') ? 'COMPLETE' : 'DRY_RUN', 'profiles' => count($profiles), 'skipped_inactive_items' => $skipped,
                'bound_policies' => [], 'skipped_policies' => []];
            if ($this->option('apply')) {
                $actor = (int) $this->option('actor');
                throw_unless($actor > 0 && $db->table('users')->where(['id' => $actor, 'client_id' => $client, 'is_active' => 1])->exists(), \RuntimeException::class, '有効な実行ユーザーを --actor で指定してください。');
                $db->transaction(function () use ($client, $source, $profiles, $service, $actor, &$result) {
                    foreach ($profiles as $id => $profile) {
                        $before = $service->find($client, $source, $id);
                        $saved = $service->save($client, $source, $id, $profile['quantities'], (int) ($before?->version ?? 0), $actor, $profile['basis']);
                        if ($this->option('bind-off-policies')) {
                            $this->bindPolicies($client, $source, $saved, $profile, $actor, $result);
                        }
                    }
                }, 3);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(get_class($e) === \RuntimeException::class ? $e->getMessage() : '取込に失敗しました。ファイル形式・追加migrationの状態を管理者に確認してください。');
            if (get_class($e) !== \RuntimeException::class) {
                report($e);
            }

            return self::FAILURE;
        }
    }

    private function bindPolicies(int $client, int $source, object $saved, array $profile, int $actor, array &$result): void
    {
        $policies = WmsItemReorderPolicy::where(['client_id' => $client, 'channel' => 'LOGISTICS', 'source_warehouse_id' => $source, 'item_id' => $saved->item_id])->lockForUpdate()->get();
        foreach ($policies as $policy) {
            $basis = $policy->calculation_basis ?? [];
            if ($policy->is_enabled || ! is_numeric($basis['mean_daily'] ?? null) || $basis['mean_daily'] <= 0) {
                $result['skipped_policies'][] = ['id' => $policy->id, 'reason' => 'ONまたは出荷見込未設定'];

                continue;
            }
            $quantities = array_values(array_filter($profile['quantities'], fn ($q) => $q <= min($policy->max_order_pieces, $policy->target_stock_pieces ?? $policy->reorder_point_pieces)));
            if (! $quantities) {
                $result['skipped_policies'][] = ['id' => $policy->id, 'reason' => '既存の上限・補充目標内の候補なし'];

                continue;
            }
            $own = $profile['basis']['warehouse_counts'][(string) $policy->warehouse_id] ?? [];
            $counts = array_filter($own, fn ($count, $q) => in_array((int) $q, $quantities, true), ARRAY_FILTER_USE_BOTH);
            ksort($counts, SORT_NUMERIC);
            $normal = $counts ? (int) array_search(max($counts), $counts, true)
                : app(ShippingQuantitySet::class)->floor($quantities, (int) ($basis['batch_quantity_pieces'] ?? $policy->min_order_pieces));
            if ($normal < 1) {
                $result['skipped_policies'][] = ['id' => $policy->id, 'reason' => '店舗の通常量内の候補なし'];

                continue;
            }
            $batchRule = $counts ? 'observed-mode-with-historical-maximum' : 'preserved-store-normal-with-shipping-quantities';
            $binding = app(ShippingQuantityService::class)->binding((int) $policy->id);
            if ($binding && (int) $binding->profile_id === (int) $saved->id && (int) $binding->profile_version === (int) $saved->version
                && $policy->strategy_key === 'arrival_adaptive_v1' && (int) $policy->min_order_pieces === min($quantities)
                && (int) ($basis['batch_quantity_pieces'] ?? 0) === $normal
                && ($basis['shipping_profile_import']['format_version'] ?? 0) === 1 && ($basis['batch_rule'] ?? '') === $batchRule) {
                $result['skipped_policies'][] = ['id' => $policy->id, 'reason' => '同じ数量候補を適用済み'];

                continue;
            }
            $values = $policy->toArray();
            $values['strategy_key'] = 'arrival_adaptive_v1';
            $values['min_order_pieces'] = min($quantities);
            $values['shipping_unit_mode'] = 'SHIPMENT';
            $values['shipping_profile_version'] = $saved->version;
            $basis['scope'] = array_replace($basis['scope'] ?? [], ['client_id' => $client, 'warehouse_id' => (int) $policy->warehouse_id],
                array_intersect_key($values, array_flip(['item_id', 'channel', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id'])));
            $basis['batch_quantity_pieces'] = $normal;
            $basis['batch_frequency'] = $counts[$normal] ?? null;
            $batchCounts = [];
            foreach ($counts as $quantity => $frequency) {
                $batchCounts[] = ['pieces' => (int) $quantity, 'count' => (int) $frequency];
            }
            usort($batchCounts, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: ($a['pieces'] <=> $b['pieces']));
            $basis['batch_quantity_counts'] = $basis['observed_quantity_counts'] = array_slice($batchCounts, 0, 5);
            $basis['batch_rule'] = $batchRule;
            $basis['shipping_quantities'] = $profile['quantities'];
            $basis['shipping_profile_import'] = ['format_version' => 1, 'id' => $saved->id, 'version' => $saved->version, 'normal_source' => $counts ? 'この倉庫の実出庫（既存上限内）' : '店舗別実績がないため既存の通常1回量を維持', 'period' => $profile['basis']['period'] ?? null,
                'preserved' => ['reorder_point_pieces', 'target_stock_pieces', 'max_order_pieces', 'is_enabled']];
            app(PolicyService::class)->save($client, (int) $policy->warehouse_id, $values, $actor, $basis);
            $result['bound_policies'][] = $policy->id;
        }
    }
}
