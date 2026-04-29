@include('errors.layouts.status-page', [
    'statusCode' => 401,
    'title' => 'ログイン情報を確認してください',
    'message' => "認証情報を確認できませんでした。\n再度ログインしてからお試しください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
