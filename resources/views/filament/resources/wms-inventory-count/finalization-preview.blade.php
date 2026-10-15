@php($preview = $getState() ?? [])
<dl class="grid grid-cols-2 gap-2 text-sm text-gray-900 dark:text-gray-100">
    <dt>元の差異明細</dt><dd class="text-right">{{ number_format($preview['detail_count'] ?? 0) }}件</dd>
    <dt>伝票対象商品（棚番合算後）</dt><dd class="text-right">{{ number_format($preview['voucher_item_count'] ?? 0) }}件</dd>
    <dt>うち未入力を0として調節</dt><dd class="text-right">{{ number_format($preview['uncounted_count'] ?? 0) }}件</dd>
    <dt>増加数量（バラ）</dt><dd class="text-right">{{ number_format($preview['increase_quantity'] ?? 0) }}</dd>
    <dt>減少数量（バラ）</dt><dd class="text-right">{{ number_format($preview['decrease_quantity'] ?? 0) }}</dd>
</dl>
