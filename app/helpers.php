<?php

use Carbon\Carbon;

// Helper functions for the application

/**
 * JX用メッセージIDを生成
 *
 * @param  string  $documentType  ドキュメントタイプ (get, put, confirm)
 * @param  string  $uri  送信元URI
 */
function createJxMessageId(string $documentType, string $uri): string
{
    return $documentType.'_'.uniqid().'_'.Carbon::now()->format('YmdHis').'@'.$uri;
}

/**
 * JX用タイムスタンプを取得
 *
 * @param  string|null  $tz  タイムゾーン (デフォルト: null = ローカル)
 * @return string ISO 8601形式のタイムスタンプ
 */
function getJxTimestamp(?string $tz = null): string
{
    return Carbon::now($tz)->format('Y-m-d\TH:i:s');
}
