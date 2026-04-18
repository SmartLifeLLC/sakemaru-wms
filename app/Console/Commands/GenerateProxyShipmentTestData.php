<?php

namespace App\Console\Commands;

use App\Models\WmsShortageAllocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateProxyShipmentTestData extends Command
{
    protected $signature = 'test:generate-proxy-shipments
        {--cleanup : テストデータを削除する}
        {--count=6 : 生成する件数}';

    protected $description = '横持ち出荷APIテスト用データを生成する';

    private const TEST_MARKER = 'テストデータ（横持ち出荷API確認用）';

    private const WAVE_NO_PREFIX = 'TEST-PROXY-';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        return $this->generate();
    }

    protected function generate(): int
    {
        $count = (int) $this->option('count');

        $items = DB::connection('sakemaru')->table('items')
            ->where('capacity_case', '>=', 6)
            ->where('volume', '>', 0)
            ->select('id', 'code', 'name', 'capacity_case')
            ->limit($count)
            ->get();

        if ($items->count() < $count) {
            $this->error("商品が{$count}件未満です（{$items->count()}件）");

            return 1;
        }

        $targetWarehouseId = 91;  // 華むすびの蔵 = ピッキング元
        $sourceWarehouseId = 1;   // 本店 = 送り先

        $courses = DB::connection('sakemaru')->table('delivery_courses')
            ->where('code', 'like', '91%')
            ->select('id', 'code', 'name')
            ->limit(3)
            ->get();

        if ($courses->isEmpty()) {
            $this->error('配送コースが見つかりません');

            return 1;
        }

        $clientId = DB::connection('sakemaru')->table('clients')->value('id');
        $partnerId = DB::connection('sakemaru')->table('partners')
            ->where('id', '>=', 1000)->value('id') ?? 1;

        $shipmentDate = now()->format('Y-m-d');

        $this->info('横持ち出荷テストデータを生成します...');
        $this->info("  出荷元倉庫: id={$targetWarehouseId} (華むすびの蔵センター)");
        $this->info("  送り先倉庫: id={$sourceWarehouseId} (本店)");
        $this->info("  出荷日: {$shipmentDate}");
        $this->info("  件数: {$count}");

        DB::connection('sakemaru')->beginTransaction();

        try {
            // 1. ダミー wave_setting + wave（wms_shortages.wave_id が NOT NULL のため）
            // unique(delivery_course_id, picking_start_time) 制約を回避: 既存を再利用 or 新規作成
            $existingSetting = DB::connection('sakemaru')->table('wms_wave_settings')
                ->where('name', self::TEST_MARKER)
                ->first();

            if ($existingSetting) {
                $waveSettingId = $existingSetting->id;
            } else {
                // 重複しない時間を生成（23:5X:00）
                $uniqueMinute = str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT);
                $waveSettingId = DB::connection('sakemaru')->table('wms_wave_settings')->insertGetId([
                    'name' => self::TEST_MARKER,
                    'delivery_course_id' => $courses->first()->id,
                    'picking_start_time' => "23:{$uniqueMinute}:00",
                    'picking_deadline_time' => '23:59:00',
                    'creator_id' => 1,
                    'last_updater_id' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $waveId = DB::connection('sakemaru')->table('wms_waves')->insertGetId([
                'wms_wave_setting_id' => $waveSettingId,
                'wave_no' => self::WAVE_NO_PREFIX . now()->format('Ymd'),
                'shipping_date' => $shipmentDate,
                'status' => 'completed',
                'print_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $createdShortages = [];
            $createdAllocations = [];
            $createdTrades = [];

            foreach ($items as $i => $item) {
                $course = $courses[$i % $courses->count()];
                $assignQty = rand(3, 20);

                // 2. ダミー trade（必須カラムのみ、generated columns は除外）
                $tradeId = DB::connection('sakemaru')->table('trades')->insertGetId([
                    'client_id' => $clientId,
                    'partner_id' => $partnerId,
                    'creator_id' => 1,
                    'uuid' => Str::uuid()->toString(),
                    'serial_id' => 900000 + $i,
                    'entry_lot_number' => self::WAVE_NO_PREFIX . ($i + 1),
                    'process_date' => $shipmentDate,
                    'note' => self::TEST_MARKER,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdTrades[] = $tradeId;

                // 3. ダミー trade_item
                $tradeItemId = DB::connection('sakemaru')->table('trade_items')->insertGetId([
                    'client_id' => $clientId,
                    'trade_id' => $tradeId,
                    'stock_allocation_id' => 0,
                    'item_id' => $item->id,
                    'order_quantity_type' => 'CASE',
                    'quantity' => $assignQty + 5,
                    'quantity_type' => 'CASE',
                    'capacity_case' => $item->capacity_case,
                    'price_category' => 'CLIENT',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 4. wms_shortages
                $shortageId = DB::connection('sakemaru')->table('wms_shortages')->insertGetId([
                    'wave_id' => $waveId,
                    'shipment_date' => $shipmentDate,
                    'warehouse_id' => $sourceWarehouseId,
                    'item_id' => $item->id,
                    'trade_id' => $tradeId,
                    'trade_item_id' => $tradeItemId,
                    'delivery_course_id' => $course->id,
                    'order_qty' => $assignQty + 5,
                    'shortage_qty' => $assignQty,
                    'qty_type_at_order' => 'CASE',
                    'case_size_snap' => $item->capacity_case,
                    'is_confirmed' => true,
                    'confirmed_at' => now(),
                    'status' => 'SHORTAGE',
                    'note' => self::TEST_MARKER,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdShortages[] = $shortageId;

                // 5. wms_shortage_allocations（RESERVED + is_confirmed=true）
                $allocationId = DB::connection('sakemaru')->table('wms_shortage_allocations')->insertGetId([
                    'shortage_id' => $shortageId,
                    'delivery_course_id' => $course->id,
                    'shipment_date' => $shipmentDate,
                    'target_warehouse_id' => $targetWarehouseId,
                    'source_warehouse_id' => $sourceWarehouseId,
                    'assign_qty' => $assignQty,
                    'picked_qty' => 0,
                    'assign_qty_type' => 'CASE',
                    'status' => WmsShortageAllocation::STATUS_RESERVED,
                    'is_confirmed' => true,
                    'confirmed_at' => now(),
                    'is_finished' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdAllocations[] = $allocationId;

                $this->line("  [{$allocationId}] {$item->name} x {$assignQty}ケース → {$course->name}");
            }

            DB::connection('sakemaru')->commit();

            $this->newLine();
            $this->info('生成完了!');
            $this->table(
                ['テーブル', '件数'],
                [
                    ['trades', count($createdTrades)],
                    ['wms_shortages', count($createdShortages)],
                    ['wms_shortage_allocations', count($createdAllocations)],
                ]
            );

            $this->newLine();
            $this->info('=== Android テスト情報 ===');
            $this->info("  warehouse_id: {$targetWarehouseId}");
            $this->info("  shipment_date: {$shipmentDate}");
            $this->info('  ピッカー: id=1〜10 (全員 warehouse_id=91)');
            $this->info('  allocation_ids: ' . implode(', ', $createdAllocations));
            $this->newLine();
            $this->info('クリーンアップ: php artisan test:generate-proxy-shipments --cleanup');

            return 0;
        } catch (\Exception $e) {
            DB::connection('sakemaru')->rollBack();
            $this->error('エラー: ' . $e->getMessage());

            return 1;
        }
    }

    protected function cleanup(): int
    {
        $shortageIds = DB::connection('sakemaru')->table('wms_shortages')
            ->where('note', self::TEST_MARKER)
            ->pluck('id')->toArray();

        $tradeIds = DB::connection('sakemaru')->table('trades')
            ->where('note', self::TEST_MARKER)
            ->pluck('id')->toArray();

        if (empty($shortageIds) && empty($tradeIds)) {
            $this->info('テストデータはありません。');

            return 0;
        }

        $allocationIds = ! empty($shortageIds)
            ? DB::connection('sakemaru')->table('wms_shortage_allocations')
                ->whereIn('shortage_id', $shortageIds)->pluck('id')->toArray()
            : [];

        $requestIds = array_map(fn ($id) => "proxy-shipment-{$id}", $allocationIds);
        $queueCount = ! empty($requestIds)
            ? DB::connection('sakemaru')->table('stock_transfer_queue')
                ->whereIn('request_id', $requestIds)->count()
            : 0;

        $waveIds = DB::connection('sakemaru')->table('wms_waves')
            ->where('wave_no', 'like', self::WAVE_NO_PREFIX . '%')->pluck('id')->toArray();
        $waveSettingIds = DB::connection('sakemaru')->table('wms_wave_settings')
            ->where('name', self::TEST_MARKER)->pluck('id')->toArray();

        $this->info('削除対象:');
        $this->table(['テーブル', '件数'], array_filter([
            ['wms_shortage_allocations', count($allocationIds)],
            ['wms_shortages', count($shortageIds)],
            $queueCount > 0 ? ['stock_transfer_queue', $queueCount] : null,
            ['trade_items (via trades)', count($tradeIds) . '件分'],
            ['trades', count($tradeIds)],
            ! empty($waveIds) ? ['wms_waves', count($waveIds)] : null,
            ! empty($waveSettingIds) ? ['wms_wave_settings', count($waveSettingIds)] : null,
        ]));

        if (! $this->confirm('削除を実行しますか？')) {
            return 0;
        }

        DB::connection('sakemaru')->beginTransaction();

        try {
            if ($queueCount > 0) {
                DB::connection('sakemaru')->table('stock_transfer_queue')
                    ->whereIn('request_id', $requestIds)->delete();
            }
            if (! empty($allocationIds)) {
                DB::connection('sakemaru')->table('wms_shortage_allocations')
                    ->whereIn('id', $allocationIds)->delete();
            }
            if (! empty($shortageIds)) {
                DB::connection('sakemaru')->table('wms_shortages')
                    ->whereIn('id', $shortageIds)->delete();
            }
            if (! empty($tradeIds)) {
                DB::connection('sakemaru')->table('trade_items')
                    ->whereIn('trade_id', $tradeIds)->delete();
                DB::connection('sakemaru')->table('trades')
                    ->whereIn('id', $tradeIds)->delete();
            }
            if (! empty($waveIds)) {
                DB::connection('sakemaru')->table('wms_waves')
                    ->whereIn('id', $waveIds)->delete();
            }
            if (! empty($waveSettingIds)) {
                DB::connection('sakemaru')->table('wms_wave_settings')
                    ->whereIn('id', $waveSettingIds)->delete();
            }

            DB::connection('sakemaru')->commit();
            $this->info('テストデータを削除しました。');

            return 0;
        } catch (\Exception $e) {
            DB::connection('sakemaru')->rollBack();
            $this->error('エラー: ' . $e->getMessage());

            return 1;
        }
    }
}
