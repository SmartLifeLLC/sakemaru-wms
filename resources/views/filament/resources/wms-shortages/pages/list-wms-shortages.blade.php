<x-filament-panels::page>
    <style>
        .wms-shortages-list-page {
            height: calc(100dvh - 4rem);
            --wms-shortages-content-height: calc(100dvh - 13rem);
        }

        .wms-shortages-list-page .fi-ta {
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100% !important;
        }

        .wms-shortages-list-page .fi-ta-ctn {
            flex: 1 !important;
            height: 100% !important;
            min-height: 0 !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .wms-shortages-list-page .fi-ta-main {
            flex: 1 !important;
            height: 100% !important;
            min-height: 0 !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .wms-shortages-list-page .fi-ta-content-ctn {
            flex: 0 0 var(--wms-shortages-content-height) !important;
            min-height: 0 !important;
            height: var(--wms-shortages-content-height) !important;
            max-height: var(--wms-shortages-content-height) !important;
            overflow-y: auto !important;
            overflow-x: auto !important;
        }

        .wms-shortages-list-page .sticky-actions-left .fi-ta-table {
            max-height: none !important;
            overflow: visible !important;
        }

        .wms-shortages-list-page .fi-pagination {
            flex-shrink: 0 !important;
            margin-top: 0 !important;
            width: 100%;
        }

        .wms-shortages-list-page .fi-ta thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: white;
        }

        .dark .wms-shortages-list-page .fi-ta thead {
            background-color: rgb(17 24 39);
        }

        .wms-shortages-list-page .fi-ta thead th {
            background-color: inherit;
        }

        .wms-shortages-list-page .fi-ta-footer {
            flex-shrink: 0;
            background-color: white;
            border-top: 1px solid rgb(229 231 235);
        }

        .dark .wms-shortages-list-page .fi-ta-footer {
            background-color: rgb(17 24 39);
            border-top-color: rgb(55 65 81);
        }
    </style>
    <div class="wms-shortages-list-page">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
