@php
    $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400;
    [$title, $message] = match ($statusCode) {
        404 => ['ページが見つかりません', "指定されたページが見つかりませんでした。\nURLをご確認のうえ、再度お試しください。"],
        419 => ['ページの有効期限が切れました', "一定時間操作がなかったため、ページの有効期限が切れました。\n再度お試しください。"],
        429 => ['アクセスが集中しています', "ただいまアクセスが集中しています。\nしばらく時間をおいてから再度お試しください。"],
        default => ['エラーが発生しました', "ページの表示中に問題が発生しました。\n時間をおいてから再度お試しください。"],
    };
@endphp

@include('errors.layouts.status-page', [
    'statusCode' => $statusCode,
    'title' => $title,
    'message' => $message,
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
