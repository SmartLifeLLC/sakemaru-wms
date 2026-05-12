@php
    $summary = $preview['summary'];
    $rows = $preview['rows'];
    $createLots = $preview['create_lots'];
    $pickingChecks = $preview['picking_checks'];
@endphp

<div class="space-y-4 text-sm text-slate-700 dark:text-gray-200">
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-slate-500 dark:text-gray-400">差分件数</div>
            <div class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ number_format($summary['rows']) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-slate-500 dark:text-gray-400">既存ロット更新</div>
            <div class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ number_format($summary['update_lot']) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-slate-500 dark:text-gray-400">新規ロット作成</div>
            <div class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ number_format($summary['create_lot']) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-slate-500 dark:text-gray-400">差分絶対値</div>
            <div class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ number_format($summary['current_abs_delta_total']) }}</div>
        </div>
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
        91倉庫限定で、real_stocks を正として ACTIVE ロット合計を同期します。新規ロットは商品別default棚番へ作成し、default棚番がない場合のみZ00へ作成します。実行時には最新差分を再計算します。
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        <div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">
            <div class="text-xs text-slate-500 dark:text-gray-400">EARNING ピッキング中</div>
            <div class="mt-1 font-semibold">{{ number_format($pickingChecks['earnings_picking']) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">
            <div class="text-xs text-slate-500 dark:text-gray-400">WMSタスク PICKING</div>
            <div class="mt-1 font-semibold">{{ number_format($pickingChecks['wms_picking_tasks_picking']) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">
            <div class="text-xs text-slate-500 dark:text-gray-400">EARNING明細 PICKING</div>
            <div class="mt-1 font-semibold">{{ number_format($pickingChecks['wms_picking_item_results_earning_picking']) }}</div>
        </div>
    </div>

    <div>
        <div class="mb-2 font-semibold text-slate-900 dark:text-white">現在の在庫差分</div>
        <div class="max-h-72 overflow-auto rounded-lg border border-slate-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-slate-200 text-xs dark:divide-gray-700">
                <thead class="sticky top-0 bg-slate-100 text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-2 py-2 text-left">商品CD</th>
                        <th class="px-2 py-2 text-left">商品名</th>
                        <th class="px-2 py-2 text-right">real</th>
                        <th class="px-2 py-2 text-right">lot</th>
                        <th class="px-2 py-2 text-right">差分</th>
                        <th class="px-2 py-2 text-left">処理</th>
                        <th class="px-2 py-2 text-left">棚番</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="whitespace-nowrap px-2 py-1 font-mono">{{ $row['item_code'] }}</td>
                            <td class="max-w-80 px-2 py-1">{{ $row['item_name'] }}</td>
                            <td class="px-2 py-1 text-right">{{ number_format($row['real_stock_current_quantity']) }}</td>
                            <td class="px-2 py-1 text-right">{{ number_format($row['lot_current_quantity']) }}</td>
                            <td class="px-2 py-1 text-right font-semibold">{{ number_format($row['current_delta']) }}</td>
                            <td class="whitespace-nowrap px-2 py-1">{{ $row['action'] === 'create_lot' ? '新規作成' : '既存更新' }}</td>
                            <td class="whitespace-nowrap px-2 py-1 font-mono">{{ $row['action'] === 'create_lot' ? $row['default_location_display'] : '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-slate-400 dark:text-gray-500">在庫差分はありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="mb-2 font-semibold text-slate-900 dark:text-white">新規作成ロット</div>
        <div class="max-h-56 overflow-auto rounded-lg border border-slate-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-slate-200 text-xs dark:divide-gray-700">
                <thead class="sticky top-0 bg-slate-100 text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-2 py-2 text-left">商品CD</th>
                        <th class="px-2 py-2 text-left">商品名</th>
                        <th class="px-2 py-2 text-right">作成数量</th>
                        <th class="px-2 py-2 text-right">予約数</th>
                        <th class="px-2 py-2 text-left">作成棚番</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                    @forelse ($createLots as $row)
                        <tr>
                            <td class="whitespace-nowrap px-2 py-1 font-mono">{{ $row['item_code'] }}</td>
                            <td class="max-w-96 px-2 py-1">{{ $row['item_name'] }}</td>
                            <td class="px-2 py-1 text-right font-semibold">{{ number_format($row['real_stock_current_quantity']) }}</td>
                            <td class="px-2 py-1 text-right">{{ number_format($row['real_stock_reserved_quantity']) }}</td>
                            <td class="whitespace-nowrap px-2 py-1 font-mono">{{ $row['default_location_display'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-slate-400 dark:text-gray-500">新規作成ロットはありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
