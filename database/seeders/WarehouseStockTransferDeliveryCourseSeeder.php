<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarehouseStockTransferDeliveryCourseSeeder extends Seeder
{
    /**
     * 倉庫間移動の配送コース設定を投入
     *
     * データ形式: [配送コースコード, 移動元倉庫コード, 移動先倉庫コード]
     */
    public function run(): void
    {
        $mappings = [
            // [delivery_course_code, from_warehouse_code, to_warehouse_code]
            [919203, 91, 3],   // [華むすびの蔵]店舗（坂井店）
            [919222, 91, 22],  // [華むすびの蔵]店舗（小浜店）
            [919202, 91, 2],   // [華むすびの蔵]店舗（二の宮店）
            [919209, 91, 9],   // [華むすびの蔵]店舗（ヴィオ店）
            [919205, 91, 5],   // [華むすびの蔵]店舗（武生店）
            [919201, 91, 1],   // [華むすびの蔵]店舗（本店）
            [919207, 91, 7],   // [華むすびの蔵]店舗（光陽店）
            [919210, 91, 10],  // [華むすびの蔵]店舗（敦賀店）
            [919221, 91, 21],  // [華むすびの蔵]店舗（江守店）
            [919206, 91, 6],   // [華むすびの蔵]店舗（鯖江店）
            [919208, 91, 8],   // [華むすびの蔵]店舗（プラザ店）
            [919204, 91, 4],   // [華むすびの蔵]店舗（ＳＤ前店）
            [919211, 91, 11],  // [華むすびの蔵]店舗（越前店）
            [919997, 91, 97],  // [華むすびの蔵]店舗大口
            [919223, 91, 23],  // [華むすびの蔵]店舗（金沢店）
            [19207, 1, 7],     // [本店]店舗（光陽店）
        ];

        $conn = DB::connection('sakemaru');

        // コードからIDを引くためのマップを構築
        $warehouseCodes = collect($mappings)->flatMap(fn ($m) => [$m[1], $m[2]])->unique()->values()->toArray();
        $warehouseMap = $conn->table('warehouses')
            ->whereIn('code', $warehouseCodes)
            ->pluck('id', 'code');

        $courseCodes = collect($mappings)->pluck(0)->unique()->values()->toArray();
        $courseMap = $conn->table('delivery_courses')
            ->whereIn('code', $courseCodes)
            ->pluck('id', 'code');

        $now = now();
        $insertData = [];

        foreach ($mappings as $mapping) {
            [$courseCode, $fromCode, $toCode] = $mapping;

            $fromId = $warehouseMap[$fromCode] ?? null;
            $toId = $warehouseMap[$toCode] ?? null;
            $courseId = $courseMap[$courseCode] ?? null;

            if (! $fromId || ! $toId || ! $courseId) {
                $this->command->warn("Skipped: course={$courseCode}, from={$fromCode}, to={$toCode} (ID not found)");

                continue;
            }

            $insertData[] = [
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'delivery_course_id' => $courseId,
                'picking_lead_days' => 0,
                'creator_id' => 0,
                'last_updater_id' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($insertData)) {
            $this->command->warn('No records to insert.');

            return;
        }

        // 重複を避けてupsert
        $conn->table('warehouse_stock_transfer_delivery_courses')
            ->upsert(
                $insertData,
                ['from_warehouse_id', 'to_warehouse_id'],
                ['delivery_course_id', 'last_updater_id', 'updated_at']
            );

        $this->command->info('Inserted/updated '.count($insertData).' warehouse stock transfer delivery course records.');
    }
}
