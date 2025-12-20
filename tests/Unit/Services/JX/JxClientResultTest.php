<?php

namespace Tests\Unit\Services\JX;

use App\Services\JX\JxClientResult;
use PHPUnit\Framework\TestCase;

class JxClientResultTest extends TestCase
{
    public function test_success_result_creation(): void
    {
        $result = JxClientResult::success('test_message_id_123');

        $this->assertTrue($result->success);
        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->failed());
        $this->assertEquals('test_message_id_123', $result->messageId);
        $this->assertNull($result->error);
        $this->assertNull($result->response);
    }

    public function test_failure_result_creation(): void
    {
        $result = JxClientResult::failure('Connection timeout', 'test_message_id_456');

        $this->assertFalse($result->success);
        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->failed());
        $this->assertEquals('test_message_id_456', $result->messageId);
        $this->assertEquals('Connection timeout', $result->error);
        $this->assertNull($result->response);
    }
}
