<?php

namespace App\Domains\Sakemaru;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SakemaruModel
{
    protected static function retryRequest(callable $request, string $url, int $maxRetry = 3, ?array $requestData = null): array
    {
        // Skip retries in local environment to avoid timeout
        if (app()->environment('local')) {
            $maxRetry = 1;
        }

        $retry = 1;
        $response = $request();

        while (! $response->successful()) {
            if ($retry > $maxRetry) {
                Log::error('APIの呼び出しに失敗しました。', [
                    'url' => $url,
                    'request' => $requestData,
                    'response' => $response->reason(),
                    'content' => $response->json(),
                ]);

                return [
                    'success' => false,
                    'error' => $response->reason(),
                    'status' => $response->status(),
                    'debug_message' => $response->body(),
                ];
            }

            // Short sleep for local, normal sleep for production
            $sleepSeconds = app()->environment('local') ? 2 : ($retry * 60);
            sleep($sleepSeconds);

            Log::info('API Retry '.$retry, [
                'url' => $url,
                'request' => $requestData,
                'response' => $response->reason(),
                'content' => $response->json(),
            ]);

            $response = $request();
            $retry++;
        }

        return $response->json();
    }

    public static function getData(int $page = 1, array $params = []): array
    {
        return static::retryRequest(
            fn () => static::getResponse($page, $params),
            static::url($page)
        );
    }

    public static function postData(array $data): array
    {
        return static::retryRequest(
            fn () => static::postResponse($data),
            static::postUrl(),
            3,
            $data
        );
    }

    protected static function getResponse(int $page = 1, array $params = []): Response
    {
        $url = static::url($page);
        foreach ($params as $key => $param) {
            $url .= "&{$key}={$param}";
        }

        $http = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withToken(static::getApiToken());

        // Disable SSL verification for local development
        if (app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        return $http->get($url);
    }

    protected static function postResponse(array $data): Response
    {
        $http = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(300)
            ->connectTimeout(10)
            ->withToken(static::getApiToken());

        // Disable SSL verification for local development
        if (app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        return $http->post(static::postUrl(), $data);
    }

    protected static function url(int $page = 1): string
    {
        return '';
    }

    protected static function postUrl(): string
    {
        return '';
    }

    protected static function baseUrl(): string
    {
        $coreUrl = config('app.core_url', env('CORE_URL', 'https://sakemaru-core.test'));

        return rtrim($coreUrl, '/').'/api';
    }

    protected static function getApiToken(): string
    {
        return config('app.sakemaru_api_token', env('SAKEMARU_API_TOKEN', ''));
    }
}
