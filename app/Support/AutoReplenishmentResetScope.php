<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;

/** Shared DB contract with Core: use the warehouse IDs frozen during initial reset. */
final class AutoReplenishmentResetScope
{
    public static function preservedWarehouseIds(ConnectionInterface $db, object $control): array
    {
        if (empty($control->release_key)) {
            throw new \RuntimeException('数量設定を保持する倉庫を確認できません。初期化時の保持対象を管理者に確認してください。');
        }

        $payload = $db->table('wms_auto_order_events')->where('client_id', $control->client_id)
            ->where('event', 'RESET_SCOPE_FIXED')->where('payload->release_key', $control->release_key)
            ->orderByDesc('id')->value('payload');
        try {
            $scope = json_decode($payload ?? '', true, 512, JSON_THROW_ON_ERROR);
            $ids = $scope['preserved_warehouse_ids'] ?? null;
            if (! is_array($ids) || ! array_is_list($ids)) {
                throw new \RuntimeException;
            }
            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new \RuntimeException;
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('数量設定を保持する倉庫を確認できません。初期化時の保持対象を管理者に確認してください。', 0, $e);
        }

        return $ids;
    }
}
