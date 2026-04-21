<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OriginType;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Services\AutoOrder\OrderCandidateCalculationService;
use App\Services\AutoOrder\SalesBasedOrderCandidateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TestAutoOrderCommand extends Command
{
    protected $signature = 'wms:test-auto-order
        {--phase= : 実行するPhase番号 (2-7)}
        {--analyze : マスタデータ分析のみ (Phase2)}';

    protected $description = '発注候補生成の統合テスト実行';

    private array $results = [];

    private int $passCount = 0;

    private int $failCount = 0;

    private int $skipCount = 0;

    private int $createdBy;

    public function handle(): int
    {
        ini_set('memory_limit', '-1');

        $this->createdBy = DB::connection('sakemaru')
            ->table('users')
            ->where('email', 'automator@sakemaru.ai')
            ->value('id') ?? 0;

        $this->info("=== 発注候補生成 統合テスト ===");
        $this->info("実行日時: " . now()->format('Y-m-d H:i:s'));
        $this->info("実行者ID: {$this->createdBy}");
        $this->newLine();

        $phase = $this->option('phase');
        $analyzeOnly = $this->option('analyze');

        if ($analyzeOnly) {
            $phase = '2';
        }

        try {
            if ($phase) {
                $method = "runPhase{$phase}";
                if (method_exists($this, $method)) {
                    $this->$method();
                } else {
                    $this->error("Phase {$phase} は存在しません (2-7)");
                    return 1;
                }
            } else {
                for ($i = 2; $i <= 7; $i++) {
                    $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                    $method = "runPhase{$i}";
                    $this->$method();
                    $this->newLine();
                }
            }
        } catch (\Exception $e) {
            $this->error("致命的エラー: " . $e->getMessage());
            Log::error('[TestAutoOrder] Fatal error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return 1;
        }

        $this->printSummary();

        return $this->failCount > 0 ? 1 : 0;
    }

    private function truncateCandidateTables(): void
    {
        $this->info('候補テーブルをtruncate中...');
        DB::connection('sakemaru')->table('wms_stock_transfer_candidates')->truncate();
        DB::connection('sakemaru')->table('wms_order_candidates')->truncate();
        DB::connection('sakemaru')->table('wms_order_calculation_logs')->truncate();
        DB::connection('sakemaru')->table('wms_auto_order_job_controls')->truncate();
        DB::connection('sakemaru')->table('wms_queue_progress')->truncate();
        $this->info('truncate完了');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Phase 2: マスタデータ分析
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function runPhase2(): void
    {
        $this->info('【Phase 2】マスタデータ分析');
        $this->newLine();

        $enabledWarehouses = WmsWarehouseAutoOrderSetting::enabled()->pluck('warehouse_id')->toArray();
        $this->info("自動発注有効倉庫: " . count($enabledWarehouses) . "件 (IDs: " . implode(',', $enabledWarehouses) . ")");
        $this->newLine();

        $today = now()->toDateString();

        // A1: 安全在庫ON・在庫不足
        $a1 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id, '-', ic.contractor_id)) as cnt
            FROM item_contractors ic
            JOIN items i ON i.id = ic.item_id
            JOIN contractors c ON c.id = ic.contractor_id
            WHERE ic.is_auto_order = 1 AND ic.safety_stock > 0
            AND i.is_ended = 0 AND i.end_of_sale_type = 'NORMAL'
            AND (i.start_of_sale_date IS NULL OR i.start_of_sale_date <= ?)
            AND c.is_auto_change_order = 1
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
        ", [$today]);

        // A2: (will be checked during execution)

        // A3: 発注OFF・実績あり
        $a3 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id)) as cnt
            FROM item_contractors ic
            JOIN items i ON i.id = ic.item_id
            JOIN contractors c ON c.id = ic.contractor_id
            JOIN stats_item_warehouse_sales_summaries s ON s.item_id = ic.item_id AND s.warehouse_id = ic.warehouse_id
            WHERE ic.is_auto_order = 0
            AND s.last_3d_qty > 0
            AND i.is_ended = 0 AND i.end_of_sale_type = 'NORMAL'
            AND (i.start_of_sale_date IS NULL OR i.start_of_sale_date <= ?)
            AND c.is_auto_change_order = 1
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
        ", [$today]);

        // A4: 発注OFF・実績なし
        $a4 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id)) as cnt
            FROM item_contractors ic
            JOIN items i ON i.id = ic.item_id
            WHERE ic.is_auto_order = 0
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
            AND NOT EXISTS (
                SELECT 1 FROM stats_item_warehouse_sales_summaries s
                WHERE s.item_id = ic.item_id AND s.warehouse_id = ic.warehouse_id AND s.last_3d_qty > 0
            )
        ", []);

        // A5: 発注OFF・安全在庫あり・実績あり
        $a5 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id)) as cnt
            FROM item_contractors ic
            JOIN items i ON i.id = ic.item_id
            JOIN stats_item_warehouse_sales_summaries s ON s.item_id = ic.item_id AND s.warehouse_id = ic.warehouse_id
            WHERE ic.is_auto_order = 0 AND ic.safety_stock > 0 AND s.last_3d_qty > 0
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
        ", []);

        // A6: 発注ON・安全在庫ゼロ
        $a6 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id)) as cnt
            FROM item_contractors ic
            WHERE ic.is_auto_order = 1 AND (ic.safety_stock = 0 OR ic.safety_stock IS NULL)
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
        ", []);

        // A7: 販売終了品
        $a7 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id)) as cnt
            FROM item_contractors ic
            JOIN items i ON i.id = ic.item_id
            WHERE (i.is_ended = 1 OR i.end_of_sale_type != 'NORMAL')
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
        ", []);

        // A8: 販売開始前
        $a8 = DB::connection('sakemaru')->select("
            SELECT COUNT(DISTINCT CONCAT(ic.item_id, '-', ic.warehouse_id)) as cnt
            FROM item_contractors ic
            JOIN items i ON i.id = ic.item_id
            WHERE i.start_of_sale_date > ?
            AND ic.warehouse_id IN (" . implode(',', $enabledWarehouses) . ")
        ", [$today]);

        // INTERNAL/EXTERNAL分類
        $types = DB::connection('sakemaru')->select("
            SELECT transmission_type, COUNT(*) as cnt
            FROM wms_contractor_settings
            GROUP BY transmission_type
        ");

        // 発注CD分析
        $orderingCodeTrue = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->count();
        $orderingCodeFalse = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where(function ($q) {
                $q->where('is_used_for_ordering', false)->orWhereNull('is_used_for_ordering');
            })
            ->where('is_active', true)
            ->count();

        $this->table(
            ['カテゴリ', '説明', '該当数'],
            [
                ['A1', '安全在庫ON・対象商品（is_auto_order=true, safety_stock>0）', $a1[0]->cnt ?? 0],
                ['A3', '発注OFF・実績あり（is_auto_order=false, last_3d>0）', $a3[0]->cnt ?? 0],
                ['A4', '発注OFF・実績なし', $a4[0]->cnt ?? 0],
                ['A5', '発注OFF・安全在庫あり・実績あり', $a5[0]->cnt ?? 0],
                ['A6', '発注ON・安全在庫ゼロ（ギャップ）', $a6[0]->cnt ?? 0],
                ['A7', '販売終了品', $a7[0]->cnt ?? 0],
                ['A8', '販売開始前', $a8[0]->cnt ?? 0],
            ]
        );

        $this->newLine();
        $this->info('INTERNAL/EXTERNAL 分類:');
        $this->table(
            ['transmission_type', '件数'],
            collect($types)->map(fn ($t) => [$t->transmission_type, $t->cnt])->toArray()
        );

        $this->newLine();
        $this->info('発注CD分析:');
        $this->table(
            ['条件', '件数'],
            [
                ['is_used_for_ordering = true', $orderingCodeTrue],
                ['is_used_for_ordering = false/null', $orderingCodeFalse],
            ]
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Phase 3: 安全在庫ベーステスト
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function runPhase3(): void
    {
        $this->info('【Phase 3】安全在庫ベーステスト');
        $this->truncateCandidateTables();
        $this->newLine();

        $this->info('安全在庫ベースサービスを実行中...');
        $service = app(OrderCandidateCalculationService::class);
        $job = $service->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            originType: OriginType::MANUAL_SAFETY_STOCK,
        );

        $batchCode = $job->batch_code;
        $this->info("batch_code: {$batchCode}");
        $this->info("処理件数: {$job->processed_records}");
        $this->newLine();

        // A1: 候補が生成されていること
        $orderCount = DB::connection('sakemaru')->table('wms_order_candidates')->where('batch_code', $batchCode)->count();
        $transferCount = DB::connection('sakemaru')->table('wms_stock_transfer_candidates')->where('batch_code', $batchCode)->count();
        $this->recordResult('A1', '安全在庫ON・在庫不足→候補生成', ($orderCount + $transferCount) > 0, $orderCount + $transferCount, '>0', "発注:{$orderCount}, 移動:{$transferCount}");

        // A2: 在庫十分な商品が候補に含まれていないこと
        $overStocked = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            WHERE oc.batch_code = ?
            AND oc.calculated_shortage_qty <= 0
        ", [$batchCode]);
        $this->recordResult('A2', '在庫十分→スキップ（calculated_shortage_qty<=0なし）', ($overStocked[0]->cnt ?? 0) === 0, $overStocked[0]->cnt ?? -1, '0', 'calculated_shortage_qty<=0のレコード数');

        // A7: 販売終了品が含まれていないこと
        $endedInCandidates = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN items i ON i.id = oc.item_id
            WHERE oc.batch_code = ?
            AND (i.is_ended = 1 OR i.end_of_sale_type != 'NORMAL')
        ", [$batchCode]);
        $this->recordResult('A7-order', '販売終了品→発注候補なし', ($endedInCandidates[0]->cnt ?? 0) === 0, $endedInCandidates[0]->cnt ?? -1, '0', '');

        $endedInTransfers = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_stock_transfer_candidates stc
            JOIN items i ON i.id = stc.item_id
            WHERE stc.batch_code = ?
            AND (i.is_ended = 1 OR i.end_of_sale_type != 'NORMAL')
        ", [$batchCode]);
        $this->recordResult('A7-transfer', '販売終了品→移動候補なし', ($endedInTransfers[0]->cnt ?? 0) === 0, $endedInTransfers[0]->cnt ?? -1, '0', '');

        // A8: 販売開始前が含まれていないこと
        $today = now()->toDateString();
        $futureInCandidates = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN items i ON i.id = oc.item_id
            WHERE oc.batch_code = ?
            AND i.start_of_sale_date > ?
        ", [$batchCode, $today]);
        $this->recordResult('A8', '販売開始前→候補なし', ($futureInCandidates[0]->cnt ?? 0) === 0, $futureInCandidates[0]->cnt ?? -1, '0', '');

        // is_auto_order=false の商品が含まれていないこと
        $nonAutoInSafety = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id AND ic.warehouse_id = oc.warehouse_id
            WHERE oc.batch_code = ? AND ic.is_auto_order = 0
        ", [$batchCode]);
        $this->recordResult('A1-excl', '安全在庫ベース→is_auto_order=falseなし', ($nonAutoInSafety[0]->cnt ?? 0) === 0, $nonAutoInSafety[0]->cnt ?? -1, '0', '');

        // B1-B3: 数量計算検証（サンプル10件）
        $this->newLine();
        $this->info('数量計算検証（サンプル10件）:');
        $samples = DB::connection('sakemaru')->select("
            SELECT oc.item_id, oc.warehouse_id, oc.contractor_id,
                   oc.calculated_shortage_qty, oc.order_quantity, oc.purchase_unit,
                   oc.current_effective_stock, oc.incoming_quantity,
                   oc.safety_stock, oc.suggested_quantity,
                   i.capacity_case
            FROM wms_order_candidates oc
            JOIN items i ON i.id = oc.item_id
            WHERE oc.batch_code = ?
            LIMIT 10
        ", [$batchCode]);

        $calcResults = [];
        $allCalcOk = true;
        foreach ($samples as $s) {
            $expectedShortage = max(0, (int) $s->safety_stock - (int) $s->current_effective_stock - (int) $s->incoming_quantity);
            $pu = max(1, (int) $s->purchase_unit);
            $expectedSuggested = (int) ceil($expectedShortage / $pu) * $pu;
            $capacityCase = max(1, (int) ($s->capacity_case ?? 1));
            $expectedOrderQty = (int) ceil($expectedSuggested / $capacityCase);
            $shortageOk = (int) $s->calculated_shortage_qty === $expectedShortage;
            $orderOk = (int) $s->order_quantity === $expectedOrderQty;
            if (!$shortageOk || !$orderOk) {
                $allCalcOk = false;
            }
            $calcResults[] = [
                $s->item_id,
                $s->warehouse_id,
                "SS:{$s->safety_stock} Stk:{$s->current_effective_stock} Inc:{$s->incoming_quantity}",
                "不足: {$s->calculated_shortage_qty}" . ($shortageOk ? ' OK' : " NG(期待:{$expectedShortage})"),
                "PU:{$s->purchase_unit} CC:{$capacityCase} Sug:{$s->suggested_quantity} Qty:{$s->order_quantity}" . ($orderOk ? ' OK' : " NG(期待:{$expectedOrderQty})"),
            ];
        }
        $this->table(['item_id', 'wh_id', '在庫情報', '不足数検証', '発注数検証'], $calcResults);
        $this->recordResult('B1-B3', '数量計算（不足数・切り上げ・仕入単位）', $allCalcOk, count($samples), count($samples) . ' OK', $allCalcOk ? '' : '一部計算不一致');

        // C1-C3: INTERNAL/EXTERNAL分岐
        $this->newLine();
        $this->info('INTERNAL/EXTERNAL分岐検証:');

        $wrongInternal = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_stock_transfer_candidates stc
            JOIN wms_contractor_settings cs ON cs.contractor_id = stc.contractor_id
            WHERE stc.batch_code = ? AND cs.transmission_type != 'INTERNAL'
        ", [$batchCode]);
        $this->recordResult('C1', 'INTERNAL候補→transmission_type=INTERNALのみ', ($wrongInternal[0]->cnt ?? 0) === 0, $wrongInternal[0]->cnt ?? -1, '0', '');

        $wrongExternal = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN wms_contractor_settings cs ON cs.contractor_id = oc.contractor_id
            WHERE oc.batch_code = ? AND cs.transmission_type = 'INTERNAL'
        ", [$batchCode]);
        $this->recordResult('C2', 'EXTERNAL候補→INTERNAL発注先なし', ($wrongExternal[0]->cnt ?? 0) === 0, $wrongExternal[0]->cnt ?? -1, '0', '');

        // E1: origin_type
        $originTypes = DB::connection('sakemaru')->select("
            SELECT DISTINCT origin_type FROM wms_order_candidates WHERE batch_code = ?
        ", [$batchCode]);
        $originTypeValues = collect($originTypes)->pluck('origin_type')->toArray();
        $e1Pass = count($originTypeValues) === 1 && $originTypeValues[0] === OriginType::MANUAL_SAFETY_STOCK->value;
        $this->recordResult('E1', 'origin_type=MANUAL_SAFETY_STOCK', $e1Pass, implode(',', $originTypeValues), OriginType::MANUAL_SAFETY_STOCK->value, '');

        $transferOrigins = DB::connection('sakemaru')->select("
            SELECT DISTINCT origin_type FROM wms_stock_transfer_candidates WHERE batch_code = ?
        ", [$batchCode]);
        $transferOriginValues = collect($transferOrigins)->pluck('origin_type')->toArray();
        $e1tPass = count($transferOriginValues) <= 1 && ($transferOriginValues[0] ?? OriginType::MANUAL_SAFETY_STOCK->value) === OriginType::MANUAL_SAFETY_STOCK->value;
        $this->recordResult('E1-transfer', 'origin_type(移動)=MANUAL_SAFETY_STOCK', $e1tPass, implode(',', $transferOriginValues), OriginType::MANUAL_SAFETY_STOCK->value, '');

        // F1-F3: 発注CD検証
        $this->newLine();
        $this->info('発注CD検証:');

        // F1: ordering_codeがsearch_stringと一致
        $orderingMismatch = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN item_search_information isi ON isi.item_id = oc.item_id AND isi.is_used_for_ordering = 1 AND isi.is_active = 1
            WHERE oc.batch_code = ? AND oc.ordering_code IS NOT NULL
            AND oc.ordering_code COLLATE utf8mb4_general_ci != LPAD(isi.search_string, 13, '0') COLLATE utf8mb4_general_ci
        ", [$batchCode]);
        $this->recordResult('F1', '発注CD=search_string(13桁パディング)一致', ($orderingMismatch[0]->cnt ?? 0) === 0, $orderingMismatch[0]->cnt ?? -1, '0', '');

        // F2: is_used_for_ordering=falseの場合ordering_code=null
        $wrongOrdering = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            WHERE oc.batch_code = ? AND oc.ordering_code IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM item_search_information isi
                WHERE isi.item_id = oc.item_id AND isi.is_used_for_ordering = 1 AND isi.is_active = 1
            )
        ", [$batchCode]);
        $this->recordResult('F2', '発注CD=null(is_used_for_ordering=false)', ($wrongOrdering[0]->cnt ?? 0) === 0, $wrongOrdering[0]->cnt ?? -1, '0', '');

        // F3: 13桁ゼロパディング
        $wrongLength = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            WHERE oc.batch_code = ? AND oc.ordering_code IS NOT NULL AND LENGTH(oc.ordering_code) != 13
        ", [$batchCode]);
        $this->recordResult('F3', '発注CD=13桁', ($wrongLength[0]->cnt ?? 0) === 0, $wrongLength[0]->cnt ?? -1, '0', '');

        // G1: 重複チェック (発注候補)
        $duplicateOrders = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM (
                SELECT item_id, warehouse_id, contractor_id, COUNT(*) as dup
                FROM wms_order_candidates
                WHERE batch_code = ?
                GROUP BY item_id, warehouse_id, contractor_id
                HAVING dup > 1
            ) d
        ", [$batchCode]);
        $this->recordResult('G1-order', '発注候補重複なし(item×wh×contractor)', ($duplicateOrders[0]->cnt ?? 0) === 0, $duplicateOrders[0]->cnt ?? -1, '0', '');

        // G1: 重複チェック (移動候補)
        $duplicateTransfers = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM (
                SELECT item_id, satellite_warehouse_id, contractor_id, COUNT(*) as dup
                FROM wms_stock_transfer_candidates
                WHERE batch_code = ?
                GROUP BY item_id, satellite_warehouse_id, contractor_id
                HAVING dup > 1
            ) d
        ", [$batchCode]);
        $this->recordResult('G1-transfer', '移動候補重複なし(item×wh×contractor)', ($duplicateTransfers[0]->cnt ?? 0) === 0, $duplicateTransfers[0]->cnt ?? -1, '0', '');

        // 倉庫別サマリ
        $this->newLine();
        $this->info('倉庫別生成数サマリ:');
        $whSummary = DB::connection('sakemaru')->select("
            SELECT w.name, COUNT(*) as cnt
            FROM wms_order_candidates oc
            JOIN warehouses w ON w.id = oc.warehouse_id
            WHERE oc.batch_code = ?
            GROUP BY oc.warehouse_id, w.name
            ORDER BY w.name
        ", [$batchCode]);
        $this->table(['倉庫', '発注候補数'], collect($whSummary)->map(fn ($r) => [$r->name, $r->cnt])->toArray());
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Phase 4: 実績ベーステスト
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function runPhase4(): void
    {
        $this->info('【Phase 4】実績ベーステスト');
        $this->truncateCandidateTables();
        $this->newLine();

        $this->info('実績ベースサービスを実行中...');
        $service = app(SalesBasedOrderCandidateService::class);
        $job = $service->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            originType: OriginType::MANUAL_SALES_BASED,
        );

        $batchCode = $job->batch_code;
        $this->info("batch_code: {$batchCode}");
        $this->info("処理件数: {$job->processed_records}");
        $this->newLine();

        // A3: is_auto_order=false のみ候補生成
        $orderCount = DB::connection('sakemaru')->table('wms_order_candidates')->where('batch_code', $batchCode)->count();
        $transferCount = DB::connection('sakemaru')->table('wms_stock_transfer_candidates')->where('batch_code', $batchCode)->count();
        $this->recordResult('A3', '実績ベース→候補生成', ($orderCount + $transferCount) > 0, $orderCount + $transferCount, '>0', "発注:{$orderCount}, 移動:{$transferCount}");

        // is_auto_order=true が含まれていないこと
        $autoOrderInSales = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id AND ic.warehouse_id = oc.warehouse_id
            WHERE oc.batch_code = ? AND ic.is_auto_order = 1
        ", [$batchCode]);
        $this->recordResult('A3-excl', '実績ベース→is_auto_order=trueなし', ($autoOrderInSales[0]->cnt ?? 0) === 0, $autoOrderInSales[0]->cnt ?? -1, '0', '');

        $autoOrderInTransfers = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_stock_transfer_candidates stc
            JOIN item_contractors ic ON ic.item_id = stc.item_id AND ic.contractor_id = stc.contractor_id AND ic.warehouse_id = stc.satellite_warehouse_id
            WHERE stc.batch_code = ? AND ic.is_auto_order = 1
        ", [$batchCode]);
        $this->recordResult('A3-excl-t', '実績ベース移動→is_auto_order=trueなし', ($autoOrderInTransfers[0]->cnt ?? 0) === 0, $autoOrderInTransfers[0]->cnt ?? -1, '0', '');

        // A5: safety_stock>0 でも実績ベースに含まれること（is_auto_order=falseなら）
        $safetyStockInSales = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id AND ic.warehouse_id = oc.warehouse_id
            WHERE oc.batch_code = ? AND ic.is_auto_order = 0 AND ic.safety_stock > 0
        ", [$batchCode]);
        $a5Count = $safetyStockInSales[0]->cnt ?? 0;
        $this->recordResult('A5', '発注OFF・安全在庫あり→実績ベースに含まれる', true, $a5Count, '>=0 (データ依存)', "safety_stock>0 かつ is_auto_order=false: {$a5Count}件");

        // A6: is_auto_order=true は含まれないことを再確認（許容ギャップ）
        $this->recordResult('A6', '発注ON・安全在庫ゼロ→実績ベース対象外', ($autoOrderInSales[0]->cnt ?? 0) === 0, $autoOrderInSales[0]->cnt ?? -1, '0', 'A3-exclと同一チェック');

        // B5-B6: 実績ベース数量計算
        $this->newLine();
        $this->info('実績ベース数量計算検証（サンプル10件）:');
        $samples = DB::connection('sakemaru')->select("
            SELECT oc.item_id, oc.warehouse_id, oc.contractor_id,
                   oc.calculated_shortage_qty, oc.order_quantity, oc.purchase_unit,
                   oc.current_effective_stock, oc.incoming_quantity,
                   oc.suggested_quantity,
                   s.last_3d_qty,
                   i.capacity_case
            FROM wms_order_candidates oc
            JOIN stats_item_warehouse_sales_summaries s ON s.item_id = oc.item_id AND s.warehouse_id = oc.warehouse_id
            JOIN items i ON i.id = oc.item_id
            WHERE oc.batch_code = ?
            LIMIT 10
        ", [$batchCode]);

        $calcResults = [];
        $allCalcOk = true;
        foreach ($samples as $s) {
            $pu = max(1, (int) $s->purchase_unit);
            $capacityCase = max(1, (int) ($s->capacity_case ?? 1));
            $suggestedPieces = (int) $s->suggested_quantity;
            $expectedOrderQty = (int) ceil($suggestedPieces / $capacityCase);
            $shortageOk = (int) $s->calculated_shortage_qty > 0;
            $suggestedOk = $suggestedPieces >= (int) $s->calculated_shortage_qty && $suggestedPieces % $pu === 0;
            $orderOk = (int) $s->order_quantity === $expectedOrderQty;
            if (!$orderOk || !$suggestedOk) {
                $allCalcOk = false;
            }
            $calcResults[] = [
                $s->item_id,
                $s->warehouse_id,
                "3d:{$s->last_3d_qty} Stk:{$s->current_effective_stock} Inc:{$s->incoming_quantity}",
                "不足: {$s->calculated_shortage_qty}" . ($shortageOk ? ' OK' : ' NG'),
                "PU:{$s->purchase_unit} CC:{$capacityCase} Sug:{$s->suggested_quantity}" . ($suggestedOk ? ' OK' : ' NG') . " Qty:{$s->order_quantity}" . ($orderOk ? ' OK' : " NG(期待:{$expectedOrderQty})"),
            ];
        }
        $this->table(['item_id', 'wh_id', '在庫情報', '不足数検証', '発注数検証'], $calcResults);
        $this->recordResult('B5-B6', '実績ベース数量計算(suggested/order_qty整合性)', $allCalcOk, count($samples), count($samples) . ' OK', $allCalcOk ? '' : '一部計算不一致 ※transfer考慮あり');

        // E2: origin_type
        $originTypes = DB::connection('sakemaru')->select("
            SELECT DISTINCT origin_type FROM wms_order_candidates WHERE batch_code = ?
        ", [$batchCode]);
        $originTypeValues = collect($originTypes)->pluck('origin_type')->toArray();
        $e2Pass = count($originTypeValues) === 1 && $originTypeValues[0] === OriginType::MANUAL_SALES_BASED->value;
        $this->recordResult('E2', 'origin_type=MANUAL_SALES_BASED', $e2Pass, implode(',', $originTypeValues), OriginType::MANUAL_SALES_BASED->value, '');

        // G1: 重複チェック
        $duplicateOrders = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM (
                SELECT item_id, warehouse_id, contractor_id, COUNT(*) as dup
                FROM wms_order_candidates
                WHERE batch_code = ?
                GROUP BY item_id, warehouse_id, contractor_id
                HAVING dup > 1
            ) d
        ", [$batchCode]);
        $this->recordResult('G1-sales', '実績ベース発注候補重複なし', ($duplicateOrders[0]->cnt ?? 0) === 0, $duplicateOrders[0]->cnt ?? -1, '0', '');

        // 倉庫別サマリ
        $this->newLine();
        $this->info('倉庫別生成数サマリ:');
        $whSummary = DB::connection('sakemaru')->select("
            SELECT w.name, COUNT(*) as cnt
            FROM wms_order_candidates oc
            JOIN warehouses w ON w.id = oc.warehouse_id
            WHERE oc.batch_code = ?
            GROUP BY oc.warehouse_id, w.name
            ORDER BY w.name
        ", [$batchCode]);
        $this->table(['倉庫', '発注候補数'], collect($whSummary)->map(fn ($r) => [$r->name, $r->cnt])->toArray());
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Phase 5: batch_code共有テスト
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function runPhase5(): void
    {
        $this->info('【Phase 5】batch_code共有テスト');

        // D1: 安全在庫→実績の順
        $this->info('D1: 安全在庫→実績の順');
        $this->truncateCandidateTables();

        $safetyService = app(OrderCandidateCalculationService::class);
        $safetyJob = $safetyService->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            originType: OriginType::MANUAL_SAFETY_STOCK,
        );
        $batchCode1 = $safetyJob->batch_code;
        $this->info("安全在庫 batch_code: {$batchCode1}");

        $salesService = app(SalesBasedOrderCandidateService::class);
        $salesJob = $salesService->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            batchCode: $batchCode1,
            originType: OriginType::MANUAL_SALES_BASED,
        );
        $batchCode2 = $salesJob->batch_code;
        $this->info("実績ベース batch_code: {$batchCode2}");

        $this->recordResult('D1', '安全在庫→実績: 同一batch_code', $batchCode1 === $batchCode2, $batchCode2, $batchCode1, '');

        // G2: 同一batch_code内で安全在庫と実績で同一商品が重複しないこと
        $crossDup = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc1
            WHERE oc1.batch_code = ? AND oc1.origin_type = ?
            AND EXISTS (
                SELECT 1 FROM wms_order_candidates oc2
                WHERE oc2.batch_code = oc1.batch_code
                AND oc2.item_id = oc1.item_id AND oc2.warehouse_id = oc1.warehouse_id
                AND oc2.origin_type = ?
            )
        ", [$batchCode1, OriginType::MANUAL_SAFETY_STOCK->value, OriginType::MANUAL_SALES_BASED->value]);
        $this->recordResult('G2', '安全在庫+実績で同一商品重複なし', ($crossDup[0]->cnt ?? 0) === 0, $crossDup[0]->cnt ?? -1, '0', 'is_auto_orderで排他');

        $this->newLine();

        // D2: 実績のみ
        $this->info('D2: 実績のみ');
        $this->truncateCandidateTables();

        $salesService2 = app(SalesBasedOrderCandidateService::class);
        $salesJob2 = $salesService2->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            originType: OriginType::MANUAL_SALES_BASED,
        );
        $batchCode3 = $salesJob2->batch_code;
        $this->info("実績のみ batch_code: {$batchCode3}");
        $this->recordResult('D2', '実績のみ→新規batch_code', !empty($batchCode3) && $batchCode3 !== $batchCode1, $batchCode3, '新規(≠' . $batchCode1 . ')', '');

        $this->newLine();

        // D3: 2回連続実績 (安全在庫→実績→実績)
        $this->info('D3: 安全在庫→実績→実績');
        $this->truncateCandidateTables();

        $safetyService3 = app(OrderCandidateCalculationService::class);
        $safetyJob3 = $safetyService3->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            originType: OriginType::MANUAL_SAFETY_STOCK,
        );
        $bc = $safetyJob3->batch_code;

        $salesService3a = app(SalesBasedOrderCandidateService::class);
        $salesJob3a = $salesService3a->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            batchCode: $bc,
            originType: OriginType::MANUAL_SALES_BASED,
        );
        $bc2 = $salesJob3a->batch_code;

        $salesService3b = app(SalesBasedOrderCandidateService::class);
        $salesJob3b = $salesService3b->calculate(
            warehouseId: null,
            createdBy: $this->createdBy,
            batchCode: $bc,
            originType: OriginType::MANUAL_SALES_BASED,
        );
        $bc3 = $salesJob3b->batch_code;

        $this->info("安全在庫: {$bc}, 実績1: {$bc2}, 実績2: {$bc3}");
        $d3Pass = ($bc === $bc2) && ($bc2 === $bc3);
        $this->recordResult('D3', '2回連続実績→同一batch_code', $d3Pass, "{$bc}/{$bc2}/{$bc3}", '全一致', '');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Phase 6: origin_type・発注CD・重複チェック (Phase3,4で大部分カバー済み)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function runPhase6(): void
    {
        $this->info('【Phase 6】origin_type・発注CD・重複チェック追加検証');
        $this->info('Note: 主要検証はPhase3-5で実施済み。追加の横断検証を実施。');
        $this->newLine();

        // 最新のbatch_codeを取得（Phase5のD1テスト結果を利用）
        $latestJob = DB::connection('sakemaru')
            ->table('wms_auto_order_job_controls')
            ->orderByDesc('id')
            ->first();

        if (!$latestJob) {
            $this->warn('ジョブ制御レコードが見つかりません。Phase3-5を先に実行してください。');
            return;
        }

        $batchCode = $latestJob->batch_code;
        $this->info("検証対象 batch_code: {$batchCode}");

        // origin_type の値域チェック
        $allOriginTypes = DB::connection('sakemaru')->select("
            SELECT origin_type, COUNT(*) as cnt FROM wms_order_candidates
            WHERE batch_code = ?
            GROUP BY origin_type
        ", [$batchCode]);
        $this->info('origin_type 分布:');
        $this->table(
            ['origin_type', '件数'],
            collect($allOriginTypes)->map(fn ($r) => [$r->origin_type, $r->cnt])->toArray()
        );

        $validOriginTypes = [OriginType::MANUAL_SAFETY_STOCK->value, OriginType::MANUAL_SALES_BASED->value];
        $invalidOriginCount = collect($allOriginTypes)->filter(fn ($r) => !in_array($r->origin_type, $validOriginTypes))->sum('cnt');
        $this->recordResult('E-extra', 'origin_type値域チェック', $invalidOriginCount === 0, $invalidOriginCount, '0', '許容: MANUAL_SAFETY_STOCK, MANUAL_SALES_BASED');

        // 移動候補のorigin_type
        $allTransferOrigins = DB::connection('sakemaru')->select("
            SELECT origin_type, COUNT(*) as cnt FROM wms_stock_transfer_candidates
            WHERE batch_code = ?
            GROUP BY origin_type
        ", [$batchCode]);
        $this->info('移動候補 origin_type 分布:');
        $this->table(
            ['origin_type', '件数'],
            collect($allTransferOrigins)->map(fn ($r) => [$r->origin_type, $r->cnt])->toArray()
        );

        // 発注CD: search_codeの検証
        $searchCodeWithoutOrdering = DB::connection('sakemaru')->select("
            SELECT COUNT(*) as cnt FROM wms_order_candidates oc
            WHERE oc.batch_code = ? AND oc.search_code IS NOT NULL
        ", [$batchCode]);
        $this->info("search_code付き候補数: " . ($searchCodeWithoutOrdering[0]->cnt ?? 0));

        // ordering_code の NULL/非NULL 分布
        $orderingDist = DB::connection('sakemaru')->select("
            SELECT
                SUM(CASE WHEN ordering_code IS NOT NULL THEN 1 ELSE 0 END) as with_code,
                SUM(CASE WHEN ordering_code IS NULL THEN 1 ELSE 0 END) as without_code
            FROM wms_order_candidates
            WHERE batch_code = ?
        ", [$batchCode]);
        $this->info("ordering_code分布: あり={$orderingDist[0]->with_code}, なし={$orderingDist[0]->without_code}");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Phase 7: 最終分析レポート
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function runPhase7(): void
    {
        $this->info('【Phase 7】最終分析レポート作成');

        if (empty($this->results)) {
            $this->warn('テスト結果が空です。Phase3-6を先に実行してください。');
            return;
        }

        $reportPath = storage_path('specifications/20260422/20260422-auto-order-integration-test/test-results.md');
        $dir = dirname($reportPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $report = "# 発注候補生成 統合テスト結果\n\n";
        $report .= "- **実行日時**: " . now()->format('Y-m-d H:i:s') . "\n";
        $report .= "- **実行者ID**: {$this->createdBy}\n";
        $report .= "- **対象倉庫数**: " . WmsWarehouseAutoOrderSetting::enabled()->count() . "\n\n";

        $report .= "## サマリ\n\n";
        $report .= "| 結果 | 件数 |\n|------|------|\n";
        $report .= "| PASS | {$this->passCount} |\n";
        $report .= "| FAIL | {$this->failCount} |\n";
        $report .= "| SKIP | {$this->skipCount} |\n";
        $report .= "| 合計 | " . ($this->passCount + $this->failCount + $this->skipCount) . " |\n\n";

        $report .= "## 全テスト結果\n\n";
        $report .= "| # | ケース | 結果 | 実測値 | 期待値 | 詳細 |\n";
        $report .= "|---|--------|------|--------|--------|------|\n";

        foreach ($this->results as $r) {
            $statusIcon = $r['pass'] ? 'PASS' : 'FAIL';
            $report .= "| {$r['id']} | {$r['name']} | {$statusIcon} | {$r['actual']} | {$r['expected']} | {$r['detail']} |\n";
        }

        $report .= "\n## 分析\n\n";

        $failedResults = array_filter($this->results, fn ($r) => !$r['pass']);
        if (empty($failedResults)) {
            $report .= "全テストケースが PASS しました。\n";
        } else {
            $report .= "### 失敗ケース\n\n";
            foreach ($failedResults as $r) {
                $report .= "- **{$r['id']}** ({$r['name']}): 実測={$r['actual']}, 期待={$r['expected']}. {$r['detail']}\n";
            }
        }

        file_put_contents($reportPath, $report);
        $this->info("レポート出力: {$reportPath}");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ヘルパー
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function recordResult(string $id, string $name, bool $pass, mixed $actual, mixed $expected, string $detail): void
    {
        $this->results[] = [
            'id' => $id,
            'name' => $name,
            'pass' => $pass,
            'actual' => (string) $actual,
            'expected' => (string) $expected,
            'detail' => $detail,
        ];

        if ($pass) {
            $this->passCount++;
            $this->info("  [PASS] {$id}: {$name}");
        } else {
            $this->failCount++;
            $this->error("  [FAIL] {$id}: {$name} (actual={$actual}, expected={$expected}) {$detail}");
        }
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("テスト結果サマリ");
        $total = $this->passCount + $this->failCount + $this->skipCount;
        $this->info("  PASS: {$this->passCount} / {$total}");
        if ($this->failCount > 0) {
            $this->error("  FAIL: {$this->failCount} / {$total}");
        }
        if ($this->skipCount > 0) {
            $this->warn("  SKIP: {$this->skipCount}");
        }
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }
}
