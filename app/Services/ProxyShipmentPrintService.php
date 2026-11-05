<?php

namespace App\Services;

use App\Enums\PrintType;
use App\Models\Sakemaru\LogPdfExport;
use App\Services\Shortage\ProxyShipmentTransferResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProxyShipmentPrintService
{
    public function authorizedRequest(string $uuid): object
    {
        abort_unless(auth()->check() && \App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource::canViewAny(), 403);
        foreach (['sakemaru.stock-transfers.view', 'sakemaru.stock-transfers.print'] as $permission) {
            abort_unless(app(\Sakemaru\Auth\Services\PermissionService::class)->check(auth()->user(), $permission), 403);
        }
        $request = DB::connection('sakemaru')->table('wms_proxy_shipment_print_requests')->where('uuid', $uuid)
            ->where('client_id', config('app.client_id'))->where('requested_by', auth()->id())->first();
        abort_unless($request, 404);
        abort_if(now()->greaterThan($request->expires_at), 410, '印刷受付の期限が切れています。一覧から選び直してください。');
        return $request;
    }

    public function validateItems(object $request): array
    {
        $items = DB::connection('sakemaru')->table('wms_proxy_shipment_print_request_items')->where('request_id', $request->id)->get();
        abort_if($items->isEmpty(), 409, '印刷対象がありません。');
        $ids = [];
        foreach ($items as $item) {
            $current = app(ProxyShipmentTransferResolver::class)->resolve((int) $item->allocation_id, (int) $request->client_id);
            abort_unless((int) $current['stock_transfer_queue_id'] === (int) $item->stock_transfer_queue_id
                && (int) $current['stock_transfer_id'] === (int) $item->stock_transfer_id
                && hash_equals($item->snapshot_hash, $current['snapshot_hash']), 409,
                '倉庫移動伝票が変更されています。一覧を更新して選び直してください。');
            $ids[] = (int) $item->stock_transfer_id;
        }
        return array_values(array_unique($ids));
    }

    public function start(string $uuid, bool $retry = false, ?int $expectedAttempt = null): object
    {
        $authorized = $this->authorizedRequest($uuid);
        $attempt = DB::connection('sakemaru')->transaction(function () use ($authorized, $retry, $expectedAttempt) {
            $request = DB::connection('sakemaru')->table('wms_proxy_shipment_print_requests')->where('id', $authorized->id)->lockForUpdate()->first();
            $old = DB::connection('sakemaru')->table('wms_proxy_shipment_print_attempts')->where('request_id', $request->id)
                ->where('attempt', $request->current_attempt)->lockForUpdate()->first();
            if ($old && ! $retry) {
                return $old;
            }
            if ($retry) {
                abort_unless($expectedAttempt !== null && $expectedAttempt > 0 && $old
                    && $expectedAttempt <= (int) $old->attempt, 409, '再生成するPDFの受付番号を確認してください。');
                // A retry response may be lost after commit. Reuse its accepted successor,
                // including a failed successor, instead of creating another attempt.
                if ($expectedAttempt < (int) $old->attempt) {
                    return $old;
                }
            }
            abort_if($retry && (! $old || $old->status !== 'failed'), 409, '処理中のPDFは再生成できません。');
            $ids = $this->validateItems($request);
            $number = $request->current_attempt + 1;
            $log = LogPdfExport::create([
                'client_id' => $request->client_id, 'creator_id' => $request->requested_by,
                'type' => PrintType::STOCK_TRANSFER_CHECK->value, 'name' => '倉庫移動チェックリスト',
                'path' => 'data/pdf/'.now()->format('Ymd').'/'.Str::uuid().'/stock-transfer.pdf',
            ]);
            foreach ($ids as $id) {
                DB::connection('sakemaru')->table('pdf_exportables')->insert(['log_pdf_export_id' => $log->id,
                    'pdf_exportable_type' => 'App\\Models\\StockTransfer', 'pdf_exportable_id' => $id]);
            }
            $id = DB::connection('sakemaru')->table('wms_proxy_shipment_print_attempts')->insertGetId([
                'request_id' => $request->id, 'attempt' => $number, 'log_pdf_export_id' => $log->id,
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::connection('sakemaru')->table('wms_proxy_shipment_print_requests')->where('id', $request->id)
                ->update(['current_attempt' => $number, 'updated_at' => now()]);
            return DB::connection('sakemaru')->table('wms_proxy_shipment_print_attempts')->find($id);
        });
        return $attempt;
    }

    public function attempt(string $uuid, int $number): array
    {
        $request = $this->authorizedRequest($uuid);
        $attempt = DB::connection('sakemaru')->table('wms_proxy_shipment_print_attempts')->where('request_id', $request->id)->where('attempt', $number)->first();
        abort_unless($attempt, 404);
        $this->validateItems($request);
        $log = LogPdfExport::where('client_id', $request->client_id)->where('creator_id', $request->requested_by)
            ->where('type', PrintType::STOCK_TRANSFER_CHECK->value)->findOrFail($attempt->log_pdf_export_id);
        return [$request, $attempt, $log];
    }
}
