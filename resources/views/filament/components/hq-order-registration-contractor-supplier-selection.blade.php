{{--
    外部発注（本部）専用: 外部発注候補生成の「発注先」「仕入先」選択。

    - 左: 発注先（EOS・FAX の区別なく1つにまとめて表示。EOS 対応の発注先にはバッジを付ける）
    - 右: 仕入先（チェックした発注先に紐づく仕入先だけを表示。仕入先でさらに絞り込める）
--}}
@php
    $lw = $lw ?? (isset($getLivewire) ? $getLivewire() : null);
    $contractors = $lw?->hqExternalOrderContractors() ?? [];
    $supplierSelection = $lw?->hqSupplierSelectionData() ?? ['suppliers' => [], 'links' => []];
    $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    $jsonElementPrefix = uniqid('hq-order-registration-selection-', false);
    $contractorsJsonElementId = "{$jsonElementPrefix}-contractors";
    $suppliersJsonElementId = "{$jsonElementPrefix}-suppliers";
    $linksJsonElementId = "{$jsonElementPrefix}-links";
@endphp

<div wire:ignore>
    <script type="application/json" id="{{ $contractorsJsonElementId }}">@json($contractors, $jsonOptions)</script>
    <script type="application/json" id="{{ $suppliersJsonElementId }}">@json($supplierSelection['suppliers'] ?? [], $jsonOptions)</script>
    <script type="application/json" id="{{ $linksJsonElementId }}">@json((object) ($supplierSelection['links'] ?? []), $jsonOptions)</script>

    <div
        x-data="{
            contractorQuery: '',
            supplierQuery: '',
            contractors: [],
            suppliers: [],
            links: {},
            selectedContractorIds: $wire.$entangle('selectedExternalOrderContractorIds'),
            selectedSupplierIds: $wire.$entangle('selectedHqSupplierIds'),

            readJsonElement(elementId, fallback) {
                try {
                    const raw = document.getElementById(elementId)?.textContent || '';
                    return raw ? JSON.parse(raw) : fallback;
                } catch (error) {
                    console.error('発注先・仕入先の初期データを読み込めませんでした。', error);
                    return fallback;
                }
            },

            init() {
                this.contractors = this.readJsonElement(@js($contractorsJsonElementId), []);
                this.suppliers = this.readJsonElement(@js($suppliersJsonElementId), []);
                this.links = this.readJsonElement(@js($linksJsonElementId), {});

                if (!Array.isArray(this.selectedContractorIds)) this.selectedContractorIds = [];
                if (!Array.isArray(this.selectedSupplierIds)) this.selectedSupplierIds = [];
            },

            normalize(value) {
                return String(value ?? '').normalize('NFKC').toLowerCase();
            },

            matches(row, query) {
                if (!query) return true;
                const q = this.normalize(query);

                return this.normalize(row.code).includes(q) || this.normalize(row.name).includes(q);
            },

            get filteredContractors() {
                return this.contractors.filter(contractor => this.matches(contractor, this.contractorQuery));
            },

            get linkedSupplierIds() {
                const ids = new Set();
                for (const contractorId of this.selectedContractorIds) {
                    for (const supplierId of (this.links[String(contractorId)] || [])) {
                        ids.add(Number(supplierId));
                    }
                }

                return ids;
            },

            get linkedSuppliers() {
                const ids = this.linkedSupplierIds;

                return this.suppliers.filter(supplier => ids.has(Number(supplier.id)));
            },

            get filteredSuppliers() {
                return this.linkedSuppliers.filter(supplier => this.matches(supplier, this.supplierQuery));
            },

            get selectedSupplierCount() {
                const ids = this.linkedSupplierIds;

                return this.selectedSupplierIds.filter(id => ids.has(Number(id))).length;
            },

            isContractorSelected(id) {
                return this.selectedContractorIds.map(Number).includes(Number(id));
            },

            isSupplierSelected(id) {
                return this.selectedSupplierIds.map(Number).includes(Number(id));
            },

            // 発注先の選択を変えたら、紐づく仕入先の選択も合わせる。
            // 新しくチェックした発注先の仕入先は選択済みにし、紐づきが無くなった仕入先は外す。
            setContractorIds(nextIds) {
                const previous = new Set(this.selectedContractorIds.map(Number));
                const next = [...new Set(nextIds.map(Number))];
                this.selectedContractorIds = next;

                const linked = this.linkedSupplierIds;
                const supplierIds = new Set(this.selectedSupplierIds.map(Number).filter(id => linked.has(id)));
                for (const contractorId of next) {
                    if (previous.has(contractorId)) continue;
                    for (const supplierId of (this.links[String(contractorId)] || [])) {
                        supplierIds.add(Number(supplierId));
                    }
                }
                this.selectedSupplierIds = [...supplierIds];
            },

            toggleContractor(id) {
                const normalizedId = Number(id);
                const current = this.selectedContractorIds.map(Number);
                this.setContractorIds(
                    current.includes(normalizedId)
                        ? current.filter(selectedId => selectedId !== normalizedId)
                        : [...current, normalizedId]
                );
            },

            selectFilteredContractors() {
                this.setContractorIds([
                    ...this.selectedContractorIds.map(Number),
                    ...this.filteredContractors.map(contractor => Number(contractor.id)),
                ]);
            },

            deselectFilteredContractors() {
                const ids = this.filteredContractors.map(contractor => Number(contractor.id));
                this.setContractorIds(this.selectedContractorIds.map(Number).filter(id => !ids.includes(id)));
            },

            toggleSupplier(id) {
                const normalizedId = Number(id);
                const current = this.selectedSupplierIds.map(Number);
                this.selectedSupplierIds = current.includes(normalizedId)
                    ? current.filter(selectedId => selectedId !== normalizedId)
                    : [...current, normalizedId];
            },

            selectFilteredSuppliers() {
                const ids = this.filteredSuppliers.map(supplier => Number(supplier.id));
                this.selectedSupplierIds = [...new Set([...this.selectedSupplierIds.map(Number), ...ids])];
            },

            deselectFilteredSuppliers() {
                const ids = this.filteredSuppliers.map(supplier => Number(supplier.id));
                this.selectedSupplierIds = this.selectedSupplierIds.map(Number).filter(id => !ids.includes(id));
            },
        }"
        class="flex flex-col gap-3 overflow-hidden"
    >
        <div class="flex flex-wrap items-center gap-2 rounded-md border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex shrink-0 items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm font-bold text-amber-600 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                <x-heroicon-m-check-circle class="h-4 w-4" />
                <span x-text="`発注先 ${selectedContractorIds.length}/${contractors.length}件 選択中`"></span>
            </div>
            <div class="flex shrink-0 items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm font-bold text-amber-600 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                <x-heroicon-m-check-circle class="h-4 w-4" />
                <span x-text="`仕入先 ${selectedSupplierCount}/${linkedSuppliers.length}件 選択中`"></span>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">発注先をチェックすると、紐づく仕入先が右に表示されます。</div>
        </div>

        <div class="grid h-[24rem] min-h-0 grid-cols-2 gap-3">
            {{-- 発注先 --}}
            <div class="flex min-w-0 flex-col overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" data-hq-contractor-list>
                <div class="space-y-2 border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-bold text-gray-800 dark:text-gray-100">発注先</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${filteredContractors.length}件`"></div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <button type="button"
                                    @click="selectFilteredContractors()"
                                    class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                全選択
                            </button>
                            <button type="button"
                                    @click="deselectFilteredContractors()"
                                    class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                解除
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <input type="text"
                               x-model="contractorQuery"
                               placeholder="発注先CD・名前で検索..."
                               class="w-full rounded-md border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <x-heroicon-m-magnifying-glass class="h-4 w-4 text-gray-400" />
                        </div>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto bg-amber-50/30 dark:bg-slate-900">
                    <template x-if="filteredContractors.length === 0">
                        <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する発注先がありません</div>
                    </template>
                    <div class="grid grid-cols-1">
                        <template x-for="(contractor, index) in filteredContractors" :key="`contractor-${contractor.id}`">
                            <label class="flex cursor-pointer items-center gap-2 border-b border-gray-100 px-3 py-2 text-sm transition hover:bg-amber-100/50 dark:border-gray-800 dark:hover:bg-slate-800"
                                   :class="index % 2 === 0 ? 'bg-amber-50/50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950'">
                                <input type="checkbox"
                                       :checked="isContractorSelected(contractor.id)"
                                       @change="toggleContractor(contractor.id)"
                                       class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-500 dark:bg-gray-700">
                                <span class="shrink-0 font-mono text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                                <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" x-text="contractor.name"></span>
                                <span x-show="contractor.is_eos"
                                      class="shrink-0 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
                                    EOS
                                </span>
                                <span x-show="contractor.transmission_parent_code"
                                      class="shrink-0 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-600 dark:bg-orange-900/30 dark:text-orange-300">
                                    集約元
                                </span>
                                <span x-show="!contractor.is_eos && contractor.generation_time"
                                      class="shrink-0 font-mono text-xs text-gray-400 dark:text-gray-500"
                                      x-text="contractor.generation_time"></span>
                            </label>
                        </template>
                    </div>
                </div>
            </div>

            {{-- 仕入先 --}}
            <div class="flex min-w-0 flex-col overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" data-hq-supplier-list>
                <div class="space-y-2 border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-bold text-gray-800 dark:text-gray-100">仕入先</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${filteredSuppliers.length}件`"></div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <button type="button"
                                    @click="selectFilteredSuppliers()"
                                    class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                全選択
                            </button>
                            <button type="button"
                                    @click="deselectFilteredSuppliers()"
                                    class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                解除
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <input type="text"
                               x-model="supplierQuery"
                               placeholder="仕入先CD・名前で検索..."
                               class="w-full rounded-md border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <x-heroicon-m-magnifying-glass class="h-4 w-4 text-gray-400" />
                        </div>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto bg-amber-50/30 dark:bg-slate-900">
                    <template x-if="selectedContractorIds.length === 0">
                        <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">発注先を選択すると、紐づく仕入先が表示されます</div>
                    </template>
                    <template x-if="selectedContractorIds.length > 0 && filteredSuppliers.length === 0">
                        <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する仕入先がありません</div>
                    </template>
                    <div class="grid grid-cols-1">
                        <template x-for="(supplier, index) in filteredSuppliers" :key="`supplier-${supplier.id}`">
                            <label class="flex cursor-pointer items-center gap-2 border-b border-gray-100 px-3 py-2 text-sm transition hover:bg-amber-100/50 dark:border-gray-800 dark:hover:bg-slate-800"
                                   :class="index % 2 === 0 ? 'bg-amber-50/50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950'">
                                <input type="checkbox"
                                       :checked="isSupplierSelected(supplier.id)"
                                       @change="toggleSupplier(supplier.id)"
                                       class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-500 dark:bg-gray-700">
                                <span class="shrink-0 font-mono text-gray-500 dark:text-gray-400" x-text="supplier.code"></span>
                                <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" x-text="supplier.name"></span>
                            </label>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="selectedContractorIds.length === 0" class="text-sm text-danger-600 dark:text-danger-400">
            ※ 最低1つの発注先を選択してください
        </div>
        <div x-show="selectedContractorIds.length > 0 && selectedSupplierCount === 0" class="text-sm text-danger-600 dark:text-danger-400">
            ※ 最低1つの仕入先を選択してください
        </div>
    </div>
</div>
