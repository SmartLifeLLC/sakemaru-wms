<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swagger UI用Basic認証ミドルウェア
 */
class SwaggerBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedUser = config('l5-swagger.defaults.routes.auth.username', 'code');
        $expectedPassword = config('l5-swagger.defaults.routes.auth.password', 'code');

        $user = $request->getUser();
        $password = $request->getPassword();

        if ($user === $expectedUser && $password === $expectedPassword) {
            return $next($request);
        }

        return response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="API Documentation"',
        ]);
    }
}
