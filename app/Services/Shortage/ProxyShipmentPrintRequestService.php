<?php

namespace App\Services\Shortage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProxyShipmentPrintRequestService
{
    public function create(array $ids, int $clientId, int $userId, string $operationKey, string $copyType = 'both'): string
    {
        if (! in_array($copyType, ['both', 'outbound'], true)) {
            throw ValidationException::withMessages(['copy_type' => '印刷する帳票を選択してください。']);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if (! $ids || ! Str::isUuid($operationKey)) {
            throw ValidationException::withMessages(['allocation_ids' => '印刷する完了済みの依頼を選択してください。']);
        }
        $hash = hash('sha256', json_encode($ids));
        $db = DB::connection('sakemaru');

        return $db->transaction(function () use ($db, $ids, $clientId, $userId, $operationKey, $hash, $copyType) {
            $uuid = (string) Str::uuid();
            // Existing UNIQUE constraint serializes concurrent retries of the same operation.
            $db->table('wms_proxy_shipment_print_requests')->insertOrIgnore([
                'uuid' => $uuid, 'client_id' => $clientId, 'requested_by' => $userId,
                'idempotency_key' => $operationKey, 'selection_hash' => $hash, 'copy_type' => $copyType,
                'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $request = $db->table('wms_proxy_shipment_print_requests')->where('client_id', $clientId)
                ->where('requested_by', $userId)->where('idempotency_key', $operationKey)->lockForUpdate()->first();
            if (! $request || $request->selection_hash !== $hash || $request->copy_type !== $copyType || now()->greaterThan($request->expires_at)) {
                throw ValidationException::withMessages(['allocation_ids' => '印刷対象が変更されたか受付期限が切れました。選び直してください。']);
            }
            if ($request->uuid === $uuid) {
                foreach ($ids as $id) {
                    $resolved = app(ProxyShipmentTransferResolver::class)->resolve($id, $clientId);
                    unset($resolved['serial_id']);
                    $db->table('wms_proxy_shipment_print_request_items')->insert(['request_id' => $request->id] + $resolved);
                }
            }

            return route('proxy-shipment-print.show', $request->uuid);
        });
    }
}
