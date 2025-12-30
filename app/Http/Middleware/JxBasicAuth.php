<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JXサーバー用Basic認証ミドルウェア
 *
 * config/jx.php の設定値と比較して認証を行う
 */
class JxBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedUserId = config('jx.server.basic_user_id');
        $expectedPassword = config('jx.server.basic_user_password');

        // 設定が空の場合は認証をスキップ
        if (empty($expectedUserId) || empty($expectedPassword)) {
            return $next($request);
        }

        $userId = $request->getUser();
        $password = $request->getPassword();

        if ($userId === $expectedUserId && $password === $expectedPassword) {
            return $next($request);
        }

        return response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="JX Server"',
        ]);
    }
}
