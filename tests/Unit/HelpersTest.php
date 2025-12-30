<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function test_create_jx_message_id_format(): void
    {
        $messageId = createJxMessageId('put', 'test.example.co.jp');

        $this->assertStringStartsWith('put_', $messageId);
        $this->assertStringEndsWith('@test.example.co.jp', $messageId);
        $this->assertMatchesRegularExpression('/^put_[a-f0-9]+_\d{14}@test\.example\.co\.jp$/', $messageId);
    }

    public function test_create_jx_message_id_with_different_types(): void
    {
        $getMessageId = createJxMessageId('get', 'example.co.jp');
        $this->assertStringStartsWith('get_', $getMessageId);

        $confirmMessageId = createJxMessageId('confirm', 'example.co.jp');
        $this->assertStringStartsWith('confirm_', $confirmMessageId);
    }

    public function test_get_jx_timestamp_format(): void
    {
        $timestamp = getJxTimestamp();

        // ISO 8601 形式 (YYYY-MM-DDTHH:MM:SS) であることを確認
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $timestamp);
    }

    public function test_get_jx_timestamp_with_utc(): void
    {
        $timestamp = getJxTimestamp('UTC');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $timestamp);
    }
}
