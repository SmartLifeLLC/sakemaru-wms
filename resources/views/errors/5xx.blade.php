@php
    $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
    $title = sprintf(
        '%d %s',
        $statusCode,
        \Symfony\Component\HttpFoundation\Response::$statusTexts[$statusCode] ?? 'Server Error',
    );
@endphp

@include('errors.layouts.status-page', [
    'statusCode' => $statusCode,
    'title' => $title,
    'message' => 'ページの表示中に問題が発生しました。しばらくしてから再度お試しください。',
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
