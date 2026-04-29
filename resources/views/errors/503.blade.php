@include('errors.layouts.status-page', [
    'statusCode' => 503,
    'title' => 'システムを一時的に利用できません',
    'message' => "現在メンテナンス中、または一時的にご利用いただけません。\nしばらくしてから再度お試しください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
