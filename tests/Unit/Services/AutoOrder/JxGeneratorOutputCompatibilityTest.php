<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use Tests\Support\FakeLegacyEosSlipNumberService;
use Tests\Support\JxGeneratorTestCase;
use Tests\Support\JxTestRecords;

class JxGeneratorOutputCompatibilityTest extends JxGeneratorTestCase
{
    public function test_all_four_recipients_match_the_pre_change_generator_byte_for_byte(): void
    {
        $generator = new HanaOrderJXFileGenerator(new FakeLegacyEosSlipNumberService);
        $candidates = WmsOrderCandidate::with(['warehouse', 'item', 'contractor'])->orderBy('id')->get();
        $files = $generator->generate($candidates);
        $this->assertCount(4, $files);

        foreach ($files as $file) {
            $path = __DIR__.'/../../../Fixtures/jx/generated/'.$file['contractor_code'].'.dat';
            $this->assertFileExists($path);
            $expected = file_get_contents($path);
            $this->assertTrue(
                $expected === $file['content'],
                sprintf('送信先%sのJXデータが変更されています。期待SHA-256: %s / 実際: %s', $file['contractor_code'], hash('sha256', $expected), hash('sha256', $file['content']))
            );
            $this->assertCount($file['record_count'], JxTestRecords::generated($file['content']));
        }
    }
}
