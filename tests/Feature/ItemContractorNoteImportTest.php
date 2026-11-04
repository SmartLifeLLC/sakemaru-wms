<?php

namespace Tests\Feature;

use App\Services\ItemContractorNoteImport;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ItemContractorNoteImportTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, client_id INTEGER, code INTEGER)');
        $db->statement('CREATE TABLE item_contractors (id INTEGER PRIMARY KEY, client_id INTEGER, item_id INTEGER, warehouse_id INTEGER, note TEXT, safety_stock INTEGER, is_auto_order INTEGER, updated_at TEXT)');
        $db->statement('INSERT INTO items VALUES (1, 1, 100), (2, 1, 200), (3, 2, 100)');
        $db->statement("INSERT INTO item_contractors VALUES (1, 1, 1, 91, '旧1', 10, 1, NULL), (2, 1, 1, 92, '旧2', 20, 1, NULL), (3, 1, 2, 91, '旧3', 30, 0, NULL), (4, 2, 3, 91, '他社', 40, 0, NULL)");
        $this->path = tempnam(sys_get_temp_dir(), 'item_note_test_');
    }

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public static function formats(): array
    {
        return [['xls'], ['xlsx'], ['csv'], ['csv', 'SJIS-win']];
    }

    #[DataProvider('formats')]
    public function test_import_updates_only_matching_company_notes_and_can_be_retried(string $extension, string $encoding = 'UTF-8'): void
    {
        $note = "=棚番A,\n二行目";
        if ($extension === 'csv') {
            $csv = "備考,商品名,商品コード\r\n\"{$note}\",商品A,00100\r\n,商品B,200\r\n";
            file_put_contents($this->path, $encoding === 'UTF-8' ? "\xEF\xBB\xBF".$csv : mb_convert_encoding($csv, $encoding, 'UTF-8'));
        } else {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->fromArray([['備考', '商品名', '商品コード'], [null, '商品A', 100], [null, '商品B', '00200']]);
            $sheet->setCellValueExplicit('A2', $note, DataType::TYPE_STRING);
            $writer = $extension === 'xls' ? new Xls($book) : new Xlsx($book);
            $writer->save($this->path);
            $book->disconnectWorksheets();
        }

        $service = new ItemContractorNoteImport;
        self::assertSame(3, $service->import(1, $this->path, strtoupper($extension)));
        self::assertSame(0, $service->import(1, $this->path, $extension));
        $table = DB::connection('sakemaru')->table('item_contractors')->orderBy('id');
        self::assertSame([$note, $note, null, '他社'], $table->pluck('note')->all());
        self::assertSame([10, 20, 30, 40], $table->pluck('safety_stock')->all());
        self::assertSame([1, 1, 0, 0], $table->pluck('is_auto_order')->all());
    }

    public static function invalidFiles(): array
    {
        return [
            ['商品コード,商品名', '1行目に「備考」'],
            ["商品コード,備考\n100,更新\n00100,重複", '重複'],
            ["商品コード,備考\n100,更新\n999,未登録", '未登録'],
            ["商品コード,備考\n100,更新\n200", '備考欄がありません'],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_rows_abort_the_whole_import(string $csv, string $error): void
    {
        file_put_contents($this->path, $csv);
        try {
            (new ItemContractorNoteImport)->import(1, $this->path, 'csv');
            self::fail('Expected an invalid file error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($error, $exception->getMessage());
        }
        self::assertSame('旧1', DB::connection('sakemaru')->table('item_contractors')->where('id', 1)->value('note'));
    }
}
