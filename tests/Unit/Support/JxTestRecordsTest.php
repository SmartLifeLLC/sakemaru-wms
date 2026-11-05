<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\JxTestRecords;

class JxTestRecordsTest extends TestCase
{
    #[DataProvider('sampleSeparators')]
    public function test_sample_separators_preserve_record_bytes(string $separator): void
    {
        $records = [str_pad('A01', 128), str_pad('B01', 128), str_pad('D01', 128)];

        $this->assertSame($records, JxTestRecords::sample(implode($separator, $records).$separator));
    }

    public static function sampleSeparators(): array
    {
        return ['contiguous' => [''], 'LF' => ["\n"], 'CRLF' => ["\r\n"]];
    }

    #[DataProvider('invalidSamples')]
    public function test_invalid_sample_data_is_rejected(string $content): void
    {
        $this->expectException(RuntimeException::class);
        JxTestRecords::sample($content);
    }

    public static function invalidSamples(): array
    {
        $a = str_pad('A01', 128);
        $b = str_pad('B01', 128);

        return [
            'empty' => [''],
            'short record' => [substr($a, 0, 127)],
            'short final record' => [$a.substr($b, 0, 127)],
            'LF inside record' => [substr_replace($a, "\n", 64, 1).$b],
            'CRLF inside record' => [substr_replace($a, "\r\n", 64, 2).$b],
            'LF before first record' => ["\n".$a],
            'extra blank line at boundary' => [$a."\n\n".$b],
            'bare CR at boundary' => [$a."\r".$b],
            'unknown record type' => [str_pad('X01', 128)],
        ];
    }

    public function test_generated_data_accepts_only_an_optional_terminal_crlf(): void
    {
        $records = [str_pad('A01', 128), str_pad('B01', 128)];
        $this->assertSame($records, JxTestRecords::generated(implode('', $records)));
        $this->assertSame($records, JxTestRecords::generated(implode('', $records)."\r\n"));
    }

    #[DataProvider('generatedSeparators')]
    public function test_generated_data_never_accepts_internal_separators(string $separator): void
    {
        $this->expectException(RuntimeException::class);
        JxTestRecords::generated(str_pad('A01', 128).$separator.str_pad('B01', 128));
    }

    public static function generatedSeparators(): array
    {
        return ['LF' => ["\n"], 'CRLF' => ["\r\n"]];
    }

    #[DataProvider('invalidGeneratedData')]
    public function test_generated_data_never_hides_a_malformed_tail(string $content): void
    {
        $this->expectException(RuntimeException::class);
        JxTestRecords::generated($content);
    }

    public static function invalidGeneratedData(): array
    {
        $a = str_pad('A01', 128);

        return [
            'empty' => [''],
            'short final record' => [$a.substr(str_pad('B01', 128), 0, 127)],
            'terminal LF' => [$a."\n"],
            'extra terminal CRLF' => [$a."\r\n\r\n"],
            'trailing garbage' => [$a.'x'],
        ];
    }

    public function test_a_missing_fixture_fails_with_its_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing_sample.txt');
        JxTestRecords::sampleFile(__DIR__.'/missing_sample.txt');
    }

    #[DataProvider('archives')]
    public function test_archived_fixtures_keep_the_original_bytes(int $code, string $hash, int $count): void
    {
        $path = __DIR__.'/../../Fixtures/jx/'.$code.'_sample.txt';
        $this->assertSame($hash, hash_file('sha256', $path));
        $records = JxTestRecords::sampleFile($path);
        $this->assertCount($count, $records);
        foreach (['A', 'B', 'D'] as $type) {
            $this->assertNotEmpty(array_filter($records, fn ($record) => $record[0] === $type));
        }
    }

    public static function archives(): array
    {
        return [
            [1017, '3ba8606a4e16bb4b4a8ca8f73749f1857784b15c6fa9e27e89587e5c524c1357', 18],
            [1106, '4dbcb57568dbe1392ccf2d63b582cadd68460083e01ce625647bde9eee24a49e', 682],
            [1202, 'd899f198737f92da1547ed665f85925dbd74ff831675010a2d474ae119bac24a', 269],
            [1330, '3d8763945bbd6b3f3a83b2f6d8555ed0e312b07d1004646a0da85c4facc55868', 309],
        ];
    }
}
