<x-filament-panels::page>
    <style>
        /* Target the main table wrapper */
        .fi-ta {
            position: relative;
            display: flex;
            flex-direction: column;
            height: calc(100vh - 10rem);
        }

        /* Table container - make it scrollable */
        .fi-ta-ctn {
            flex: 1;
            overflow-y: auto;
            overflow-x: auto;
        }

        /* Make thead sticky at the top */
        .fi-ta thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: white;
        }

        /* Dark mode support for thead */
        .dark .fi-ta thead {
            background-color: rgb(17 24 39);
        }

        /* Ensure each th has proper background */
        .fi-ta thead th {
            background-color: inherit;
        }

        /* Footer section - keep it at bottom */
        .fi-ta-footer {
            flex-shrink: 0;
            background-color: white;
            border-top: 1px solid rgb(229 231 235);
        }

        /* Dark mode support for footer */
        .dark .fi-ta-footer {
            background-color: rgb(17 24 39);
            border-top-color: rgb(55 65 81);
        }
    </style>
    <x-advanced-tables::favorites-bar />

    {{ $this->table }}
</x-filament-panels::page>
