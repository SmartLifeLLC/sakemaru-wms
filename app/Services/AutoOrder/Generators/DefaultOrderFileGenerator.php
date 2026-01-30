<?php

namespace App\Services\AutoOrder\Generators;

use App\Contracts\OrderFileGeneratorInterface;
use Illuminate\Support\Collection;

/**
 * デフォルト発注ファイル生成クラス
 *
 * クライアント設定が未設定の場合のフォールバック用。
 * 実際のファイル生成は行わない。
 */
class DefaultOrderFileGenerator implements OrderFileGeneratorInterface
{
    public function generate(Collection $orderCandidates): array
    {
        return [];
    }

    public function getJxTransmissionContractorIds(): array
    {
        return [];
    }

    public function getTransmissionContractorMapping(): array
    {
        return [];
    }

    public function getEncoding(): string
    {
        return 'UTF-8';
    }

    public function getLineEnding(): string
    {
        return "\n";
    }

    public function getFileExtension(): string
    {
        return 'txt';
    }
}
