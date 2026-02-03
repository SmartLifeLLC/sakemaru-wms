<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 楽観ロック機能を提供するトレイト
 *
 * モデルの更新時にlock_versionをチェックし、
 * 競合が発生した場合は例外をスローする
 */
trait HasOptimisticLock
{
    /**
     * 楽観ロックを使用して更新
     *
     * @param  array  $attributes  更新する属性
     * @return bool 更新成功時true
     *
     * @throws OptimisticLockException 競合時
     */
    public function updateWithLock(array $attributes): bool
    {
        $currentVersion = $this->lock_version ?? 1;
        $newVersion = $currentVersion + 1;

        // lock_version を含めて更新
        $attributes['lock_version'] = $newVersion;

        $affected = static::where('id', $this->id)
            ->where('lock_version', $currentVersion)
            ->update($attributes);

        if ($affected === 0) {
            // 別のユーザーが更新した可能性
            throw new OptimisticLockException(
                "データが他のユーザーによって更新されました。画面を更新して再度お試しください。"
            );
        }

        // メモリ上のモデルも更新
        $this->fill($attributes);
        $this->lock_version = $newVersion;

        return true;
    }

    /**
     * 楽観ロックを使用してステータス更新
     *
     * @param  string  $status  新しいステータス
     * @param  int|null  $modifiedBy  変更者ID
     * @return bool 更新成功時true
     *
     * @throws OptimisticLockException 競合時
     */
    public function updateStatusWithLock(string $status, ?int $modifiedBy = null): bool
    {
        return $this->updateWithLock([
            'status' => $status,
            'modified_by' => $modifiedBy ?? auth()->id(),
            'modified_at' => now(),
        ]);
    }

    /**
     * トランザクション内で楽観ロック更新を実行
     *
     * @param  array  $attributes  更新する属性
     * @return bool 更新成功時true
     *
     * @throws OptimisticLockException 競合時
     */
    public function updateWithLockInTransaction(array $attributes): bool
    {
        return DB::connection($this->getConnectionName())->transaction(function () use ($attributes) {
            return $this->updateWithLock($attributes);
        });
    }

    /**
     * 指定されたバージョンと現在のバージョンが一致するか確認
     */
    public function checkVersion(int $expectedVersion): bool
    {
        return $this->lock_version === $expectedVersion;
    }

    /**
     * 最新のバージョンを取得してリフレッシュ
     */
    public function refreshWithVersion(): self
    {
        $this->refresh();

        return $this;
    }
}

/**
 * 楽観ロック競合例外
 */
class OptimisticLockException extends \RuntimeException
{
    public function __construct(string $message = 'Optimistic lock conflict detected', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
