<?php

namespace App\Support;

use RuntimeException;

final class ExternalJsonReport
{
    public static function write(string $path, mixed $data): void
    {
        $directory = realpath(dirname($path));
        if ($directory === false) {
            throw new RuntimeException('保存先のフォルダーがありません。');
        }
        for ($ancestor = $directory; ; $ancestor = dirname($ancestor)) {
            if (file_exists($ancestor.'/.git')) {
                throw new RuntimeException('保存先はGit作業フォルダー外の新規ファイルを指定してください。');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('保存先は書き込み可能な新規ファイル名を指定してください。');
        }
        try {
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('照合結果を保存できませんでした。');
                }
                $offset += $written;
            }
        } finally {
            fclose($handle);
        }
    }
}
