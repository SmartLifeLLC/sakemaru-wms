<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * 発注ファイル生成インターフェース
 *
 * 顧客ごとに異なる発注ファイルフォーマットに対応するためのインターフェース。
 * 各顧客用の実装クラスは app/Services/AutoOrder/Generators/ に配置する。
 */
interface OrderFileGeneratorInterface
{
    /**
     * 発注データから送信用ファイルを生成
     *
     * @param  Collection  $orderCandidates  発注候補（EXECUTED状態のもの）
     * @return array<int, array{
     *     contractor_id: int,
     *     jx_setting_id: int|null,
     *     content: string,
     *     filename: string,
     *     encoding: string,
     *     record_count: int,
     *     order_count: int
     * }>
     */
    public function generate(Collection $orderCandidates): array;

    /**
     * JX送信対象の発注先IDを取得
     *
     * @return array<int>
     */
    public function getJxTransmissionContractorIds(): array;

    /**
     * 発注データ集約先IDを取得（transmission_contractor_id対応）
     *
     * 複数の発注先が同一の送信先に集約される場合のマッピングを返す。
     *
     * @return array<int, int> [発注先ID => 発注データ集約先ID]
     */
    public function getTransmissionContractorMapping(): array;

    /**
     * ファイルのエンコーディングを取得
     */
    public function getEncoding(): string;

    /**
     * ファイルの改行コードを取得
     */
    public function getLineEnding(): string;

    /**
     * ファイル拡張子を取得
     */
    public function getFileExtension(): string;
}
