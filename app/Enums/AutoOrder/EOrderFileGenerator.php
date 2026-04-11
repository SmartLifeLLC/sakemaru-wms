<?php

namespace App\Enums\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator2;

enum EOrderFileGenerator: string
{
    case HANA = 'hana';
    case HANA2 = 'hana2';

    public function label(): string
    {
        return match ($this) {
            self::HANA => 'ハナ様向け（128byte固定長）',
            self::HANA2 => 'ハナ様向け（Aレコードなし）',
        };
    }

    public function generatorClass(): string
    {
        return match ($this) {
            self::HANA => HanaOrderJXFileGenerator::class,
            self::HANA2 => HanaOrderJXFileGenerator2::class,
        };
    }

    public function generator(): OrderFileGeneratorInterface
    {
        return app($this->generatorClass());
    }
}
