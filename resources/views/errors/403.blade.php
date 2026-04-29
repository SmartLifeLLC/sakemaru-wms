@include('errors.layouts.status-page', [
    'statusCode' => 403,
    'title' => '403 Forbidden',
    'message' => '該当ページへのアクセスが制限されています。',
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
