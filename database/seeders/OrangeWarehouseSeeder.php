<?php

namespace Database\Seeders;

use App\Enums\AutoOrder\ConfirmationLevel;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsWarehouseAutoOrderSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * オレンジ冷凍倉庫（code=901）の新設シーダー
 *
 * 倉庫91（華むすびの蔵センター）をベースにオレンジ冷凍倉庫を作成し、
 * 関連するコントラクタ・サプライヤー・WMS設定を一括で登録する。
 *
 * 実行方法:
 *   php artisan db:seed --class=OrangeWarehouseSeeder
 */
class OrangeWarehouseSeeder extends Seeder
{
    private const ORANGE_WAREHOUSE_CODE = '101';
    private const ORANGE_BRANCH_CODE = '101';

    private const ORANGE_CONTRACTOR_ID = 9901;

    private const BASE_WAREHOUSE_CODE = '91';

    private const AKUTO_CONTRACTOR_ID = 1497;

    public function run(): void
    {
        $this->command->info('オレンジ冷凍倉庫セットアップを開始します...');

        // Step 1: 倉庫レコード作成
        $orangeWarehouse = $this->createWarehouse();
        if (! $orangeWarehouse) {
            return;
        }

        // Step 2: コントラクタレコード作成
        $this->createContractor();

        // Step 3: サプライヤーレコード作成
        $this->createSupplier();

        // Step 4: wms_contractor_settings（オレンジINTERNAL）
        $this->createContractorSetting($orangeWarehouse->id);

        // Step 5: wms_contractor_settings（アクト中食1497更新）
        $this->updateAkutoContractorSetting();

        // Step 6: wms_warehouse_auto_order_settings
        $this->createAutoOrderSetting($orangeWarehouse->id);

        // Step 7: 配送コース＆倉庫間移動配送コース設定
        $this->createDeliveryCoursesAndMappings($orangeWarehouse->id);

        $this->command->info('オレンジ冷凍倉庫セットアップが完了しました');
    }

    private function createWarehouse(): ?Warehouse
    {
        if (Warehouse::where('code', self::ORANGE_WAREHOUSE_CODE)->exists()) {
            $this->command->info('  [SKIP] オレンジ冷凍倉庫は既に存在します');

            return Warehouse::where('code', self::ORANGE_WAREHOUSE_CODE)->first();
        }

        $warehouse91 = Warehouse::where('code', self::BASE_WAREHOUSE_CODE)->first();
        if (! $warehouse91) {
            $this->command->error('  [ERROR] 倉庫91が見つかりません');

            return null;
        }

        $orange = $warehouse91->replicate(['is_virtual']);
        $orange->id = self::ORANGE_WAREHOUSE_CODE;
        $orange->code = self::ORANGE_WAREHOUSE_CODE;
        $orange->name = 'オレンジ冷凍倉庫';
        $orange->kana_name = 'オレンジソウコ';
        $orange->abbreviation = 'オレンジ';
        $orange->branch_id = self::ORANGE_BRANCH_CODE;
        $orange->save();

        $this->command->info("  [OK] 倉庫作成: id={$orange->id}, code=901, name=オレンジ冷凍倉庫");

        return $orange;
    }

    private function createContractor(): void
    {
        $exists = DB::connection('sakemaru')
            ->table('contractors')
            ->where('id', self::ORANGE_CONTRACTOR_ID)
            ->exists();

        if ($exists) {
            $this->command->info('  [SKIP] コントラクタ9901は既に存在します');

            return;
        }

        DB::connection('sakemaru')->table('contractors')->insert([
            'id' => self::ORANGE_CONTRACTOR_ID,
            'client_id' => 6,
            'code' => '9901',
            'name' => 'LW華本部 物流課（オレンジ）',
            'kana_name' => 'ｵﾚﾝｼﾞ',
            'postal_code' => '918-8231',
            'address1' => '福井市問屋町2丁目35番地',
            'tel' => '0776-24-1160',
            'fax' => '0776-24-1161',
            'is_auto_change_order' => true,
            'delivery_type' => 'ARRIVAL',
            'supplier_id' => self::ORANGE_CONTRACTOR_ID,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('  [OK] コントラクタ作成: id=9901, name=LW華本部 物流課（オレンジ）');
    }

    private function createSupplier(): void
    {
        $exists = DB::connection('sakemaru')
            ->table('suppliers')
            ->where('id', self::ORANGE_CONTRACTOR_ID)
            ->exists();

        if ($exists) {
            $this->command->info('  [SKIP] サプライヤー9901は既に存在します');

            return;
        }

        DB::connection('sakemaru')->table('suppliers')->insert([
            'id' => self::ORANGE_CONTRACTOR_ID,
            'client_id' => 6,
            'partner_id' => self::ORANGE_CONTRACTOR_ID,
            'partner_category' => 'SUPPLIER',
            'delivery_price_payer' => 'PARTNER',
            'payee_bank_type' => 'SAME_BANK_SAME_BRANCH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('  [OK] サプライヤー作成: id=9901');
    }

    private function createContractorSetting(int $orangeWarehouseId): void
    {
        WmsContractorSetting::updateOrCreate(
            ['contractor_id' => self::ORANGE_CONTRACTOR_ID],
            [
                'transmission_type' => TransmissionType::INTERNAL,
                'supply_warehouse_id' => $orangeWarehouseId,
                'transmission_time' => '10:30',
                'auto_order_generation_time' => '09:30',
                'is_transmission_sun' => true,
                'is_transmission_mon' => true,
                'is_transmission_tue' => true,
                'is_transmission_wed' => true,
                'is_transmission_thu' => true,
                'is_transmission_fri' => true,
                'is_transmission_sat' => true,
                'is_auto_transmission' => false,
                'is_receive_enabled' => false,
            ]
        );

        $this->command->info("  [OK] wms_contractor_settings: contractor_id=9901, INTERNAL, supply_warehouse_id={$orangeWarehouseId}");
    }

    private function updateAkutoContractorSetting(): void
    {
        $updated = WmsContractorSetting::where('contractor_id', self::AKUTO_CONTRACTOR_ID)
            ->update([
                'is_receive_enabled' => true,
                'receive_format' => 'CSV',
            ]);

        if ($updated) {
            $this->command->info('  [OK] wms_contractor_settings: contractor_id=1497 → receive_format=CSV, is_receive_enabled=true');
        } else {
            $this->command->warn('  [WARN] contractor_id=1497 の wms_contractor_settings が見つかりません');
        }
    }

    /**
     * 倉庫101用の配送コースを作成し、倉庫間移動配送コース設定に紐づける
     *
     * 倉庫91のパターン（919201=本店, 919202=二の宮店...）に倣い、
     * 倉庫101用コード（10190XX）で各サテライト倉庫への配送コースを作成
     */
    private function createDeliveryCoursesAndMappings(int $orangeWarehouseId): void
    {
        $conn = DB::connection('sakemaru');

        // 倉庫91の既存マッピングを参照して、同じサテライト倉庫に対して101用を作成
        $existingMappings = $conn->table('warehouse_stock_transfer_delivery_courses as wstdc')
            ->join('delivery_courses as dc', 'wstdc.delivery_course_id', '=', 'dc.id')
            ->join('warehouses as tw', 'wstdc.to_warehouse_id', '=', 'tw.id')
            ->where('wstdc.from_warehouse_id', 91)
            ->select('wstdc.to_warehouse_id', 'tw.code as to_warehouse_code', 'tw.name as to_warehouse_name', 'dc.code as base_course_code', 'wstdc.picking_lead_days')
            ->get();

        if ($existingMappings->isEmpty()) {
            $this->command->warn('  [WARN] 倉庫91の移動配送コース設定が見つかりません');

            return;
        }

        $now = now();
        $created = 0;

        foreach ($existingMappings as $mapping) {
            // コース命名規則: 101 + 倉庫91コースの下4桁（例: 919201 → 1019201）
            $newCourseCode = (int) ('101'.substr((string) $mapping->base_course_code, -4));
            $newCourseName = '[オレンジ]店舗（'.$mapping->to_warehouse_name.'）';

            // 配送コースが既に存在するかチェック
            $existingCourse = $conn->table('delivery_courses')
                ->where('code', $newCourseCode)
                ->where('warehouse_id', $orangeWarehouseId)
                ->first();

            if ($existingCourse) {
                $courseId = $existingCourse->id;
            } else {
                $courseId = $conn->table('delivery_courses')->insertGetId([
                    'client_id' => 6,
                    'code' => $newCourseCode,
                    'name' => $newCourseName,
                    'warehouse_id' => $orangeWarehouseId,
                    'is_active' => true,
                    'creator_id' => 0,
                    'last_updater_id' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->command->info("  [OK] 配送コース作成: code={$newCourseCode}, name={$newCourseName}");
            }

            // 倉庫間移動配送コース設定をupsert
            $conn->table('warehouse_stock_transfer_delivery_courses')->upsert(
                [
                    'from_warehouse_id' => $orangeWarehouseId,
                    'to_warehouse_id' => $mapping->to_warehouse_id,
                    'delivery_course_id' => $courseId,
                    'picking_lead_days' => $mapping->picking_lead_days,
                    'creator_id' => 0,
                    'last_updater_id' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['from_warehouse_id', 'to_warehouse_id'],
                ['delivery_course_id', 'last_updater_id', 'updated_at']
            );

            $created++;
        }

        $this->command->info("  [OK] 配送コース＆マッピング: {$created}件作成/更新");
    }

    private function createAutoOrderSetting(int $orangeWarehouseId): void
    {
        WmsWarehouseAutoOrderSetting::updateOrCreate(
            ['warehouse_id' => $orangeWarehouseId],
            [
                'is_auto_order_enabled' => true,
                'exclude_sunday_arrival' => true,
                'exclude_holiday_arrival' => true,
                'confirmation_level' => ConfirmationLevel::STATUS1,
            ]
        );

        $this->command->info("  [OK] wms_warehouse_auto_order_settings: warehouse_id={$orangeWarehouseId}, STATUS2");
    }
}
