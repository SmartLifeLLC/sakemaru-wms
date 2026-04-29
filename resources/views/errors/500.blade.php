@include('errors.layouts.status-page', [
    'statusCode' => 500,
    'title' => 'システムエラーが発生しました',
    'message' => "申し訳ありません。サーバー内部で予期せぬエラーが発生しました。\nしばらく時間をおいてから再度お試しいただくか、以下のボタンからご報告ください。",
    'panelCandidates' => ['admin'],
    'megaMenuPanelIds' => ['admin'],
])
