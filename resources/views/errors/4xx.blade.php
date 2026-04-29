@php
    $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400;
    $title = sprintf(
        '%d %s',
        $statusCode,
        \Symfony\Component\HttpFoundation\Response::$statusTexts[$statusCode] ?? 'Client Error',
    );
    $message = match ($statusCode) {
        404 => '該当ページが見つかりません。',
        419 => 'ページの有効期限が切れました。再度お試しください。',
        429 => 'アクセスが集中しています。時間をおいて再度お試しください。',
        default => '該当ページの表示中に問題が発生しました。',
    };
@endphp

@include('errors.layouts.status-page', [
    'statusCode' => $statusCode,
    'title' => $title,
    'message' => $message,
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
