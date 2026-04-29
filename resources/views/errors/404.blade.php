@include('errors.layouts.status-page', [
    'statusCode' => 404,
    'title' => 'ページが見つかりません',
    'message' => "指定されたページが見つかりませんでした。\nURLをご確認のうえ、再度お試しください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
