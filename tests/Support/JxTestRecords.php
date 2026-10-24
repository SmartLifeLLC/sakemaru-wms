<?php

namespace Tests\Support;

use RuntimeException;

/** Test-only reader: archive separators are allowed only between complete records. */
final class JxTestRecords
{
    public static function sampleFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("JXサンプルを読み込めません: {$path}");
        }

        return self::parse(file_get_contents($path), true);
    }

    public static function generated(string $content): array
    {
        // Generated files may have one terminal CRLF, never record separators.
        if (str_ends_with($content, "\r\n")) {
            $content = substr($content, 0, -2);
        }

        return self::parse($content, false);
    }

    public static function sample(string $content): array
    {
        return self::parse($content, true);
    }

    private static function parse(string $content, bool $allowSeparators): array
    {
        if ($content === '') {
            throw new RuntimeException('JXデータが空です。');
        }

        $records = [];
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            if ($allowSeparators && $offset > 0) {
                if (substr($content, $offset, 2) === "\r\n") {
                    $offset += 2;
                } elseif ($content[$offset] === "\n") {
                    $offset++;
                }
                if ($offset === $length) {
                    break;
                }
            }

            $record = substr($content, $offset, 128);
            if (strlen($record) !== 128) {
                throw new RuntimeException("JXレコードが128バイトに足りません（位置{$offset}）。");
            }
            if (str_contains($record, "\r") || str_contains($record, "\n")) {
                throw new RuntimeException("JXレコード内に改行があります（位置{$offset}）。");
            }
            if (! in_array($record[0], ['A', 'B', 'D', '1', '8'], true)) {
                throw new RuntimeException("JXレコード区分が不正です（位置{$offset}）。");
            }
            $records[] = $record;
            $offset += 128;
        }

        return $records;
    }
}
