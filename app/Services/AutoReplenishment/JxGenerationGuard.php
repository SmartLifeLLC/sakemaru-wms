<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRunLine;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderJxDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JxGenerationGuard
{
    private array $heldLocks = [];

    public function __construct(private readonly ReplenishmentGuard $guard) {}

    public function eligible(Collection $candidates): Collection
    {
        if (! $this->guard->installed()) {
            return $candidates;
        }
        $lines = WmsAutoOrderRunLine::whereIn('order_candidate_id', $candidates->pluck('id'))->get()->keyBy('order_candidate_id');

        return $candidates->filter(function ($candidate) use ($lines) {
            $line = $lines->get($candidate->id);
            if (! $line) {
                return true;
            }
            try {
                $this->guard->assertLine($line);

                return true;
            } catch (\RuntimeException $e) {
                if (! in_array($line->phase, ['EOS_JX_READY', 'ABORTED', 'STOPPED'])) {
                    $line->update(['phase' => 'STOPPED', 'reason' => mb_substr($e->getMessage(), 0, 500)]);
                }

                return false;
            }
        })->values();
    }

    public function acquire(Collection $candidates): ?string
    {
        if (! $this->guard->installed()) {
            return null;
        }
        $token = (string) Str::uuid();
        $locked = [];
        try {
            foreach ($candidates->pluck('id')->sort() as $id) {
                $key = 'auto-jx-generation:'.$id;
                throw_unless(\App\Support\DbMutex::acquireOrFail($key, 0, 'sakemaru'), \RuntimeException::class, '別の処理がJXを生成中です。');
                $locked[] = $key;
            }
            DB::connection('sakemaru')->transaction(function () use ($candidates, $token) {
                foreach ($candidates->sortBy('id') as $candidate) {
                    $line = WmsAutoOrderRunLine::where('order_candidate_id', $candidate->id)->first();
                    if ($line) {
                        $this->guard->assertLine($line);
                    }
                    $fresh = WmsOrderCandidate::lockForUpdate()->findOrFail($candidate->id);
                    throw_if($fresh->wms_order_jx_document_id || $fresh->status->value !== 'CONFIRMED', \RuntimeException::class, 'JX生成対象が変更されました。');
                    $claim = DB::connection('sakemaru')->table('wms_order_generation_claims')->where('candidate_id', $fresh->id)->lockForUpdate()->first();
                    if ($claim && $claim->state === 'PUBLISHED' && ! $line) {
                        $old = DB::connection('sakemaru')->table('wms_order_jx_documents')->find($claim->document_id);
                        if ($old && $old->status === 'CANCELLED') {
                            DB::connection('sakemaru')->table('wms_order_generation_claims')->where('id', $claim->id)->delete();
                            $claim = null;
                        }
                    }
                    throw_if($claim, \RuntimeException::class, 'JX生成中または生成履歴があります。照合が必要です。');
                    DB::connection('sakemaru')->table('wms_order_generation_claims')->insert([
                        'candidate_id' => $fresh->id, 'token' => $token, 'manifest_hash' => $this->hash($fresh),
                        'state' => 'BUILDING', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }, 3);
        } catch (\Throwable $e) {
            foreach ($locked as $key) {
                \App\Support\DbMutex::release($key, 'sakemaru');
            }
            throw $e;
        }
        $this->heldLocks[$token] = $locked;

        return $token;
    }

    public function validatePublication(Collection $candidates, ?string $token): void
    {
        if ($token === null) {
            return;
        }
        foreach ($candidates->sortBy('id') as $candidate) {
            $line = WmsAutoOrderRunLine::where('order_candidate_id', $candidate->id)->first();
            if ($line) {
                $this->guard->assertLine($line);
                throw_unless(hash_equals($line->confirmation_snapshot['jx_route_hash'], app(JxRouteFingerprint::class)->fingerprint((int) $candidate->contractor_id, true)), \RuntimeException::class, 'JX経路の設定が変わったため公開を保留します。');
            }
            $fresh = WmsOrderCandidate::lockForUpdate()->findOrFail($candidate->id);
            $claim = DB::connection('sakemaru')->table('wms_order_generation_claims')->where('candidate_id', $candidate->id)->where('token', $token)->lockForUpdate()->first();
            throw_unless($claim && $claim->state === 'BUILDING' && hash_equals($claim->manifest_hash, $this->hash($fresh)) && ! $fresh->wms_order_jx_document_id,
                \RuntimeException::class, '生成中に発注内容が変更されたためJXを公開しません。');
        }
    }

    public function recordPublication(Collection $candidates, WmsOrderJxDocument $document, ?string $token, array $file, array $details): void
    {
        if ($token === null) {
            return;
        }
        $orderedIds = collect($file['slip_assignments'] ?? [])->flatMap(fn ($a) => $a['order_candidate_ids'] ?? [])->values();
        $lines = WmsAutoOrderRunLine::whereIn('order_candidate_id', $candidates->pluck('id'))->get()->keyBy('order_candidate_id');
        if ($lines->isNotEmpty()) {
            throw_unless($orderedIds->count() === count($details) && $orderedIds->sort()->values()->all() === $candidates->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(), \RuntimeException::class, 'JX全明細の出力対応が不一致です。');
        }
        foreach ($orderedIds as $index => $candidateId) {
            $line = $lines->get($candidateId);
            if (! $line) {
                continue;
            }
            $candidate = $candidates->firstWhere('id', $candidateId);
            $detail = $details[$index];
            $output = app(\App\Services\AutoOrder\OrderOutputQuantityResolver::class)->resolve($candidate);
            $pieces = (int) $detail['d_case_quantity'] * (int) $candidate->item->capacity_case
                + (int) $detail['d_piece_quantity'] * (int) ($output['ordering_unit_quantity'] ?? 1);
            throw_unless($pieces === (int) $line->quantity_pieces && $pieces <= (int) $line->confirmation_snapshot['policy']['max_order_pieces']
                && ltrim((string) $detail['d_item_code'], '0') === ltrim((string) $candidate->item_code, '0')
                && $detail['b_delivery_date'] === $candidate->expected_arrival_date->format('ymd'), \RuntimeException::class, 'JX実出力の数量・商品・日付が発注と一致しません。');
            $schedulePieces = 0;
            $outputSchedules = [];
            foreach (\App\Models\WmsOrderIncomingSchedule::where('order_candidate_id', $candidateId)->get() as $s) {
                $schedulePieces += app(SnapshotReader::class)->pieces((int) $s->expected_quantity, $s->quantity_type->value, $candidate->item);
                $outputSchedules[] = $s->toArray();
                DB::connection('sakemaru')->table('wms_auto_order_artifact_links')->where(['line_id' => $line->id, 'artifact_type' => 'incoming', 'artifact_id' => $s->id])->update(['business_number' => $s->slip_number, 'updated_at' => now()]);
            }
            throw_unless($schedulePieces === $pieces, \RuntimeException::class, 'JXと入荷予定の総バラが一致しません。');
            $actualAmount = bcmul($detail['d_unit_price_decimal'], (string) $detail['d_total_piece_quantity'], 4);
            if (bccomp($actualAmount, (string) $line->amount, 4) > 0) {
                $line->update(['amount' => $actualAmount]);
                DB::connection('sakemaru')->table('wms_auto_order_budget_entries')->where('effect_key', $line->effect_key)->where('state', 'RESERVED')->update(['amount' => $actualAmount]);
            }
            app(BudgetService::class)->commit($line);
            app(ArtifactRecorder::class)->link($line, 'jx', (int) $document->id, $document->toArray());
            $line->update(['phase' => 'EOS_JX_READY', 'reason' => null, 'artifact_output_snapshot' => ['document' => $document->toArray(), 'detail' => $detail, 'quantity_pieces' => $pieces, 'incoming_schedules' => $outputSchedules]]);
            $this->guard->event((int) $line->client_id, 'JX_GENERATED', ['document_id' => $document->id], (int) $line->id);
        }
        DB::connection('sakemaru')->table('wms_order_generation_claims')->whereIn('candidate_id', $candidates->pluck('id'))->where('token', $token)
            ->update(['state' => 'PUBLISHED', 'document_id' => $document->id, 'updated_at' => now()]);
    }

    public function releaseUnpublished(?string $token): void
    {
        try {
            if ($token !== null) {
                DB::connection('sakemaru')->table('wms_order_generation_claims')->where('token', $token)->where('state', 'BUILDING')->delete();
            }
        } finally {
            foreach ($this->heldLocks[$token] ?? [] as $key) {
                \App\Support\DbMutex::release($key, 'sakemaru');
            }
            unset($this->heldLocks[$token]);
        }
    }

    public function assertEditable(WmsOrderCandidate $candidate): void
    {
        if (! $this->guard->installed() || ! $candidate->exists) {
            return;
        }
        $claim = DB::connection('sakemaru')->table('wms_order_generation_claims')->where('candidate_id', $candidate->id)->lockForUpdate()->first();
        throw_if($claim?->state === 'BUILDING', \RuntimeException::class, 'JX生成中のため変更できません。');
        $line = WmsAutoOrderRunLine::where('order_candidate_id', $candidate->id)->first();
        throw_if($line && $line->generated_at, \RuntimeException::class, '自動発注の内容変更は工程の照合・中止から行ってください。');
    }

    private function hash(WmsOrderCandidate $candidate): string
    {
        $values = array_intersect_key($candidate->getRawOriginal(), array_flip([
            'id', 'warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'ordering_code', 'order_quantity',
            'quantity_type', 'expected_arrival_date', 'purchase_unit_price', 'status', 'lock_version',
        ]));
        $values['capacity_case'] = $candidate->item?->capacity_case;

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
