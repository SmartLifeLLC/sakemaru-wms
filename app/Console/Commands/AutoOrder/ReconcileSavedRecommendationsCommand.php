<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\PalletUnitService;
use App\Services\AutoReplenishment\RecommendationChangeGuard;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use App\Services\AutoReplenishment\ShippingQuantityService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation of saved recommendations: every policy whose quantities were saved from the recommendation is recalculated through the
 * production path (SettingsRecommendationService with the saved calculation date) and classified — 一致 / 不一致 / 再現不能 / 対象外.
 * Reads only; never updates a policy or a recommendation. Policies can come from the connected database or from a JSON export (SELECT) of another one.
 */
class ReconcileSavedRecommendationsCommand extends Command
{
    protected $signature = 'wms:reconcile-saved-recommendations {client : 対象顧客ID}
        {--json= : 他環境（本番）から SELECT で取り出したポリシー JSON（配列。calculation_basis は JSON 文字列または配列）。未指定は接続先DBのポリシー}
        {--out= : 出力フォルダ（既定 storage/app/replenishment-reconcile/<日時>）}
        {--limit= : 件数上限（動作確認用）}';

    protected $description = '保存済みおすすめ値を保存時の算定日で再計算して全件突合する（一致／不一致／再現不能／対象外。DBへの書き込みなし）';

    public function handle(SettingsRecommendationService $recommendations): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $out = $this->option('out') ?: storage_path('app/replenishment-reconcile/'.now('Asia/Tokyo')->format('Ymd-His'));
            if (! is_dir($out)) {
                mkdir($out, 0775, true);
            }
            $db = DB::connection('sakemaru');
            $policies = $this->policies($client);
            if ($this->option('limit')) {
                $policies = array_slice($policies, 0, (int) $this->option('limit'));
            }
            $items = $db->table('items')->whereIn('id', array_values(array_unique(array_column($policies, 'item_id'))) ?: [-1])->pluck('code', 'id');
            $warehouses = $db->table('warehouses')->pluck('code', 'id');
            // The connected database's coverage: a saved calculation whose window ends after the latest local sales day cannot be reproduced here.
            $latestSales = $db->table('ret_pos_stock_applications')->selectRaw('warehouse_id, MAX(business_date) latest')->groupBy('warehouse_id')->pluck('latest', 'warehouse_id');
            $systemDate = $db->table('client_settings')->where('client_id', $client)->value('system_date');
            $environment = ['database' => $db->getDatabaseName(), 'system_date' => $systemDate, 'run_at' => now('Asia/Tokyo')->toIso8601String(),
                'source' => $this->option('json') ? 'json:'.$this->option('json') : 'db', 'policies' => count($policies),
                'enabled_policies' => count(array_filter($policies, fn ($p) => ! empty($p['is_enabled']))), 'git_head' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null'))];
            $this->info(count($policies).' ポリシー（'.$environment['source'].'、DB '.$environment['database'].'、業務日 '.$systemDate.'）を突合します。出力 '.$out);
            $file = fopen($out.'/reconcile.csv', 'w');
            fputcsv($file, ['policy_id', 'warehouse_id', 'warehouse_code', 'item_id', 'item_code', 'channel', 'calculated_at', 'as_of', 'period_start', 'period_end', 'conditions_source',
                'saved_point', 'saved_target', 'saved_min', 'saved_max', 'saved_multiple', 'saved_basis_type', 'saved_batch',
                'recalc_point', 'recalc_target', 'recalc_min', 'recalc_max', 'recalc_multiple', 'recalc_basis_type', 'recalc_batch', 'diff', 'verdict', 'reason']);
            $summary = ['一致' => 0, '不一致' => 0, '再現不能' => 0, '対象外' => 0];
            $db->enableQueryLog();
            $bar = $this->output->createProgressBar(count($policies));
            foreach ($policies as $policy) {
                $row = $this->reconcile($client, $policy, $recommendations, $latestSales);
                $summary[$row['verdict']]++;
                $saved = $row['saved'];
                $recalc = $row['recalc'];
                fputcsv($file, [$policy['id'], $policy['warehouse_id'], $warehouses[$policy['warehouse_id']] ?? '', $policy['item_id'], $items[$policy['item_id']] ?? '', $policy['channel'],
                    $row['calculated_at'], $row['as_of'], $row['period']['start'] ?? null, $row['period']['end'] ?? null, $row['conditions_source'],
                    $saved['reorder_point_pieces'] ?? null, $saved['target_stock_pieces'] ?? null, $saved['min_order_pieces'] ?? null, $saved['max_order_pieces'] ?? null, $saved['order_multiple_pieces'] ?? null, $saved['basis_type'] ?? null, $saved['batch'] ?? null,
                    $recalc['reorder_point_pieces'] ?? null, $recalc['target_stock_pieces'] ?? null, $recalc['min_order_pieces'] ?? null, $recalc['max_order_pieces'] ?? null, $recalc['order_multiple_pieces'] ?? null, $recalc['basis_type'] ?? null, $recalc['batch'] ?? null,
                    $row['diff'] ? json_encode($row['diff'], JSON_UNESCAPED_UNICODE) : '', $row['verdict'], $row['reason']]);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            fclose($file);
            foreach ($db->getQueryLog() as $query) {
                throw_unless(preg_match('/^\s*select/i', $query['query']), \RuntimeException::class, '読み取り以外のクエリが実行されました：'.$query['query']);
            }
            $environment['queries'] = count($db->getQueryLog());
            $environment['read_only_verified'] = true;
            file_put_contents($out.'/summary.json', json_encode(['summary' => $summary, 'environment' => $environment], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->table(['判定', '件数'], array_map(fn ($k, $v) => [$k, $v], array_keys($summary), $summary));
            $this->info('出力: '.$out.'（SELECT のみ '.$environment['queries'].' クエリ）');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /** @return array<int, array> */
    private function policies(int $client): array
    {
        if ($this->option('json')) {
            $rows = json_decode((string) file_get_contents($this->option('json')), true, 512, JSON_THROW_ON_ERROR);
            throw_unless(is_array($rows), \RuntimeException::class, 'JSON はポリシーの配列にしてください。');

            return array_map(function ($row) {
                $row = (array) $row;
                if (is_string($row['calculation_basis'] ?? null)) {
                    $row['calculation_basis'] = json_decode($row['calculation_basis'], true);
                }

                return $row;
            }, $rows);
        }

        return WmsItemReorderPolicy::where('client_id', $client)->orderBy('warehouse_id')->orderBy('item_id')->get()->map(fn ($p) => $p->toArray())->all();
    }

    /**
     * The shipping-unit mode of this warehouse × product in the connected database (the editor binds the constraint to the local policy row).
     *
     * @return array{0: string, 1: string} mode and a note on where it came from
     */
    private function bindingMode(int $client, array $policy): array
    {
        if (! empty($policy['shipping_unit_mode'])) {
            return [(string) $policy['shipping_unit_mode'], '入力行の shipping_unit_mode（'.$policy['shipping_unit_mode'].'）'];
        }
        $local = WmsItemReorderPolicy::where(['client_id' => $client, 'warehouse_id' => (int) $policy['warehouse_id'], 'item_id' => (int) $policy['item_id']])->first(['id']);
        if (! $local) {
            return ['STANDARD', 'なし（接続先に同じ倉庫×商品のポリシーが無いため STANDARD）'];
        }
        $mode = 'STANDARD';
        if (app(PalletUnitService::class)->binding((int) $local->id)) {
            $mode = 'PALLET';
        }
        if (($policy['channel'] ?? '') === 'LOGISTICS' && app(ShippingQuantityService::class)->binding((int) $local->id)) {
            $mode = 'SHIPMENT';
        }

        return [$mode, '接続先ポリシー #'.$local->id.'（同じ倉庫×商品）の現在値 '.$mode];
    }

    /** The events and factor sources a recommendation was adjusted with (identity only; the uplift in pieces follows the demand level). */
    private function eventSignature(array $basis): string
    {
        $events = $basis['demand_profile']['event_forecast']['events'] ?? $basis['event_adjustment']['events'] ?? [];
        // factors: training end, instances and a digest of the learned positions per profile (a changed factor is a different condition);
        // model_version: the learner version the factors belong to.
        $keys = array_flip(['type', 'name', 'start', 'end', 'pre_days', 'post_days', 'factor_source', 'factors']);
        $version = $basis['demand_profile']['event_forecast']['model_version'] ?? null;
        $rows = array_map(function ($e) use ($keys) {
            $row = array_intersect_key((array) $e, $keys);
            ksort($row); // stored JSON may carry another key order

            return $row;
        }, is_array($events) ? $events : []);
        usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

        return json_encode(['model_version' => $version, 'events' => $rows]);
    }

    private function reconcile(int $client, array $policy, SettingsRecommendationService $recommendations, $latestSales): array
    {
        $basis = is_array($policy['calculation_basis'] ?? null) ? $policy['calculation_basis'] : null;
        $guarded = array_flip(RecommendationChangeGuard::GUARDED);
        $saved = array_intersect_key($policy, $guarded) + ['basis_type' => $basis['basis_type'] ?? null, 'batch' => $basis['batch_quantity_pieces'] ?? null];
        $row = ['saved' => $saved, 'recalc' => [], 'diff' => [], 'calculated_at' => $basis['calculated_at'] ?? null, 'as_of' => null, 'period' => $basis['period'] ?? null, 'conditions_source' => ''];
        // 対象外: the quantities were not saved from the recommendation (legacy/manual setting, hand-adjusted fields, or an unavailable calculation).
        if ($basis === null || ! isset($basis['suggested_values'], $basis['safety_stock_days'], $basis['period']['end'])) {
            return $row + ['verdict' => '対象外', 'reason' => $basis === null ? 'おすすめ算定の記録なし（手動・旧設定の数量）' : 'おすすめ設定の保存値ではない（初期化・旧算定の記録：安全在庫日数・提案値の記録がない）'];
        }
        if (($basis['manually_adjusted_fields'] ?? []) !== []) {
            return $row + ['verdict' => '対象外', 'reason' => '手動調整済み：'.implode(',', (array) $basis['manually_adjusted_fields'])];
        }
        if (! empty($basis['reason'])) {
            return $row + ['verdict' => '対象外', 'reason' => '保存時の算定が不可：'.$basis['reason']];
        }
        $suggested = array_intersect_key($basis['suggested_values'], $guarded);
        if (array_intersect_key($policy, $guarded) != $suggested) {
            return $row + ['verdict' => '対象外', 'reason' => '保存値が提案値と異なる（保存後の手入力または他の変更）：提案 '.json_encode($suggested)];
        }
        $asOf = CarbonImmutable::parse($basis['period']['end'])->addDay()->toDateString();
        $row['as_of'] = $asOf;
        if (($basis['method'] ?? null) !== null && ! str_contains((string) $basis['method'], '-v8') && ! str_contains((string) $basis['method'], 'adaptive-v1')) {
            return $row + ['verdict' => '再現不能', 'reason' => '保存時の計算版が現行と異なる：'.$basis['method']];
        }
        $latest = $latestSales[$policy['warehouse_id']] ?? null;
        $coverageNote = $latest === null || (string) $latest < $basis['period']['end'] ? '接続先DBのPOS実績の最新日 '.($latest ?? 'なし').' が算定期間末 '.$basis['period']['end'].' より前で、保存時と同じ需要窓かを確認できない' : null;
        try {
            // The editor-equivalent route (same as the weekly refresh): saved route + the *current* quantity-constraint binding and demand events.
            $form = ['item_id' => (int) $policy['item_id'], 'channel' => $policy['channel'], 'contractor_id' => $policy['contractor_id'], 'supplier_id' => $policy['supplier_id'] ?? null,
                'source_warehouse_id' => $policy['source_warehouse_id'] ?? null, 'delivery_course_id' => $policy['delivery_course_id'] ?? null,
                'order_unit_mode' => $policy['order_unit_mode'] ?? 'AUTO', 'strategy_key' => $policy['strategy_key'] ?? 'arrival_adaptive_v1', 'shipping_unit_mode' => 'STANDARD'];
            // The quantity-constraint binding belongs to the connected database's policy of the SAME warehouse × product — never looked up by a
            // foreign policy id (a JSON export of another environment carries that environment's ids). An explicit shipping_unit_mode in the row wins.
            [$form['shipping_unit_mode'], $bindingNote] = $this->bindingMode($client, $policy);
            $unitMode = $basis['unit_mode'] ?? 'AUTO';
            $options = ['unit_mode' => $unitMode === 'PALLET' ? 'AUTO' : $unitMode, 'review_days' => $basis['review_days'] ?? 7, 'safety_stock_days' => $basis['safety_stock_days'],
                'existing_reorder_point' => ! empty($basis['reorder_point_preserved']) ? (int) $policy['reorder_point_pieces'] : null, 'as_of' => $asOf];
            $row['conditions_source'] = '保存条件（単位・発注日数・安全日数・算定日）＋紐付け '.$bindingNote.'・現在の承認イベント';
            $result = $recommendations->recommend($client, (int) $policy['warehouse_id'], $form, $options);
            if (! $result['available']) {
                return $row + ['verdict' => '再現不能', 'reason' => '再計算が不可：'.($result['basis']['reason'] ?? '算定不可')];
            }
            // Conditions that must be identical before any value comparison counts: demand window coverage, event conditions, the warehouse
            // schedule, the output unit, the quantity constraint. A different condition is 再現不能 even when the numbers happen to agree.
            $conditionGaps = array_filter([$coverageNote,
                $this->eventSignature($basis) !== $this->eventSignature($result['basis']) ? 'イベント条件（承認イベント・倍率の出所・倍率の学習版／学習終了日／内容）が保存時と異なる' : null,
                isset($basis['review_schedule_fingerprint'], $result['basis']['review_schedule_fingerprint']) && $basis['review_schedule_fingerprint'] !== $result['basis']['review_schedule_fingerprint'] ? '倉庫の実行曜日・時刻が保存時と異なる' : null,
                isset($basis['scope']['packing_unit']) && (int) $basis['scope']['packing_unit'] !== (int) ($result['basis']['scope']['packing_unit'] ?? 0) ? '発注単位（出力単位）が保存時と異なる' : null,
                (isset($basis['pallet_unit']) !== isset($result['basis']['pallet_unit'])) || (isset($basis['shipping_profile']) !== isset($result['basis']['shipping_profile'])) ? '数量制約（パレット／出荷数量候補）の紐付けが保存時と異なる' : null]);
            $values = array_intersect_key($result['values'], $guarded);
            $row['recalc'] = $values + ['basis_type' => $result['basis']['basis_type'] ?? null, 'batch' => $result['basis']['batch_quantity_pieces'] ?? null];
            $diff = [];
            foreach ($guarded as $key => $_) {
                if ((int) ($policy[$key] ?? 0) !== (int) ($values[$key] ?? 0)) {
                    $diff[$key] = [(int) ($policy[$key] ?? 0), (int) ($values[$key] ?? 0)];
                }
            }
            foreach (['basis_type', 'batch'] as $key) {
                if (($saved[$key] ?? null) != ($row['recalc'][$key] ?? null)) {
                    $diff[$key] = [$saved[$key] ?? null, $row['recalc'][$key] ?? null];
                }
            }
            $row['diff'] = $diff;
            if ($conditionGaps) {
                return $row + ['verdict' => '再現不能', 'reason' => implode('／', $conditionGaps).'ため同一入力を確認できない（数値は'.($diff === [] ? '同じ' : '異なる').'）'];
            }

            return $row + ['verdict' => $diff === [] ? '一致' : '不一致', 'reason' => $diff === [] ? '' : '同一条件で再計算した値が保存値と異なる'];
        } catch (\RuntimeException $e) {
            throw_if($e instanceof \Illuminate\Database\QueryException, $e);

            return $row + ['verdict' => '再現不能', 'reason' => $e->getMessage()];
        }
    }
}
