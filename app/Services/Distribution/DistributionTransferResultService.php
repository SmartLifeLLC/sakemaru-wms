<?php

namespace App\Services\Distribution;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DistributionTransferResultService
{
    /** Resolve saved rows to queues and authoritative, whole transfer slips. */
    public function resolve(int $clientId, int $sourceId, array $rows, array $destinationKeys): array
    {
        $db = DB::connection('sakemaru');

        return $db->transaction(function () use ($db, $clientId, $sourceId, $rows, $destinationKeys): array {
            $warehouses = $db->table('warehouses')->where('client_id', $clientId)
                ->get(['id', 'code', 'name', 'stock_warehouse_id'])->keyBy(fn ($w) => $this->key($w->code, $w->id));
            $source = $warehouses->firstWhere('id', $sourceId);
            if (! $source) {
                throw new RuntimeException('出庫元倉庫を確認できません。画面を再読み込みしてください。');
            }
            $sourceId = (int) ($source->stock_warehouse_id ?? $sourceId);
            $source = $warehouses->firstWhere('id', $sourceId);
            if (! $source) {
                throw new RuntimeException('出庫元の実倉庫を確認できません。');
            }
            $queueIds = collect($rows)->flatMap(fn ($r) => array_merge(
                $r['warehouseTransferQueueIds'] ?? $r['warehouse_transfer_queue_ids'] ?? [],
                $r['transferSlipQueueIds'] ?? $r['transfer_slip_queue_ids'] ?? []
            ))->map(fn ($id) => (int) $id)->filter()->unique()->values();
            if ($queueIds->count() > 3000) {
                throw new RuntimeException('伝票の確認対象が多すぎます。対象を分けて再実行してください。');
            }
            $queues = $db->table('stock_transfer_queue')->where('client_id', $clientId)
                ->where('action_type', 'CREATE')->where('from_warehouse_code', (string) $source->code)
                ->whereIn('id', $queueIds)->get()->keyBy('id');
            $states = [];
            $documents = [];
            $references = [];
            $dedupe = app(DistributionStockTransferSlipService::class);

            foreach ($rows as $row) {
                $rowId = (string) ($row['id'] ?? '');
                $identities = array_filter([$row['distributionBusinessKey'] ?? null, $rowId]);
                foreach (array_intersect($destinationKeys, $row['_requested_destination_keys'] ?? $destinationKeys) as $key) {
                    $key = (string) $key;
                    $destination = $warehouses->get($key);
                    if (! $destination) {
                        throw new RuntimeException('分配先倉庫を確認できません。店舗マスターを再読み込みしてください。');
                    }
                    $quantity = (int) ($row['alloc_'.$key] ?? 0);
                    $wish = (int) ($row['wish_'.$key] ?? 0);
                    if ($quantity <= 0 && $wish <= 0) {
                        continue;
                    }
                    $keys = array_map(fn ($id) => $dedupe->makeDistributionLineDedupeKey($sourceId, (int) $destination->id, (string) $id), $identities);
                    $matches = $queues->filter(function ($queue) use ($destination, $keys) {
                        if ((string) $queue->to_warehouse_code !== (string) $destination->code) {
                            return false;
                        }
                        foreach (json_decode($queue->items, true, 512, JSON_THROW_ON_ERROR) as $item) {
                            if (in_array($item['_distribution_dedupe_key'] ?? '', $keys, true)) {
                                return true;
                            }
                        }
                        // Older queues stored the business key in their note.
                        foreach ($keys as $key) {
                            if (str_contains((string) ($queue->note ?? ''), $key)) {
                                return true;
                            }
                        }

                        return false;
                    });
                    if ($matches->isEmpty()) {
                        if ($quantity > 0 && ($row['_distribution_mode'] ?? 'allocation') !== 'direct') {
                            $states[] = ['row_ids' => [$rowId], 'destination_key' => $key, 'state' => 'missing',
                                'message' => '倉庫移動の受付記録を確認できません。生成結果を確認してください。'];
                        } else {
                            $referenceKey = ($row['_distribution_mode'] ?? 'allocation').'-'.$key;
                            $references[$referenceKey]['kind'] = ($row['_distribution_mode'] ?? 'allocation') === 'direct' ? 'direct-reference' : 'reference';
                            $references[$referenceKey]['destination'] = $this->destination($destination);
                            $references[$referenceKey]['rows'][] = $row;
                        }

                        continue;
                    }
                    foreach ($matches as $queue) {
                        if (! isset($states['queue-'.$queue->id])) {
                            $state = $queue->status === 'FINISHED'
                                ? ($queue->is_success ? 'succeeded' : 'failed')
                                : ($queue->status === 'BEFORE' && (int) ($queue->retry_count ?? 0) > 0 ? 'retrying' : 'pending');
                            $states['queue-'.$queue->id] = ['queue_id' => (int) $queue->id, 'row_ids' => [],
                                'destination_key' => $key, 'state' => $state,
                                'message' => $state === 'failed' ? '倉庫移動の生成に失敗しました。管理者に受付番号 '.$queue->id.' の確認を依頼してください。' : null];
                            if ($state === 'succeeded') {
                                $document = $this->document($clientId, $sourceId, $destination, $queue);
                                $documents[$document['stock_transfer_id']] = $document;
                            }
                        }
                        $states['queue-'.$queue->id]['row_ids'][] = $rowId;
                    }
                }
            }
            foreach ($references as $key => $reference) {
                $documents['reference-'.$key] = [
                    'kind' => $reference['kind'], 'serial_id' => null, 'slip_number' => null,
                    'destination' => $reference['destination'], 'rows' => $reference['rows'],
                    'picking_date' => null, 'delivered_date' => null,
                ];
            }

            return ['queues' => array_values($states), 'documents' => array_values($documents)];
        });
    }

    private function document(int $clientId, int $sourceId, object $destination, object $queue): array
    {
        $db = DB::connection('sakemaru');
        $original = $db->table('stock_transfers as st')->join('trades as t', 't.id', '=', 'st.trade_id')
            ->where('st.id', $queue->stock_transfer_id)->where('st.client_id', $clientId)->where('t.client_id', $clientId)
            ->first(['t.serial_id', 't.trade_category']);
        if (! $original || ! $original->serial_id) {
            throw new RuntimeException('生成済み伝票の識別IDを取得できません。受付番号 '.$queue->id.' を管理者に確認してください。');
        }
        $current = $db->table('trades as t')->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)->where('st.client_id', $clientId)
            ->where('t.trade_category', $original->trade_category)->where('t.serial_id', $original->serial_id)
            ->where('t.is_latest', true)->where('t.is_active', true)->where('t.is_offset', false)->where('st.is_active', true)
            ->get(['st.id as stock_transfer_id', 't.id as trade_id', 't.serial_id', 't.slip_number',
                'st.from_warehouse_id', 'st.to_warehouse_id', 'st.picking_date', 'st.delivered_date', 't.process_date']);
        if ($current->count() !== 1 || (int) $current[0]->from_warehouse_id !== $sourceId
            || (int) $current[0]->to_warehouse_id !== (int) $destination->id) {
            throw new RuntimeException('訂正後の伝票または移動先を確認できません。受付番号 '.$queue->id.' を管理者に確認してください。');
        }
        $trade = $current[0];
        $expectedLineCount = $db->table('trade_items')->where('client_id', $clientId)
            ->where('trade_id', $trade->trade_id)->where('is_active', true)->count();
        $rows = $db->table('trade_items as ti')->join('items as i', 'i.id', '=', 'ti.item_id')
            ->where('ti.client_id', $clientId)->where('i.client_id', $clientId)
            ->where('ti.trade_id', $trade->trade_id)->where('ti.is_active', true)
            ->orderBy('ti.order_of_items_in_slip')->orderBy('ti.id')
            ->get(['ti.id', 'ti.item_id', 'i.code', 'ti.item_name', 'ti.capacity_case', 'ti.total_piece_quantity', 'ti.note'])
            ->map(fn ($line) => [
                'id' => 'trade-item-'.$line->id, 'tradeItemId' => (int) $line->id, 'itemId' => (int) $line->item_id,
                'productCode' => (string) $line->code, 'name' => $line->item_name, 'unitsPerCase' => (int) $line->capacity_case,
                'memo' => $line->note, 'orderDate' => $trade->picking_date ?: $trade->process_date,
                'alloc_'.$this->key($destination->code, $destination->id) => (int) $line->total_piece_quantity,
            ])->all();
        if ($rows === [] || count($rows) !== $expectedLineCount) {
            throw new RuntimeException('実伝票の明細を取得できません。受付番号 '.$queue->id.' を管理者に確認してください。');
        }

        return ['kind' => 'transfer', 'stock_transfer_id' => (int) $trade->stock_transfer_id,
            'trade_id' => (int) $trade->trade_id, 'queue_id' => (int) $queue->id,
            'serial_id' => (string) $trade->serial_id, 'slip_number' => $trade->slip_number,
            'picking_date' => $trade->picking_date ?: $trade->process_date, 'delivered_date' => $trade->delivered_date,
            'destination' => $this->destination($destination), 'rows' => $rows];
    }

    private function key($code, $id): string
    {
        $code = trim((string) $code);

        return $code === '' ? (string) $id : (ctype_digit($code) ? str_pad($code, 2, '0', STR_PAD_LEFT) : $code);
    }

    private function destination(object $warehouse): array
    {
        return ['id' => (int) $warehouse->id, 'key' => $this->key($warehouse->code, $warehouse->id),
            'code' => (string) $warehouse->code, 'name' => $warehouse->name];
    }
}
