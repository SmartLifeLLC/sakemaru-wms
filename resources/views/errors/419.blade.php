@include('errors.layouts.status-page', [
    'statusCode' => 419,
    'title' => 'ページの有効期限が切れました',
    'message' => "一定時間操作がなかったため、ページの有効期限が切れました。\n再度お試しください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
