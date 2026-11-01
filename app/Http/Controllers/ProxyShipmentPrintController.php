<?php

namespace App\Http\Controllers;

use App\Exceptions\PdfExtractionUnavailableException;
use App\Models\Sakemaru\LogPdfExport;
use App\Services\ProxyShipmentPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProxyShipmentPrintController extends Controller
{
    public function __construct(private ProxyShipmentPrintService $service) {}

    public function show(string $uuid)
    {
        $request = $this->service->authorizedRequest($uuid);
        $items = DB::connection('sakemaru')->table('wms_proxy_shipment_print_request_items')->where('request_id', $request->id)->get();

        return view('proxy-shipment-print', ['printRequest' => $request, 'allocationCount' => $items->count(),
            'transferCount' => $items->pluck('stock_transfer_id')->unique()->count()]);
    }

    public function start(string $uuid)
    {
        return response()->json(['attempt' => $this->service->start($uuid)->attempt])->header('Cache-Control', 'no-store');
    }

    public function retry(Request $request, string $uuid)
    {
        $data = $request->validate(['attempt' => 'required|integer|min:1']);

        return response()->json(['attempt' => $this->service->start($uuid, true, (int) $data['attempt'])->attempt])->header('Cache-Control', 'no-store');
    }

    private function context(Request $request, string $uuid): array
    {
        $data = $request->validate(['attempt' => 'required|integer|min:1']);

        return $this->service->attempt($uuid, (int) $data['attempt']);
    }

    public function status(Request $request, string $uuid)
    {
        [, $attempt] = $this->context($request, $uuid);

        return response()->json(['status' => $attempt->status, 'message' => $attempt->error_message])->header('Cache-Control', 'no-store');
    }

    public function file(Request $request, string $uuid)
    {
        [$printRequest, $attempt, $log] = $this->context($request, $uuid);
        abort_unless($attempt->status === 'ready' && $log->prepared_at, 409, 'PDFを生成中です。');
        try {
            $pdf = Storage::disk('core_pdf')->getDriver()->read($log->path);
        } catch (\League\Flysystem\UnableToReadFile $exception) {
            report($exception);

            return response()->json(['message' => 'PDF保存先から取得できません。PDF取得を再試行してください。'], 503);
        }
        abort_unless(is_string($pdf) && str_starts_with($pdf, '%PDF-'), 503, 'PDFを取得できませんでした。');
        if ($printRequest->copy_type === 'outbound') {
            $serials = DB::connection('sakemaru')->table('wms_proxy_shipment_print_request_items as i')
                ->join('stock_transfers as st', 'st.id', '=', 'i.stock_transfer_id')
                ->join('trades as t', 't.id', '=', 'st.trade_id')->where('i.request_id', $printRequest->id)
                ->orderBy('t.serial_id')->orderBy('st.id')->select('st.id', 't.serial_id')->distinct()->get()->pluck('serial_id')->map(fn ($id) => (string) $id)->all();
            try {
                $pdf = app(\App\Services\Print\ProxyShipmentOutboundPdfService::class)->extract($pdf, $serials);
            } catch (PdfExtractionUnavailableException $exception) {
                report($exception);

                return response()->json(['message' => '出庫用の印刷機能を利用できません。管理者に連絡してください。移動伝票の再作成や一覧からの選び直しは不要です。'], 503);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json(['message' => '出庫用ページを確認できませんでした。一覧から選び直してください。'], 422);
            }
        }
        $fileName = $printRequest->copy_type === 'outbound' ? 'stock-transfer-outbound.pdf' : 'stock-transfer.pdf';

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store', 'Content-Disposition' => 'inline; filename="'.$fileName.'"']);
    }

    public function complete(Request $request, string $uuid)
    {
        [, $attempt, $log] = $this->context($request, $uuid);
        abort_unless($attempt->status === 'ready' && $log->prepared_at, 409);
        DB::connection('sakemaru')->transaction(function () use ($attempt, $log) {
            $locked = LogPdfExport::whereKey($log->id)->lockForUpdate()->firstOrFail();
            if (! $locked->exported_at) {
                $locked->update(['exported_at' => now(), 'exported_count' => $locked->exported_count + 1]);
                DB::connection('sakemaru')->table('wms_proxy_shipment_print_attempts')->where('id', $attempt->id)->update(['downloaded_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->noContent();
    }
}
