<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MySQL GET_LOCK/RELEASE_LOCK wrapper for distributed mutual exclusion
 *
 * NOTE: Locks are connection-scoped. The same PDO connection MUST be used
 * for acquire -> process -> release cycle.
 */
final class DbMutex
{
    /**
     * Acquire a named lock using MySQL GET_LOCK()
     *
     * @param  string  $key  Lock key (e.g., "alloc:991:12345")
     * @param  int  $timeoutSec  Timeout in seconds (default: 1)
     * @param  string|null  $connection  Database connection name
     * @return bool True if lock acquired, false if timeout/failure
     */
    public static function acquire(string $key, int $timeoutSec = 1, ?string $connection = null): bool
    {
        try {
            $pdo = DB::connection($connection)->getPdo();
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([$key, $timeoutSec]);
            $result = (int) $stmt->fetchColumn();

            if ($result === 1) {
                Log::debug('DbMutex acquired', ['key' => $key]);

                return true;
            }

            Log::warning('DbMutex acquire failed', [
                'key' => $key,
                'result' => $result,
                'timeout' => $timeoutSec,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('DbMutex acquire exception', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Release a named lock using MySQL RELEASE_LOCK()
     *
     * @param  string  $key  Lock key
     * @param  string|null  $connection  Database connection name
     */
    public static function release(string $key, ?string $connection = null): void
    {
        try {
            $pdo = DB::connection($connection)->getPdo();
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$key]);
            $result = (int) $stmt->fetchColumn();

            if ($result === 1) {
                Log::debug('DbMutex released', ['key' => $key]);
            } elseif ($result === 0) {
                Log::warning('DbMutex release: lock not held', ['key' => $key]);
            } else {
                Log::warning('DbMutex release: lock does not exist', ['key' => $key]);
            }
        } catch (\Throwable $e) {
            // Don't throw - connection may be closed or lock auto-released
            Log::warning('DbMutex release failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a lock is currently held
     *
     * @param  string  $key  Lock key
     * @param  string|null  $connection  Database connection name
     * @return bool True if lock is held by ANY connection
     */
    public static function isLocked(string $key, ?string $connection = null): bool
    {
        try {
            $pdo = DB::connection($connection)->getPdo();
            $stmt = $pdo->prepare('SELECT IS_USED_LOCK(?)');
            $stmt->execute([$key]);
            $result = $stmt->fetchColumn();

            return $result !== null;
        } catch (\Throwable $e) {
            Log::error('DbMutex isLocked exception', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Execute a callback with lock protection
     *
     * @param  string  $key  Lock key
     * @param  callable  $callback  Function to execute while holding lock
     * @param  int  $timeoutSec  Lock timeout in seconds
     * @param  string|null  $connection  Database connection name
     * @return mixed Callback return value, or null if lock not acquired
     *
     * @throws \Throwable Re-throws exceptions from callback after releasing lock
     */
    public static function withLock(string $key, callable $callback, int $timeoutSec = 1, ?string $connection = null): mixed
    {
        if (! self::acquire($key, $timeoutSec, $connection)) {
            return null;
        }

        try {
            return $callback();
        } finally {
            self::release($key, $connection);
        }
    }
}
