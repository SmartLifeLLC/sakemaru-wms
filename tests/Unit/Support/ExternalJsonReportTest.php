<?php

namespace Tests\Unit\Support;

use App\Support\ExternalJsonReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExternalJsonReportTest extends TestCase
{
    public function test_existing_backup_is_never_overwritten(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hq-report-');
        file_put_contents($path, 'original');
        try {
            try {
                ExternalJsonReport::write($path, ['changed' => true]);
                $this->fail('既存バックアップへの上書きが許可されました。');
            } catch (RuntimeException) {
                $this->assertSame('original', file_get_contents($path));
            }
        } finally {
            unlink($path);
        }
    }

    public function test_any_git_worktree_is_rejected(): void
    {
        $directory = sys_get_temp_dir().'/hq-report-'.bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory.'/nested');
        file_put_contents($directory.'/.git', 'gitdir: /some/other/repository');
        try {
            $this->expectException(RuntimeException::class);
            ExternalJsonReport::write($directory.'/nested/backup.json', []);
        } finally {
            unlink($directory.'/.git');
            rmdir($directory.'/nested');
            rmdir($directory);
        }
    }
}
