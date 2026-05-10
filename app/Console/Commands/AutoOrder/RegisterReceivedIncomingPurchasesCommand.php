<?php

namespace App\Console\Commands\AutoOrder;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterReceivedIncomingPurchasesCommand extends Command
{
    protected $signature = 'wms:incoming-register-received-purchases
                            {--since= : 対象の更新日時開始（例: 2026-05-11 04:30:00）}
                            {--until= : 対象の更新日時終了}
                            {--limit= : 対象入荷予定数の上限}
                            {--dry-run : 対象確認のみで更新しない}
                            {--yes : 確認なしで実行}';

    protected $description = 'JX受信で反映済みの入荷予定を仕入キューへ登録する';

    public function handle(): int
    {
        $schedules = $this->resolveSchedules();

        if ($schedules->isEmpty()) {
            $this->warn('仕入登録対象のJX受信入荷予定がありません。');

            return self::SUCCESS;
        }

        [$groups, $skipped] = $this->groupSchedules($schedules);

        $this->table(
            ['対象入荷予定', '登録可能', 'スキップ', '仕入キュー予定'],
            [[
                $schedules->count(),
                $groups->flatten(1)->count(),
                count($skipped),
                $groups->sum(fn (Collection $rows): int => (int) ceil($rows->count() / 100)),
            ]]
        );

        if (! empty($skipped)) {
            $this->warn('マスタ不足によりスキップされる入荷予定があります。');
            $this->table(
                ['ID', '倉庫CD', '仕入先CD', '商品CD'],
                collect($skipped)->take(20)->map(fn (array $row): array => [
                    $row['id'],
                    $row['warehouse_code'] ?: '-',
                    $row['supplier_code'] ?: '-',
                    $row['item_code'] ?: '-',
                ])->all()
            );
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run のため、仕入キュー登録は行いません。');

            return self::SUCCESS;
        }

        if ($groups->isEmpty()) {
            $this->error('登録可能な入荷予定がありません。');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('表示したJX受信入荷予定を仕入キューへ登録しますか？')) {
            $this->info('キャンセルしました。');

            return self::SUCCESS;
        }

        $result = DB::connection('sakemaru')->transaction(fn (): array => $this->registerPurchaseQueues($groups));

        $this->info("完了: 仕入キュー {$result['queue_count']}件 / 入荷予定 {$result['schedule_count']}件");
        $this->line('仕入キューID: '.implode(', ', $result['queue_ids']));

        return self::SUCCESS;
    }

    private function resolveSchedules(): Collection
    {
        $query = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('items as i', 'i.id', '=', 's.item_id')
            ->leftJoin('contractors as ct', 'ct.id', '=', 's.contractor_id')
            ->where('s.order_source', 'RECEIVED')
            ->where('s.is_receive_matched', true)
            ->where('s.status', 'PENDING')
            ->where('s.received_quantity', '>', 0)
            ->select([
                's.id',
                's.warehouse_id',
                's.contractor_id',
                's.item_id',
                's.item_code',
                's.slip_number',
                's.received_quantity',
                's.shortage_quantity',
                's.quantity_type',
                's.expected_arrival_date',
                's.actual_arrival_date',
                's.expiration_date',
                'w.code as warehouse_code',
                'i.code as master_item_code',
                'ct.code as contractor_code',
            ])
            ->orderBy('s.id');

        if ($this->option('since')) {
            $query->where('s.updated_at', '>=', $this->option('since'));
        }

        if ($this->option('until')) {
            $query->where('s.updated_at', '<=', $this->option('until'));
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        return $query->get();
    }

    private function groupSchedules(Collection $schedules): array
    {
        $skipped = [];
        $rows = $schedules->map(function (object $schedule) use (&$skipped): ?array {
            $supplierCode = $this->resolveSupplierCode($schedule);
            $warehouseCode = trim((string) $schedule->warehouse_code);
            $itemCode = trim((string) ($schedule->master_item_code ?: $schedule->item_code));
            $deliveredDate = $schedule->actual_arrival_date
                ?: $schedule->expected_arrival_date
                ?: now()->format('Y-m-d');

            if ($supplierCode === '' || $warehouseCode === '' || $itemCode === '') {
                $skipped[] = [
                    'id' => $schedule->id,
                    'warehouse_code' => $warehouseCode,
                    'supplier_code' => $supplierCode,
                    'item_code' => $itemCode,
                ];

                return null;
            }

            return [
                'schedule' => $schedule,
                'warehouse_code' => $warehouseCode,
                'supplier_code' => $supplierCode,
                'delivered_date' => substr((string) $deliveredDate, 0, 10),
                'item_code' => $itemCode,
            ];
        })->filter();

        return [
            $rows->groupBy(fn (array $row): string => "{$row['warehouse_code']}|{$row['supplier_code']}|{$row['delivered_date']}"),
            $skipped,
        ];
    }

    private function resolveSupplierCode(object $schedule): string
    {
        if ($schedule->contractor_code !== null && trim((string) $schedule->contractor_code) !== '') {
            return trim((string) $schedule->contractor_code);
        }

        $slipContractorCode = DB::connection('sakemaru')
            ->table('wms_incoming_received_slips')
            ->where('slip_number', $schedule->slip_number)
            ->whereNotNull('b_contractor_code')
            ->value('b_contractor_code');

        return trim((string) $slipContractorCode);
    }

    private function registerPurchaseQueues(Collection $groups): array
    {
        $queueIds = [];
        $scheduleCount = 0;
        $now = now();

        foreach ($groups as $rows) {
            foreach ($rows->chunk(100) as $chunk) {
                $first = $chunk->first();
                $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
                    'request_uuid' => Str::uuid()->toString(),
                    'delivered_date' => $first['delivered_date'],
                    'items' => json_encode($this->buildPurchaseData($chunk), JSON_UNESCAPED_UNICODE),
                    'status' => 'BEFORE',
                    'retry_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $ids = $chunk->map(fn (array $row): int => (int) $row['schedule']->id)->all();
                $updated = DB::connection('sakemaru')
                    ->table('wms_order_incoming_schedules')
                    ->whereIn('id', $ids)
                    ->where('status', 'PENDING')
                    ->update([
                        'status' => 'TRANSMITTED',
                        'actual_arrival_date' => $first['delivered_date'],
                        'confirmed_at' => $now,
                        'purchase_queue_id' => $queueId,
                        'updated_at' => $now,
                    ]);

                $queueIds[] = $queueId;
                $scheduleCount += $updated;
            }
        }

        return [
            'queue_count' => count($queueIds),
            'schedule_count' => $scheduleCount,
            'queue_ids' => $queueIds,
        ];
    }

    private function buildPurchaseData(Collection $rows): array
    {
        $first = $rows->first();

        return [
            'process_date' => $first['delivered_date'],
            'delivered_date' => $first['delivered_date'],
            'account_date' => $first['delivered_date'],
            'supplier_code' => $first['supplier_code'],
            'warehouse_code' => $first['warehouse_code'],
            'note' => 'JX受信データ一括登録 / '.now()->format('Y-m-d H:i:s'),
            'details' => $rows->map(fn (array $row): array => $this->buildDetail($row))->values()->all(),
        ];
    }

    private function buildDetail(array $row): array
    {
        $schedule = $row['schedule'];
        $detail = [
            'item_code' => $row['item_code'],
            'quantity' => (int) $schedule->received_quantity,
            'quantity_type' => $schedule->quantity_type ?: 'PIECE',
            'shortage_quantity' => (int) ($schedule->shortage_quantity ?? 0),
        ];

        if ($schedule->expiration_date) {
            $detail['expiration_date'] = substr((string) $schedule->expiration_date, 0, 10);
        }

        return $detail;
    }
}
