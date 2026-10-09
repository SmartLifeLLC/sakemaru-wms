<?php

namespace Tests\Unit\Support;

use App\Support\DbMutex;
use InvalidArgumentException;
use Tests\TestCase;

class DbMutexTest extends TestCase
{
    public function test_acquire_or_fail_exposes_infrastructure_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DbMutex::acquireOrFail('test-lock', 0, 'missing-test-connection');
    }

    public function test_legacy_acquire_keeps_returning_false_for_infrastructure_errors(): void
    {
        $this->assertFalse(DbMutex::acquire('test-lock', 0, 'missing-test-connection'));
    }

    public function test_with_lock_or_fail_exposes_infrastructure_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DbMutex::withLockOrFail(
            'test-lock',
            fn (): bool => true,
            0,
            'missing-test-connection'
        );
    }
}
