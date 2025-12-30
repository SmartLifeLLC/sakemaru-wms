<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JX Server Test Authentication
    |--------------------------------------------------------------------------
    |
    | ローカル/テスト環境のJXサーバーで使用するBasic認証情報
    |
    */
    'server' => [
        'basic_user_id' => env('JX_SERVER_BASIC_USER_ID'),
        'basic_user_password' => env('JX_SERVER_BASIC_USER_PASSWORD'),
    ],
];
