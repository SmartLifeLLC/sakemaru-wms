<?php

namespace Tests\Unit\Services\Navigation;

use App\Services\Navigation\MegaMenuBuilder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SakemaruSystemUrlTest extends TestCase
{
    public static function origins(): array
    {
        return [
            ['https://wms.lw-hana.net', 'http://localhost', 'https://lw-hana.net'],
            ['https://wms.lw-hana.click', 'http://localhost', 'https://lw-hana.click'],
            ['https://wms.sakemaru.test', 'http://localhost', 'https://sakemaru.test'],
            ['http://10.0.2.2', 'https://wms.sakemaru.test', 'https://sakemaru.test'],
        ];
    }

    #[DataProvider('origins')]
    public function test_menu_and_document_urls_use_the_current_environment(string $configured, string $web, string $expected): void
    {
        config(['app.url' => $configured, 'app.core_url' => 'https://wrong-environment.invalid']);
        $this->app->instance('request', Request::create($web));
        $builder = app(MegaMenuBuilder::class);
        $this->assertSame($expected, $builder->sakemaruSystemUrl());
        $this->assertSame(str_replace('://', '://trade.', $expected), $builder->sakemaruSystemUrl('trade'));
    }
}
