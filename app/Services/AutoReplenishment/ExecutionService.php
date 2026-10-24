<?php

namespace App\Services\AutoReplenishment;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OriginType;
use App\Enums\QuantityType;
use App\Models\WmsAutoOrderRun;
use App\Models\WmsAutoOrderRunLine;
use App\Models\WmsItemReorderPolicy;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\OrderRegistrationService;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExecutionService
{
    public function __construct(private readonly ReplenishmentGuard $guard, private readonly SnapshotReader $reader,
        private readonly BudgetService $budget, private readonly ArtifactRecorder $artifacts) {}

    public function createRun(int $clientId, int $warehouseId, bool $preview, string $slot): WmsAutoOrderRun
    {
        return DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $preview, $slot) {
            $c = $this->guard->control($clientId);
            if (! $preview) {
                $this->guard->assertExecutable($clientId, $warehouseId);
            }
            throw_unless(DB::connection('sakemaru')->table('warehouses')->where(['client_id' => $clientId, 'id' => $warehouseId])->exists(), \RuntimeException::class, '倉庫が無効です。');

            return WmsAutoOrderRun::firstOrCreate(['client_id' => $clientId, 'warehouse_id' => $warehouseId,
                'slot_key' => $preview ? 'preview:'.Str::uuid() : $slot], [
                    'run_key' => (string) Str::uuid(), 'is_preview' => $preview, 'generation' => $c->generation,
                    'policy_max_id' => WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $warehouseId])->max('id') ?? 0,
                    'business_date' => \App\Models\Sakemaru\ClientSetting::freshSystemDateYMD('auto_replenishment:run'),
                ]);
        }, 3);
    }

    public function process(WmsAutoOrderRun $run): bool
    {
        return \App\Support\DbMutex::withLockOrFail('auto-replenishment:run:'.$run->id, function () use ($run) {
            $this->processLocked($run);

            return true;
        }, 0, 'sakemaru') === true;
    }

    public function resumeLine(WmsAutoOrderRunLine $line): void
    {
        \App\Support\DbMutex::withLockOrFail('auto-replenishment:run:'.$line->run_id, function () use ($line) {
            $this->execute($line);
            $run = WmsAutoOrderRun::findOrFail($line->run_id);
            if (! in_array($run->status, ['RUNNING', 'FAILED'])) {
                $this->refreshRun($run);
            }
        }, 0, 'sakemaru');
    }

    private function processLocked(WmsAutoOrderRun $run): void
    {
        $run->refresh();
        if (in_array($run->status, ['COMPLETED', 'NO_OP'])) {
            return;
        }
        $started = microtime(true);
        $yielded = false;
        if ($run->status === 'PARTIAL') {
            WmsAutoOrderRunLine::where('run_id', $run->id)->whereIn('phase', ['INCOMING_CREATED', 'TRANSFER_PREPARED', 'FAILED_RETRYABLE'])
                ->chunkById(200, function ($lines) {
                    $this->executePrepared($lines);
                    $this->publishLines($lines->map(fn ($line) => $line->refresh()));
                });
            $this->refreshRun($run);

            return;
        }
        WmsItemReorderPolicy::where(['client_id' => $run->client_id, 'warehouse_id' => $run->warehouse_id, 'is_configured' => true])
            ->where('id', '>', (int) $run->cursor)
            ->where('id', '<=', (int) $run->policy_max_id)
            ->when(! $run->is_preview, fn ($q) => $q->where('is_enabled', true))->orderBy('id')->chunkById(200, function ($policies) use ($run, $started, &$yielded) {
                $this->reader->prime($policies);
                try {
                    $prepared = $policies->map(fn ($policy) => $this->prepare($run, $policy));
                } finally {
                    $this->reader->clear();
                }
                if (! $run->is_preview) {
                    $this->executePrepared($prepared);
                }
                if (! $run->is_preview) {
                    $this->publishLines(WmsAutoOrderRunLine::where('run_id', $run->id)->whereIn('policy_id', $policies->pluck('id'))->get());
                }
                $run->update(['cursor' => $policies->last()->id, 'status' => 'RUNNING']);
                if (microtime(true) - $started >= (int) config('replenishment.worker_seconds', 180)) {
                    $yielded = true;

                    return false;
                }
            });
        if (! $yielded) {
            $this->refreshRun($run);
        }
    }

    private function executePrepared(\Illuminate\Support\Collection $prepared): void
    {
        foreach ($prepared as $line) {
            if (in_array($line->phase, ['READY', 'INCOMING_CREATED', 'TRANSFER_PREPARED', 'FAILED_RETRYABLE'])) {
                try {
                    $this->execute($line, false);
                } catch (\Throwable $e) {
                    $line->refresh()->update(['phase' => $line->order_candidate_id || $line->transfer_candidate_id ? 'FAILED_RETRYABLE' : 'HOLD', 'reason' => mb_substr($e->getMessage(), 0, 500)]);
                    $this->guard->event((int) $line->client_id, 'EXECUTION_FAILED', ['error' => $e->getMessage()], (int) $line->id);
                }
            }
        }
    }

    public function refreshRun(WmsAutoOrderRun $run): void
    {
        $lines = WmsAutoOrderRunLine::where('run_id', $run->id);
        $total = (clone $lines)->count();
        $generated = (clone $lines)->whereNotNull('generated_at')->count();
        $held = (clone $lines)->whereIn('phase', ['HOLD', 'STOPPED', 'RECONCILIATION_REQUIRED'])->count();
        $failed = (clone $lines)->where('phase', 'FAILED_RETRYABLE')->count();
        $pending = (clone $lines)->whereIn('phase', ['INCOMING_CREATED', 'TRANSFER_PREPARED', 'TRANSFER_QUEUED', 'TRANSFER_PROCESSING'])->count();
        $run->update(['scanned_count' => $total, 'generated_count' => $generated, 'held_count' => $held, 'failed_count' => $failed,
            'status' => $failed || $held || $pending ? 'PARTIAL' : ($total ? 'COMPLETED' : 'NO_OP'), 'finished_at' => now()]);
    }

    public function prepare(WmsAutoOrderRun $run, WmsItemReorderPolicy $policy): WmsAutoOrderRunLine
    {
        $existing = WmsAutoOrderRunLine::where(['run_id' => $run->id, 'policy_id' => $policy->id])->first();
        if ($existing) {
            return $existing;
        }
        try {
            $snapshot = $this->reader->read($policy);
            $result = app(StrategyCalculator::class)->calculate($policy->toArray(), $snapshot);
            app(ProposalSafety::class)->validate($policy->toArray(), $result, $snapshot['packing_unit'], $snapshot['shipping_unit'] ?? []);
        } catch (\Throwable $e) {
            $snapshot ??= $this->reader->lastObservation() + ['policy' => $policy->toArray()];
            $result = ['phase' => 'HOLD', 'quantity' => 0, 'reason' => mb_substr($e->getMessage(), 0, 500)];
        }
        $warehouse = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $policy->warehouse_id)->first();

        return WmsAutoOrderRunLine::firstOrCreate(['run_id' => $run->id, 'policy_id' => $policy->id], [
            'effect_key' => (string) Str::uuid(), 'client_id' => $run->client_id, 'warehouse_id' => $run->warehouse_id,
            'item_id' => $policy->item_id, 'channel' => $policy->channel, 'phase' => $result['phase'],
            'reason' => $result['reason'], 'quantity_pieces' => $result['quantity'], 'policy_version' => $policy->version,
            'warehouse_version' => $warehouse->replenishment_version ?? 0, 'proposal_snapshot' => $snapshot + ['calculation' => $result],
        ]);
    }

    public function execute(WmsAutoOrderRunLine $line, bool $publish = true): void
    {
        DB::connection('sakemaru')->transaction(function () use ($line) {
            $this->guard->assertLine($line);
            $this->guard->lockScope((int) $line->client_id, (int) $line->warehouse_id, (int) $line->item_id);
            $line->refresh();
            if ($line->order_candidate_id || $line->transfer_candidate_id) {
                return; // Resume only the unfinished downstream stage.
            }
            $policy = WmsItemReorderPolicy::findOrFail($line->policy_id);
            $s = $this->reader->read($policy, (int) $line->id);
            throw_unless($s['business_date'] === $line->proposal_snapshot['business_date'], \RuntimeException::class, '業務日が変わりました。次の実行枠で再計算してください。');
            $result = app(StrategyCalculator::class)->calculate($policy->toArray(), $s);
            app(ProposalSafety::class)->validate($policy->toArray(), $result, $s['packing_unit'], $s['shipping_unit'] ?? []);
            throw_unless($result['phase'] === 'READY' && $result['quantity'] === (int) $line->quantity_pieces, \RuntimeException::class, '在庫・供給が変わったため再計算が必要です。');
            $actor = (int) config('replenishment.system_actor_id');
            throw_unless(DB::connection('sakemaru')->table('users')->where(['id' => $actor, 'client_id' => $line->client_id, 'is_active' => 1])->exists(), \RuntimeException::class, '有効な自動発注実行ユーザーが未設定です。');
            $line->update(['confirmation_snapshot' => $s]);
            $quantity = $s['quantity_type'] === 'CASE' ? intdiv((int) $line->quantity_pieces, $s['capacity_case']) : (int) $line->quantity_pieces;
            if ($line->channel === 'EOS') {
                app(OrderRegistrationService::class)->register((int) $line->warehouse_id, [[
                    'item_id' => $line->item_id, 'contractor_id' => $policy->contractor_id, 'supplier_id' => $s['supplier_id'],
                    'order_quantity' => $quantity, 'quantity_type' => $s['quantity_type'], 'purchase_unit' => $policy->order_multiple_pieces,
                    'expected_arrival_date' => $s['arrival_date'], 'ordering_code' => $s['ordering_code'],
                ]], $actor, OrderChannel::EOS, generateDataFiles: false, originType: OriginType::AUTO_SAFETY_STOCK,
                    onCandidateConfirmed: function ($candidate, $schedules) use ($line, $s) {
                        $line->update(['order_candidate_id' => $candidate->id, 'phase' => 'INCOMING_CREATED', 'generated_at' => now()]);
                        $amount = '0';
                        foreach ($schedules as $schedule) {
                            $pieces = $this->reader->pieces((int) $schedule->expected_quantity, $schedule->quantity_type->value, $candidate->item);
                            $amount = bcadd($amount, bcmul((string) ($schedule->unit_price ?? '0'), (string) $pieces, 4), 4);
                            $this->artifacts->link($line, 'incoming', (int) $schedule->id, $schedule->toArray());
                        }
                        $this->budget->reserve($line, $amount);
                    }, batchCode: 'A'.substr(str_replace('-', '', $line->effect_key), 0, 16));
            } else {
                $this->guard->lockScope((int) $line->client_id, (int) $policy->source_warehouse_id, (int) $line->item_id);
                $candidate = WmsStockTransferCandidate::create([
                    'batch_code' => 'A'.str_replace('-', '', substr($line->effect_key, 0, 18)),
                    'satellite_warehouse_id' => $line->warehouse_id, 'hub_warehouse_id' => $policy->source_warehouse_id,
                    'item_id' => $line->item_id, 'item_code' => $s['item_code'], 'contractor_id' => $policy->contractor_id,
                    'delivery_course_id' => $policy->delivery_course_id, 'suggested_quantity' => $quantity,
                    'transfer_quantity' => $quantity, 'quantity_type' => QuantityType::from($s['quantity_type']),
                    'purchase_unit' => $policy->order_multiple_pieces, 'safety_stock' => $policy->reorder_point_pieces,
                    'expected_arrival_date' => $s['arrival_date'], 'original_arrival_date' => $s['arrival_date'],
                    'status' => CandidateStatus::APPROVED, 'lot_status' => LotStatus::RAW,
                    'origin_type' => OriginType::AUTO_SAFETY_STOCK, 'modified_by' => $actor, 'modified_at' => now(),
                ]);
                $line->update(['transfer_candidate_id' => $candidate->id, 'phase' => 'TRANSFER_PREPARED', 'generated_at' => now()]);
                $amount = bcmul($s['budget_unit_price'], (string) $line->quantity_pieces, 4);
                $this->budget->reserve($line, $amount);
            }
        }, 3);
        if ($publish) {
            $this->publishLines(collect([$line->refresh()]));
        }
    }

    private function publishLines(\Illuminate\Support\Collection $lines): void
    {
        $eos = $lines->where('channel', 'EOS')->whereNotNull('order_candidate_id')->whereIn('phase', ['INCOMING_CREATED', 'FAILED_RETRYABLE']);
        if ($eos->isNotEmpty()) {
            try {
                $result = app(OrderTransmissionService::class)->generateJxFilesForCandidateIds($eos->pluck('order_candidate_id')->map(fn ($id) => (int) $id)->all());
                throw_unless($result['success'] && $eos->every(fn ($l) => $l->refresh()->phase === 'EOS_JX_READY'), \RuntimeException::class, implode(' / ', $result['errors'] ?: ['対象明細のJX生成が未完了です。']));
            } catch (\Throwable $e) {
                foreach ($eos as $line) {
                    if (! in_array($line->refresh()->phase, ['EOS_JX_READY', 'STOPPED', 'ABORTED'])) {
                        $line->update(['phase' => 'FAILED_RETRYABLE', 'reason' => mb_substr($e->getMessage(), 0, 500)]);
                    }
                }
            }
        }
        $transfers = $lines->where('channel', 'LOGISTICS')->whereNotNull('transfer_candidate_id')->whereNull('queue_id')->whereIn('phase', ['TRANSFER_PREPARED', 'FAILED_RETRYABLE']);
        if ($transfers->isEmpty()) {
            return;
        }
        try {
            DB::connection('sakemaru')->transaction(function () use ($transfers) {
                foreach ($transfers as $line) {
                    $this->guard->assertLine($line);
                    $this->guard->lockScope((int) $line->client_id, (int) $line->warehouse_id, (int) $line->item_id);
                }
                $result = app(TransferCandidateExecutionService::class)->executeSelected($transfers->pluck('transfer_candidate_id')->map(fn ($id) => (int) $id)->all(), (int) config('replenishment.system_actor_id'), automatic: true);
                throw_unless($result['candidate_count'] === $transfers->count() && ! $result['errors'], \RuntimeException::class, '物流発注のグループ確定に失敗しました。');
                foreach ($transfers as $line) {
                    $line->refresh();
                    $schedules = WmsOrderIncomingSchedule::where('transfer_candidate_id', $line->transfer_candidate_id)->get();
                    throw_if($schedules->isEmpty() || ! $line->queue_id, \RuntimeException::class, '物流の作成要求・入荷予定の対応が不明です。');
                    foreach ($schedules as $schedule) {
                        $this->artifacts->link($line, 'incoming', (int) $schedule->id, $schedule->toArray());
                    }
                    $this->budget->commit($line);
                    $line->update(['phase' => 'TRANSFER_QUEUED', 'reason' => null]);
                }
            }, 3);
        } catch (\Throwable $e) {
            foreach ($transfers as $line) {
                $line->refresh()->update(['phase' => 'FAILED_RETRYABLE', 'reason' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }
    }
}
