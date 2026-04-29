@include('errors.layouts.status-page', [
    'statusCode' => 429,
    'title' => 'アクセスが集中しています',
    'message' => "ただいまアクセスが集中しています。\nしばらく時間をおいてから再度お試しください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
