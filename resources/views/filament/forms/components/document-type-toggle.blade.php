<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            tabs: [
                { id: 'shipment', label: '営業出荷' },
                { id: 'transfer', label: '物流出荷' },
            ],
            init() {
                if (!this.state) this.state = 'shipment';
            },
        }"
        x-init="init()"
        class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1 dark:border-gray-700 dark:bg-gray-900"
    >
        <template x-for="tab in tabs" :key="tab.id">
            <button
                type="button"
                class="px-4 py-1.5 text-sm font-semibold rounded-md transition-colors"
                :class="state === tab.id
                    ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-800 dark:text-blue-300'
                    : 'text-slate-500 hover:text-slate-800 dark:text-gray-400 dark:hover:text-gray-200'"
                @click="state = tab.id"
                x-text="tab.label"
            ></button>
        </template>
    </div>
</x-dynamic-component>
