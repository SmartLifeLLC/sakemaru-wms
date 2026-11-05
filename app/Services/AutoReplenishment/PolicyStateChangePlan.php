<?php

namespace App\Services\AutoReplenishment;

final class PolicyStateChangePlan
{
    public function build(array $versions, array $policies, bool $enabled): array
    {
        throw_unless(count($versions) > 0 && count($versions) <= 100, \RuntimeException::class, '変更する商品を1〜100件選択してください。');
        throw_unless(count($policies) === count($versions), \RuntimeException::class, '選択した商品が見つかりません。画面を再読み込みして選択し直してください。');
        $rows = [];
        foreach ($policies as $policy) {
            $id = (string) $policy['id'];
            throw_unless(isset($versions[$id]) && (int) $versions[$id] === (int) $policy['version'], \RuntimeException::class, '選択した商品の設定が変更されています。画面を再読み込みして選択し直してください。');
            $rows[] = ['id' => (int) $id, 'version' => (int) $policy['version'],
                'item_code' => (string) ($policy['item_code'] ?? ''), 'item_name' => $policy['item_name'] ?? '',
                'enabled' => (bool) $policy['is_enabled'], 'changes' => (bool) $policy['is_enabled'] !== $enabled];
        }
        $changes = count(array_filter($rows, fn ($row) => $row['changes']));

        return ['enabled' => $enabled, 'versions' => $versions, 'rows' => $rows,
            'change_count' => $changes, 'unchanged_count' => count($rows) - $changes];
    }
}
