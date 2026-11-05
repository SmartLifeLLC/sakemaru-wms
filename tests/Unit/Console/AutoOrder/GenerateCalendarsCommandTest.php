<?php

namespace Tests\Unit\Console\AutoOrder;

use App\Console\Commands\AutoOrder\GenerateCalendarsCommand;
use App\Services\AutoOrder\CalendarGenerationService;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateCalendarsCommandTest extends TestCase
{
    #[DataProvider('disabledOptions')]
    public function test_generation_is_disabled_without_resolving_service_or_database(array $options): void
    {
        $container = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->bind(CalendarGenerationService::class, function () {
            $this->fail('利用停止中にカレンダー生成サービスを呼び出してはいけません。');
        });
        $container->bind('db', function () {
            $this->fail('利用停止中にデータベースへ接続してはいけません。');
        });

        $command = new GenerateCalendarsCommand;
        $command->setLaravel($container);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute($options, ['interactive' => false]));
        $this->assertStringContainsString('倉庫カレンダーの一括生成は利用停止中です。', $tester->getDisplay());
        $this->assertStringContainsString('「倉庫カレンダー」画面', $tester->getDisplay());
    }

    public static function disabledOptions(): array
    {
        return [
            'all warehouses' => [[]],
            'single warehouse' => [['--warehouse' => '91']],
            'custom period' => [['--months' => '24']],
            'single warehouse and custom period' => [['--warehouse' => '91', '--months' => '24']],
        ];
    }
}
