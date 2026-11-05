<?php

namespace App\Services;

use App\Models\Sakemaru\ItemContractor;
use App\Services\AutoReplenishment\AutoOrderDisplayStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItemContractorReorderPoint
{
    public function update(ItemContractor $record, array $data): ItemContractor
    {
        $data['safety_stock'] = blank($data['safety_stock'] ?? null) ? 0 : $data['safety_stock'];
        $point = Validator::make($data, ['safety_stock' => 'required|integer|min:0|max:4294967295'], [
            'safety_stock.integer' => '発注点は整数で入力してください。',
            'safety_stock.min' => '発注点は0以上で入力してください。',
            'safety_stock.max' => '発注点は4,294,967,295以下で入力してください。',
        ], ['safety_stock' => '発注点'])->validate()['safety_stock'];

        $db = $record->getConnection();

        return $db->transaction(function () use ($db, $record, $data, $point) {
            // Policy and warehouse changes take this same lock before changing state.
            if ($db->getSchemaBuilder()->hasTable('wms_auto_order_controls')) {
                $db->table('wms_auto_order_controls')->where('client_id', $record->client_id)->lockForUpdate()->first();
            }
            $current = app(AutoOrderDisplayStatus::class)->withItemContractorStatus(ItemContractor::query())
                ->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($current->is_auto_order_active) {
                throw ValidationException::withMessages([
                    'safety_stock' => '自動発注中の商品は、この画面で発注点を変更できません。「商品別発注設定」で変更してください。',
                ]);
            }

            unset($data['is_auto_order_active'], $data['automatic_order_status'], $data['automatic_reorder_point']);
            $current->update(array_replace($data, ['safety_stock' => (int) $point]));

            return $current;
        }, 3);
    }
}
