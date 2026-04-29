@include('errors.layouts.status-page', [
    'statusCode' => 403,
    'title' => 'アクセスが制限されています',
    'message' => "このページを表示する権限がありません。\n必要な場合は以下のボタンからシステム担当者へご連絡ください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
