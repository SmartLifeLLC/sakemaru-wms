<x-filament-panels::page>
    <style>
        /* 発注候補テーブル専用 */
        /* 倉庫カラム幅・中央整列 */
        .fi-ta-table colgroup col:nth-child(3) {
            width: 170px !important;
        }
        .fi-ta-table thead tr th:nth-child(3),
        .fi-ta-table tbody tr td:nth-child(3) {
            width: 170px !important;
            min-width: 170px !important;
            text-align: center !important;
        }
        /* 商品コードカラム中央整列 */
        .fi-ta-table thead tr th:nth-child(4),
        .fi-ta-table tbody tr td:nth-child(4) {
            text-align: center !important;
        }
        .fi-ta-table thead tr th:nth-child(3) > div,
        .fi-ta-table thead tr th:nth-child(4) > div {
            justify-content: center !important;
        }
    </style>

    {{ $this->table }}
</x-filament-panels::page>
