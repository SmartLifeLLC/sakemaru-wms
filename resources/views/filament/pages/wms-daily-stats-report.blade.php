<x-filament-panels::page>
    @php
        $formatNumber = fn ($value) => number_format((float) $value, (float) $value == (int) $value ? 0 : 1);
        $formatMoney = fn ($value) => '¥' . number_format((float) $value);
        $formatValue = fn ($row) => ($row['money'] ?? false) ? $formatMoney($row['current']) : $formatNumber($row['current']) . $row['unit'];
        $formatCompare = fn ($row) => ($row['money'] ?? false) ? $formatMoney($row['compare']) : $formatNumber($row['compare']) . $row['unit'];
        $formatDelta = fn ($value, $money = false) => ((float) $value > 0 ? '+' : '') . ($money ? $formatMoney($value) : $formatNumber($value));
        $rateClass = fn ($rate) => $rate === null ? 'wds-muted' : ((float) $rate >= 100 ? 'wds-good' : 'wds-danger');
    @endphp

    <style>
        .wds-shell { display: flex; flex-direction: column; gap: 10px; color: #111827; }
        .wds-toolbar { display: flex; align-items: end; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid #cbd5e1; background: #fff; }
        .wds-filters, .wds-actions { display: flex; align-items: end; gap: 10px; flex-wrap: wrap; }
        .wds-field { display: flex; flex-direction: column; gap: 4px; }
        .wds-field label { font-size: 12px; color: #475569; font-weight: 800; }
        .wds-input, .wds-select { height: 34px; border: 1px solid #cbd5e1; background: #fff; padding: 4px 8px; font-size: 13px; min-width: 140px; }
        .wds-select-wide { min-width: 240px; }
        .wds-button { height: 34px; padding: 0 14px; border: 1px solid #94a3b8; background: #f8fafc; color: #0f172a; font-size: 13px; font-weight: 900; }
        .wds-button-primary { border-color: #1d4ed8; background: #2563eb; color: #fff; }
        .wds-button-danger { border-color: #b91c1c; background: #dc2626; color: #fff; }
        .wds-message { padding: 8px 10px; border: 1px solid #bbf7d0; background: #f0fdf4; color: #047857; font-size: 13px; font-weight: 800; }
        .wds-error { padding: 8px 10px; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; font-size: 13px; font-weight: 800; }
        .wds-cards { display: grid; grid-template-columns: repeat(6, minmax(150px, 1fr)); gap: 8px; }
        .wds-card { border: 1px solid #cbd5e1; background: #fff; padding: 10px 12px; min-height: 92px; }
        .wds-card-label { font-size: 11px; color: #64748b; font-weight: 800; }
        .wds-card-value { margin-top: 4px; font-size: 22px; line-height: 1.1; font-weight: 950; color: #0f172a; }
        .wds-card-sub { margin-top: 8px; font-size: 12px; font-weight: 800; color: #64748b; }
        .wds-grid { display: grid; grid-template-columns: minmax(420px, 0.95fr) minmax(520px, 1.05fr); gap: 10px; }
        .wds-panel { border: 1px solid #cbd5e1; background: #fff; }
        .wds-panel-title { padding: 8px 10px; border-bottom: 1px solid #cbd5e1; background: #f8fafc; font-size: 13px; font-weight: 900; color: #334155; }
        .wds-bars { padding: 10px; display: flex; flex-direction: column; gap: 10px; }
        .wds-bar-row { display: grid; grid-template-columns: 130px 1fr 116px; gap: 10px; align-items: center; font-size: 12px; }
        .wds-bar-label { font-weight: 900; color: #334155; }
        .wds-bar-track { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .wds-bar { height: 10px; min-width: 2px; }
        .wds-bar-current { background: #2563eb; }
        .wds-bar-compare { background: #94a3b8; }
        .wds-bar-values { text-align: right; font-weight: 800; color: #475569; }
        .wds-table-wrap { overflow: auto; max-height: calc(100vh - 430px); border-top: 1px solid #cbd5e1; }
        .wds-table { width: 100%; min-width: 920px; border-collapse: separate; border-spacing: 0; font-size: 12px; }
        .wds-table th, .wds-table td { border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1; padding: 6px 8px; white-space: nowrap; text-align: right; }
        .wds-table th { position: sticky; top: 0; background: #e2e8f0; color: #0f172a; font-weight: 900; z-index: 2; }
        .wds-table td:first-child, .wds-table th:first-child { text-align: left; }
        .wds-good { color: #047857; }
        .wds-danger { color: #b91c1c; }
        .wds-muted { color: #64748b; }
        @media (max-width: 1280px) { .wds-cards { grid-template-columns: repeat(3, minmax(150px, 1fr)); } .wds-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .wds-cards { grid-template-columns: 1fr; } .wds-toolbar { align-items: stretch; flex-direction: column; } }
    </style>

    <div class="wds-shell">
        <div class="wds-toolbar">
            <div class="wds-filters">
                <div class="wds-field">
                    <label>対象日</label>
                    <input type="date" wire:model="baseDate" class="wds-input">
                </div>
                <div class="wds-field">
                    <label>比較</label>
                    <select wire:model="compareMode" class="wds-select">
                        @foreach ($compareOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wds-field">
                    <label>倉庫</label>
                    <select wire:model="warehouseId" class="wds-select wds-select-wide">
                        @foreach ($warehouses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="wds-actions">
                <button type="button" wire:click="search" class="wds-button wds-button-primary">表示</button>
                <button type="button" wire:click="runAggregate" class="wds-button wds-button-danger">再集計</button>
            </div>
        </div>

        @if ($aggregateMessage)
            <div class="wds-message">{{ $aggregateMessage }}</div>
        @endif
        @if ($aggregateError)
            <div class="wds-error">再集計に失敗しました: {{ $aggregateError }}</div>
        @endif

        <div class="wds-cards">
            @foreach ($cards as $card)
                <div class="wds-card">
                    <div class="wds-card-label">{{ $card['label'] }}</div>
                    <div class="wds-card-value">
                        {{ $card['money'] ? $formatMoney($card['value']) : $formatNumber($card['value']) }}
                    </div>
                    <div class="wds-card-sub">
                        {{ $compareModeLabel }} {{ $card['rate'] === null ? '—' : number_format($card['rate'], 1) . '%' }}
                        <span class="{{ ($card['delta'] ?? 0) >= 0 ? 'wds-good' : 'wds-danger' }}">
                            ({{ $formatDelta($card['delta'], $card['money']) }})
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="wds-grid">
            <div class="wds-panel">
                <div class="wds-panel-title">{{ $baseDateLabel }} / {{ $compareModeLabel }} {{ $compareDateLabel }}</div>
                <div class="wds-bars">
                    @foreach ($comparisonRows as $row)
                        <div class="wds-bar-row">
                            <div class="wds-bar-label">{{ $row['label'] }}</div>
                            <div class="wds-bar-track">
                                <div class="wds-bar wds-bar-current" style="width: {{ $row['currentWidth'] }}%"></div>
                                <div class="wds-bar wds-bar-compare" style="width: {{ $row['compareWidth'] }}%"></div>
                            </div>
                            <div class="wds-bar-values">
                                <div>{{ $formatValue($row) }}</div>
                                <div class="{{ $rateClass($row['rate']) }}">{{ $row['rate'] === null ? '—' : number_format($row['rate'], 1) . '%' }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="wds-panel">
                <div class="wds-panel-title">倉庫別サマリ</div>
                <div class="wds-table-wrap">
                    <table class="wds-table">
                        <thead>
                            <tr>
                                <th>倉庫</th>
                                <th>伝票</th>
                                <th>売上（税抜）</th>
                                <th>{{ $compareModeLabel }}</th>
                                <th>商品項目</th>
                                <th>引当欠品</th>
                                <th>欠品確定</th>
                                <th>顧客</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($warehouseRows as $row)
                                <tr>
                                    <td>{{ $row['warehouse'] }}</td>
                                    <td>{{ $formatNumber($row['total_slip_count']) }}</td>
                                    <td>{{ $formatMoney($row['total_amount_ex']) }}</td>
                                    <td class="{{ $rateClass($row['sales_rate']) }}">{{ $row['sales_rate'] === null ? '—' : number_format($row['sales_rate'], 1) . '%' }}</td>
                                    <td>{{ $formatNumber($row['picking_item_count']) }}</td>
                                    <td>{{ $formatNumber($row['allocation_shortage_qty']) }}</td>
                                    <td>{{ $formatNumber($row['confirmed_shortage_qty']) }}</td>
                                    <td>{{ $formatNumber($row['unique_buyer_count']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="wds-muted">対象データがありません。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
