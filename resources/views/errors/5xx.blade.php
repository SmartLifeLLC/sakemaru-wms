@php
    $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
    [$title, $message] = match ($statusCode) {
        503 => ['システムを一時的に利用できません', "現在メンテナンス中、または一時的にご利用いただけません。\nしばらくしてから再度お試しください。"],
        default => ['システムエラーが発生しました', "申し訳ありません。サーバー内部で予期せぬエラーが発生しました。\nしばらく時間をおいてから再度お試しいただくか、以下のボタンからご報告ください。"],
    };
@endphp

@include('errors.layouts.status-page', [
    'statusCode' => $statusCode,
    'title' => $title,
    'message' => $message,
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
