<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use RuntimeException;
use Tests\TestCase;

class HanaOrderJXFileGeneratorSlipNumberTest extends TestCase
{
    public function test_generate_b_record_rejects_non_eleven_digit_db_slip_number(): void
    {
        $generator = new HanaOrderJXFileGenerator;
        $method = new \ReflectionMethod($generator, 'generateBRecord');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);

        $method->invoke($generator, $this->candidate(), 1, '202605061000');
    }

    private function candidate(): object
    {
        return (object) [
            'warehouse' => null,
            'contractor' => null,
            'expected_arrival_date' => null,
        ];
    }
}
