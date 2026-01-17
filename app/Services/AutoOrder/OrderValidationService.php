<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsOrderCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 発注バリデーションサービス
 *
 * 発注処理における各種バリデーションを集約
 */
class OrderValidationService
{
    /**
     * 送信前チェック: バッチ内の全候補がCONFIRMED状態であることを確認
     *
     * @param  string  $batchCode  バッチコード
     * @return array{valid: bool, message: string, details: array}
     */
    public function validateBatchForTransmission(string $batchCode): array
    {
        // バッチ内の全候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->whereIn('status', [
                CandidateStatus::PENDING,
                CandidateStatus::APPROVED,
                CandidateStatus::CONFIRMED,
            ])
            ->select('id', 'status', 'warehouse_id', 'item_id')
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'valid' => false,
                'message' => '送信対象の発注候補がありません',
                'details' => [],
            ];
        }

        // ステータス別にカウント
        $statusCounts = $candidates->groupBy(fn ($c) => $c->status->value)
            ->map(fn ($group) => $group->count());

        $pendingCount = $statusCounts->get(CandidateStatus::PENDING->value, 0);
        $approvedCount = $statusCounts->get(CandidateStatus::APPROVED->value, 0);
        $confirmedCount = $statusCounts->get(CandidateStatus::CONFIRMED->value, 0);

        // 未承認（PENDING）があれば送信不可
        if ($pendingCount > 0) {
            return [
                'valid' => false,
                'message' => "未承認の発注候補が {$pendingCount}件 あります。全ての発注候補を承認してください。",
                'details' => [
                    'pending_count' => $pendingCount,
                    'approved_count' => $approvedCount,
                    'confirmed_count' => $confirmedCount,
                ],
            ];
        }

        // 承認済み（APPROVED）があれば確定が必要
        if ($approvedCount > 0) {
            return [
                'valid' => false,
                'message' => "発注確定されていない候補が {$approvedCount}件 あります。発注確定を実行してください。",
                'details' => [
                    'pending_count' => $pendingCount,
                    'approved_count' => $approvedCount,
                    'confirmed_count' => $confirmedCount,
                ],
            ];
        }

        // 全てCONFIRMED
        return [
            'valid' => true,
            'message' => "送信可能: {$confirmedCount}件 の確定済み発注候補",
            'details' => [
                'pending_count' => $pendingCount,
                'approved_count' => $approvedCount,
                'confirmed_count' => $confirmedCount,
            ],
        ];
    }

    /**
     * 再計算前チェック: 送信済み（EXECUTED）の候補がないことを確認
     *
     * @return array{valid: bool, message: string, details: array}
     */
    public function validateForRecalculation(): array
    {
        // 送信済みでない候補（再計算で影響を受ける可能性のある候補）を確認
        $nonExecutedCandidates = WmsOrderCandidate::whereIn('status', [
            CandidateStatus::PENDING,
            CandidateStatus::APPROVED,
            CandidateStatus::CONFIRMED,
        ])->exists();

        if (! $nonExecutedCandidates) {
            return [
                'valid' => true,
                'message' => '再計算可能です',
                'details' => [],
            ];
        }

        // 未送信の候補がある場合、その状態を詳細に報告
        $statusCounts = WmsOrderCandidate::whereIn('status', [
            CandidateStatus::PENDING,
            CandidateStatus::APPROVED,
            CandidateStatus::CONFIRMED,
        ])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $confirmedCount = $statusCounts[CandidateStatus::CONFIRMED->value] ?? 0;

        // 確定済みだが未送信の候補がある場合は警告
        if ($confirmedCount > 0) {
            return [
                'valid' => false,
                'message' => "発注確定済みで未送信の候補が {$confirmedCount}件 あります。再計算すると入庫予定データが削除されます。",
                'details' => [
                    'pending_count' => $statusCounts[CandidateStatus::PENDING->value] ?? 0,
                    'approved_count' => $statusCounts[CandidateStatus::APPROVED->value] ?? 0,
                    'confirmed_count' => $confirmedCount,
                    'warning' => '再計算を実行すると、これらの候補と関連する入庫予定は削除されます',
                ],
            ];
        }

        // PENDING/APPROVEDのみの場合
        return [
            'valid' => true,
            'message' => '再計算可能です（未確定の候補は削除されます）',
            'details' => [
                'pending_count' => $statusCounts[CandidateStatus::PENDING->value] ?? 0,
                'approved_count' => $statusCounts[CandidateStatus::APPROVED->value] ?? 0,
                'confirmed_count' => 0,
            ],
        ];
    }

    /**
     * 手動発注の重複チェック
     *
     * @param  int  $warehouseId  倉庫ID
     * @param  int  $itemId  商品ID
     * @param  int|null  $excludeId  除外するID（編集時）
     * @return array{valid: bool, message: string, existing: ?WmsOrderCandidate}
     */
    public function validateManualOrderDuplicate(int $warehouseId, int $itemId, ?int $excludeId = null): array
    {
        $query = WmsOrderCandidate::where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', [
                CandidateStatus::PENDING,
                CandidateStatus::APPROVED,
                CandidateStatus::CONFIRMED,
            ]);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->first();

        if ($existing) {
            return [
                'valid' => false,
                'message' => "同じ倉庫・商品の発注候補が既に存在します（ステータス: {$existing->status->label()}）",
                'existing' => $existing,
            ];
        }

        return [
            'valid' => true,
            'message' => '重複なし',
            'existing' => null,
        ];
    }

    /**
     * バッチ内のステータスサマリーを取得
     *
     * @param  string  $batchCode  バッチコード
     * @return Collection<string, int>
     */
    public function getBatchStatusSummary(string $batchCode): Collection
    {
        return WmsOrderCandidate::where('batch_code', $batchCode)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
    }

    /**
     * 発注候補が編集可能かチェック
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @return array{valid: bool, message: string}
     */
    public function validateCandidateEditable(WmsOrderCandidate $candidate): array
    {
        if (! $candidate->status->isEditable()) {
            return [
                'valid' => false,
                'message' => "ステータスが「{$candidate->status->label()}」のため編集できません",
            ];
        }

        return [
            'valid' => true,
            'message' => '編集可能',
        ];
    }

    /**
     * 発注数量の変更可否チェック（承認後は変更不可）
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @return array{valid: bool, message: string}
     */
    public function validateQuantityEditable(WmsOrderCandidate $candidate): array
    {
        // PENDINGのみ数量変更可能
        if ($candidate->status !== CandidateStatus::PENDING) {
            return [
                'valid' => false,
                'message' => "承認後は発注数量を変更できません（現在のステータス: {$candidate->status->label()}）",
            ];
        }

        return [
            'valid' => true,
            'message' => '変更可能',
        ];
    }
}
