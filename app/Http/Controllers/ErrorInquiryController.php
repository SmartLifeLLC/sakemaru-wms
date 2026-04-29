<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ErrorInquiryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'status_code' => ['required', 'integer', 'between:400,599'],
            'error_title' => ['required', 'string', 'max:120'],
            'reporter_name' => ['nullable', 'string', 'max:120'],
            'details' => ['required', 'string', 'max:2000'],
            'page_url' => ['required', 'string', 'max:2000'],
        ], [
            'details.required' => '発生時の操作・状況を入力してください。',
        ]);

        if (blank((string) config('logging.channels.inquiry.url'))) {
            return response()->json([
                'message' => '問い合わせ通知の送信先が設定されていません。',
            ], 503);
        }

        $reporterName = trim((string) ($validated['reporter_name'] ?? ''));

        if ($reporterName === '') {
            $reporterName = (string) ($user?->name ?: $user?->email ?: '未ログイン');
        }

        $loginUser = trim(collect([$user?->name, $user?->email])->filter()->implode(' / '));

        $lines = [
            'エラー問い合わせを受信しました。',
            'システム: '.$this->systemLabel($request),
            'エラーコード: '.$validated['status_code'],
            'エラー内容: '.$validated['error_title'],
            '発生URL: '.$validated['page_url'],
            'ログインユーザー: '.($loginUser !== '' ? $loginUser : '未ログイン'),
            '報告者名: '.$reporterName,
            '送信元IP: '.$request->ip(),
            'User-Agent: '.Str::limit((string) $request->userAgent(), 200),
            '問い合わせ内容:',
            $validated['details'],
        ];

        try {
            Log::channel('inquiry')->warning(implode("\n", $lines));
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'message' => '問い合わせの送信に失敗しました。時間をおいて再度お試しください。',
            ], 500);
        }

        return response()->json([
            'message' => '問い合わせを送信しました。',
        ]);
    }

    private function systemLabel(Request $request): string
    {
        $host = parse_url(config('app.url') ?: $request->getSchemeAndHttpHost(), PHP_URL_HOST);
        $hostLabel = is_string($host) && $host !== '' ? $host : config('app.name', 'sakemaru-wms');
        $appName = (string) config('app.name', 'sakemaru-wms');

        return sprintf('%s (%s)', $appName, $hostLabel);
    }
}
