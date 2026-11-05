<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRunLine;
use Illuminate\Support\Facades\DB;

class ArtifactRecorder
{
    public function link(WmsAutoOrderRunLine $line, string $type, int $id, array $snapshot): void
    {
        DB::connection('sakemaru')->table('wms_auto_order_artifact_links')->insertOrIgnore([
            'line_id' => $line->id, 'artifact_type' => $type, 'artifact_id' => $id,
            'business_number' => $snapshot['slip_number'] ?? $snapshot['jx_document_no'] ?? null,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
