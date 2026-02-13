{{ $contractor?->name ?? '発注先' }} 様

いつもお世話になっております。
{{ $warehouse?->name ?? '倉庫' }}より発注データをお送りいたします。

■ 発注情報
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
発注日　　　：{{ $orderDate }}
入荷予定日　：{{ $expectedArrivalDate }}
発注件数　　：{{ number_format($orderCount) }}件
合計数量　　：{{ number_format($totalQuantity) }}

■ 添付ファイル
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
@if($attachCsv)
・発注データ（CSV形式）
@endif
@if($attachFax)
・発注書（PDF形式）
@endif

ご確認のほど、よろしくお願いいたします。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
※このメールはシステムから自動送信されています。
