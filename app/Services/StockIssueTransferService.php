<?php

namespace App\Services;

use App\Enums\QuantityType;
use App\Enums\StockIssueReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockIssueTransferService
{
    /**
     * @return array{queue_id: int, issue_warehouse_code: string, reason_label: string}
     */
    public function create(array $data, ?int $createdBy = null): array
    {
        $reason = StockIssueReason::from($data['reason']);
        $quantityType = QuantityType::from($data['quantity_type']);
        $quantity = (int) $data['quantity'];

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('払出し数量は1以上で指定してください。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($data, $createdBy, $reason, $quantityType, $quantity): array {
            $fromWarehouse = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('id', $data['from_warehouse_id'])
                ->where('is_active', true)
                ->first();

            if (! $fromWarehouse) {
                throw new \RuntimeException('移動元倉庫が見つかりません。');
            }

            $issueWarehouse = $this->ensureIssueWarehouse();

            if ((string) $fromWarehouse->code === (string) $issueWarehouse->code) {
                throw new \RuntimeException('払出し専用倉庫から払出しはできません。');
            }

            $item = DB::connection('sakemaru')
                ->table('items')
                ->where('id', $data['item_id'])
                ->where('is_active', true)
                ->first();

            if (! $item) {
                throw new \RuntimeException('商品が見つかりません。');
            }

            $reasonLabel = $reason->label();
            $operatorNote = filled($data['note'] ?? null) ? ' 備考: '.trim((string) $data['note']) : '';
            $requestId = 'stock-issue-'.now()->format('YmdHis').'-'.Str::lower(Str::random(8));

            $items = [[
                'item_code' => $item->code,
                'quantity' => $quantity,
                'quantity_type' => $quantityType->value,
                'stock_allocation_code' => '1',
                'note' => "在庫払出し 理由:{$reasonLabel}{$operatorNote}",
            ]];

            $processDate = $data['process_date'] ?? now()->format('Y-m-d');

            $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
                'client_id' => config('app.client_id'),
                'request_id' => $requestId,
                'slip_number' => null,
                'process_date' => $processDate,
                'delivered_date' => $processDate,
                'note' => "在庫払出し 理由:{$reasonLabel} 作成者ID:".($createdBy ?? '-').$operatorNote,
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'from_warehouse_code' => $fromWarehouse->code,
                'to_warehouse_code' => $issueWarehouse->code,
                'delivery_course_id' => null,
                'status' => 'BEFORE',
                'action_type' => 'CREATE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'queue_id' => (int) $queueId,
                'issue_warehouse_code' => (string) $issueWarehouse->code,
                'reason_label' => $reasonLabel,
            ];
        });
    }

    public function issueWarehouseLabel(): string
    {
        $code = $this->issueWarehouseCode();
        $name = $this->issueWarehouseName();

        return "[{$code}]{$name}";
    }

    private function ensureIssueWarehouse(): object
    {
        $code = $this->issueWarehouseCode();
        $name = $this->issueWarehouseName();

        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('code', $code)
            ->first();

        if ($warehouse) {
            return $warehouse;
        }

        $clientId = config('app.client_id')
            ?? DB::connection('sakemaru')->table('clients')->orderBy('id')->value('id');

        $warehouseId = DB::connection('sakemaru')->table('warehouses')->insertGetId([
            'client_id' => $clientId,
            'name' => $name,
            'kana_name' => $name,
            'abbreviation' => $name,
            'code' => $code,
            'out_of_stock_option' => 'IGNORE_STOCK',
            'is_active' => true,
            'creator_id' => 0,
            'last_updater_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first();
    }

    private function issueWarehouseCode(): string
    {
        return (string) (config('wms.stock_issue.warehouse_code') ?: '999');
    }

    private function issueWarehouseName(): string
    {
        return (string) (config('wms.stock_issue.warehouse_name') ?: '在庫払出し専用倉庫');
    }
}
