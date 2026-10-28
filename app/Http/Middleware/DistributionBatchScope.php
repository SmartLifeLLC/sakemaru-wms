<?php

namespace App\Http\Middleware;

use App\Models\WmsDistributionBatch;
use App\Models\WmsDistributionRow;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sakemaru\Auth\Services\PermissionService;

/** Lock a batch before reads/writes so its rows and revision always form one snapshot. */
class DistributionBatchScope
{
    public function handle(Request $request, Closure $next)
    {
        $batchId = $request->route('batch');
        if (! $batchId) {
            $storeRead = $request->input('context') === 'store' && ($request->isMethod('GET') || $request->is('*/results'));
            $headquartersOnly = $request->is('*warehouse-transfers', '*stock-transfer-slips');
            if (! $storeRead && ($headquartersOnly || $request->input('mode', 'allocation') !== 'direct')
                && Schema::connection('sakemaru')->hasTable('wms_distribution_batches')) {
                return response()->json(['message' => '分配一覧から対象の分配を開き直してください。古い画面からは保存できません。'], 409);
            }

            return $next($request);
        }
        abort_unless($request->user() && app(PermissionService::class)->check($request->user(), 'wms.distribution-adjustment.view'), 403);

        return DB::connection('sakemaru')->transaction(function () use ($batchId, $request, $next) {
            $batch = WmsDistributionBatch::query()->where('client_id', (int) config('app.client_id'))
                ->where('warehouse_id', (int) $request->user()->getSelectedWarehouseId())
                ->lockForUpdate()->findOrFail($batchId);
            $reopening = $request->isMethod('PATCH') && $request->has('archive') && ! $request->boolean('archive');
            abort_if($batch->archived_at && ! $request->isMethod('GET') && ! $request->is('*/results') && ! $reopening, 422, 'すでに対応済みの分配です。作業を再開するには再開するボタンを押してください。');
            $request->attributes->set('distribution_batch', $batch);
            // These endpoints are exclusively for headquarters allocation, never direct rows.
            $request->merge(['mode' => 'allocation']);
            $request->validate(['rows' => 'sometimes|array|max:6000', 'rows.*' => 'array',
                'rows.*.id' => 'sometimes|string|max:120', 'rows.*.row_id' => 'sometimes|string|max:120',
                'row_ids' => 'sometimes|array|max:6000', 'row_ids.*' => 'string|max:120',
                'deleted_row_ids' => 'sometimes|array|max:6000', 'deleted_row_ids.*' => 'string|max:120']);
            $auditMutation = ! $request->isMethod('GET') && ! $request->is('*/preview', '*/results');
            $beforeRows = $auditMutation ? $batch->rows()->get(['row_id', 'row_data', 'deleted_at'])->keyBy('row_id') : collect();
            $beforeHeader = $batch->only(['name', 'shipping_date', 'note', 'responsible_department', 'deleted_at', 'archived_at']);
            $ids = collect($request->input('rows', []))->map(fn ($row) => $row['row_id'] ?? $row['id'] ?? null)
                ->merge($request->input('deleted_row_ids', []))->merge($request->input('row_ids', []))->filter()->unique();
            if ($ids->isNotEmpty()) {
                $foreign = WmsDistributionRow::withoutGlobalScope('distribution_batch')->withTrashed()
                    ->whereIn('row_id', $ids)->where(function ($q) use ($batch) {
                        $q->where('distribution_batch_id', '!=', $batch->id)->orWhereNull('distribution_batch_id');
                    })->exists();
                abort_if($foreign, 403, '別の分配の明細は変更できません。');
            }
            if ($request->is('*warehouse-transfers', '*stock-transfer-slips') || ($request->is('*order-candidates') && ! $request->boolean('check_only'))) {
                $request->validate(['expected_revision' => 'required|integer|min:0'], [], ['expected_revision' => '分配の更新番号']);
                abort_unless((int) $request->input('expected_revision') === $batch->revision, 409, '分配が更新されています。再読み込みして生成対象を確認してください。');
            }
            $response = $next($request);
            if ($response->getStatusCode() === 409 && method_exists($response, 'getData')) {
                $payload = $response->getData(true);
                if (($payload['code'] ?? '') === 'DISTRIBUTION_REVISION_CONFLICT') {
                    $batch->loadMissing('updater');
                    $payload['result']['error_message'] = '分配「'.$batch->name.'」は別の画面で更新されています（'
                        .($batch->updater?->name ?? '不明').' '.$batch->updated_at?->format('m/d H:i').'）。未保存内容を控え、再読み込みして確認してください。';
                    $response->setData($payload);
                }
            }
            if ($response->getStatusCode() < 300 && $request->is('*warehouse-transfers', '*stock-transfer-slips')) {
                $payload = $response->getData(true);
                $result = $payload['result']['data'] ?? [];
                $queueIds = array_values(array_unique(array_map('intval', array_column($result['queues'] ?? [], 'queue_id'))));
                if ($queueIds !== []) {
                    foreach ($batch->rows()->whereIn('row_id', $result['row_ids'] ?? [])->get() as $row) {
                        $row->row_data = array_replace($row->row_data, ['warehouseTransferGenerated' => true,
                            'warehouseTransferQueueIds' => $queueIds]);
                        $row->updated_by = $request->user()->id;
                        $row->save();
                    }
                    $batch->increment('revision');
                    $batch->update(['updated_by' => $request->user()->id]);
                    $payload['result']['data']['revision'] = $batch->revision;
                    $response->setData($payload);
                }
            }
            if ($response->getStatusCode() < 300 && $request->is('*order-candidates') && ! $request->boolean('check_only')) {
                $payload = $response->getData(true);
                $result = $payload['result']['data'] ?? [];
                if (! empty($result['candidate_ids'])) {
                    foreach ($batch->rows()->whereIn('row_id', $result['row_ids'] ?? [])->get() as $row) {
                        $row->update(['row_data' => array_replace($row->row_data, ['orderCandidateGenerated' => true,
                            'orderCandidateIds' => $result['candidate_ids_by_row'][$row->row_id] ?? $result['candidate_ids']]),
                            'updated_by' => $request->user()->id]);
                    }
                    $batch->update(['revision' => $batch->revision + 1, 'updated_by' => $request->user()->id]);
                    $payload['result']['data']['revision'] = $batch->revision;
                    $response->setData($payload);
                }
            }
            if ($response->getStatusCode() < 300 && $auditMutation) {
                $changes = [];
                $afterRows = $batch->rows()->get(['row_id', 'row_data'])->keyBy('row_id');
                foreach ($beforeRows->keys()->merge($afterRows->keys())->unique() as $rowId) {
                    $before = $beforeRows->get($rowId)?->row_data ?? [];
                    $after = $afterRows->get($rowId)?->row_data ?? [];
                    if ($before != $after) {
                        $changes[$rowId] = ['before' => $before, 'after' => $after];
                    }
                }
                $afterHeader = $batch->only(['name', 'shipping_date', 'note', 'responsible_department', 'deleted_at', 'archived_at']);
                if ($beforeHeader != $afterHeader) {
                    $changes['header'] = ['before' => $beforeHeader, 'after' => $afterHeader];
                }
                if ($changes !== []) {
                    DB::connection('sakemaru')->table('wms_distribution_batch_events')->insert([
                        'distribution_batch_id' => $batch->id, 'created_by' => $request->user()->id,
                        'operation' => $request->method().' '.basename($request->path()),
                        'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
                    ]);
                }
            }

            return $response;
        });
    }
}
