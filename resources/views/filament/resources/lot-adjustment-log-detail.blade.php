@php
    $typeLabels = [
        'OFFSET' => ['label' => '相殺', 'class' => 'bg-blue-100 text-blue-700'],
        'REACTIVATE' => ['label' => '再ACTIVE化', 'class' => 'bg-green-100 text-green-700'],
        'REPOINT' => ['label' => 'STLA修正', 'class' => 'bg-indigo-100 text-indigo-700'],
        'SYNC_DETECTED' => ['label' => '不一致(検出のみ)', 'class' => 'bg-amber-100 text-amber-700'],
        'SKIP' => ['label' => 'スキップ', 'class' => 'bg-gray-100 text-gray-600'],
        'LOCATION_ABORTED' => ['label' => '棚番保護で中止', 'class' => 'bg-red-100 text-red-700'],
    ];
    $details = $record->details ?? [];
    $summary = $record->summary ?? [];
    $maxRows = 500;
@endphp

<div class="space-y-3 text-sm">
    <div class="text-xs text-gray-500">
        run: {{ $record->run_uuid }} ／ {{ $record->mode === 'APPLIED' ? '実行' : 'プレビュー' }}
        ／ 倉庫ID {{ $record->warehouse_id }} ／ 適用 {{ $record->affected_count }} 件
    </div>

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
        @foreach (['offset' => '相殺', 'reactivate' => '再ACTIVE化', 'repoint' => 'STLA修正', 'sync_detected' => '不一致検出', 'skipped' => 'スキップ', 'location_aborted' => '棚番中止'] as $key => $jp)
            <div class="rounded border border-gray-200 p-2 text-center dark:border-gray-700">
                <div class="text-xs text-gray-500">{{ $jp }}</div>
                <div class="text-lg font-bold">{{ $summary[$key] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    @if (count($details) > $maxRows)
        <div class="text-xs text-amber-600">表示は先頭 {{ $maxRows }} 件です。</div>
    @endif

    <div class="max-h-[60vh] overflow-auto">
        <table class="w-full text-left text-xs">
            <thead class="sticky top-0 border-b border-gray-200 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-900">
                <tr>
                    <th class="px-2 py-1">種別</th>
                    <th class="px-2 py-1">real_stock</th>
                    <th class="px-2 py-1">LOT / STLA</th>
                    <th class="px-2 py-1">current 前→後</th>
                    <th class="px-2 py-1">status 前→後</th>
                    <th class="px-2 py-1">棚番ID</th>
                    <th class="px-2 py-1">理由</th>
                </tr>
            </thead>
            <tbody>
                @foreach (array_slice($details, 0, $maxRows) as $d)
                    @php $t = $typeLabels[$d['type'] ?? 'SKIP'] ?? ['label' => $d['type'] ?? '', 'class' => 'bg-gray-100 text-gray-600']; @endphp
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-2 py-1"><span class="rounded px-1.5 py-0.5 {{ $t['class'] }}">{{ $t['label'] }}</span></td>
                        <td class="px-2 py-1">{{ $d['real_stock_id'] ?? '-' }}</td>
                        <td class="px-2 py-1">
                            @if (!empty($d['stla_id']))
                                stla:{{ $d['stla_id'] }}<span class="text-gray-400"> ({{ $d['old_lot_id'] ?? '?' }}→{{ $d['new_lot_id'] ?? '?' }})</span>
                            @else
                                {{ $d['lot_id'] ?? '-' }}
                            @endif
                        </td>
                        <td class="px-2 py-1">{{ array_key_exists('current_before', $d) ? ($d['current_before'].' → '.$d['current_after']) : '-' }}</td>
                        <td class="px-2 py-1 text-gray-500">{{ $d['status_before'] ?? '' }}{{ isset($d['status_after']) ? ' → '.$d['status_after'] : '' }}</td>
                        <td class="px-2 py-1">{{ $d['location_id'] ?? '' }}</td>
                        <td class="px-2 py-1 text-gray-500">{{ $d['reason'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
