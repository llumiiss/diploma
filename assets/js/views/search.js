/*
 * Wyszukiwarka globalna (F16): pole w górnym pasku panelu (skrót Ctrl+K lub „/”) z podpowiedziami
 * oraz pełna lista wyników. Każdy wynik pokazuje powiązania (osoba, płatnik, opiekun)
 * i powód dopasowania — także wtedy, gdy rekord pasuje przez powiązany rekord.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints, format, labels } = CertiSub;

    const GROUPS = ['certificates', 'beneficiaries', 'payers'];

    /** Dopasowania przez powiązany rekord (pozostałe to pola samego rekordu). */
    const RELATION_MATCHES = {
        certificates: ['beneficiary', 'payer', 'owner'],
        beneficiaries: ['payer', 'certificate'],
        payers: ['beneficiary', 'certificate'],
    };

    /**
     * Karta osoby lub płatnika dla ról z dostępem do raportów, w pozostałych przypadkach panel szczegółów.
     */
    CertiSub.openBeneficiaryCard = function (id) {
        if (CertiSub.can('reports.view')) {
            CertiSub.navigate('beneficiaries', id);
        } else {
            CertiSub.openDrawer('beneficiary', id);
        }
    };

    CertiSub.openPayerCard = function (id) {
        if (CertiSub.can('reports.view')) {
            CertiSub.navigate('payers', id);
        } else {
            CertiSub.openDrawer('payer', id);
        }
    };

    CertiSub.search = {
        groups: GROUPS,
        open(group, item) {
            if (group === 'certificates') {
                CertiSub.openDrawer('certificate', item.id);
            } else if (group === 'beneficiaries') {
                CertiSub.openBeneficiaryCard(item.id);
            } else {
                CertiSub.openPayerCard(item.id);
            }
        },
        title(group, item) {
            if (group === 'certificates') {
                return item.name;
            }
            return group === 'beneficiaries' ? format.person(item.first_name, item.last_name) : item.company_name;
        },
        subtitle(group, item) {
            let parts;
            if (group === 'certificates') {
                parts = [labels.type(item.certificate_type), item.serial_number, item.issuer];
            } else if (group === 'beneficiaries') {
                parts = [item.email, item.phone];
            } else {
                parts = [item.tax_id ? t('field.tax_id') + ' ' + item.tax_id : null, item.city, item.contact_person];
            }
            return parts.filter(Boolean).join(' · ');
        },
        /**
         * Powiązania wyniku: [{icon, text, open}] — open otwiera powiązany rekord (null = sam opis).
         */
        relations(group, item) {
            const relations = [];
            if (group === 'certificates') {
                if (item.beneficiary) {
                    relations.push({ icon: '👤', text: item.beneficiary.name, open: () => CertiSub.openBeneficiaryCard(item.beneficiary.id) });
                }
                relations.push({ icon: '🏢', text: item.payer.name, open: () => CertiSub.openPayerCard(item.payer.id) });
                relations.push({ icon: '🧑‍💼', text: t('search.owner', { name: item.owner_name }), open: null });
                relations.push({ icon: '📅', text: format.date(item.expiry_date) + ' · ' + format.days(item.days_left), open: null });
            } else if (group === 'beneficiaries') {
                if (item.payer) {
                    relations.push({ icon: '🏢', text: item.payer.name, open: () => CertiSub.openPayerCard(item.payer.id) });
                }
                relations.push({ icon: '📜', text: t('search.certificate_count', { count: item.certificate_count }), open: null });
                if (item.next_expiry) {
                    relations.push({ icon: '📅', text: t('search.next_expiry', { date: format.date(item.next_expiry) }), open: null });
                }
            } else {
                relations.push({ icon: '👤', text: t('search.beneficiary_count', { count: item.beneficiary_count }), open: null });
                relations.push({ icon: '📜', text: t('search.certificate_count', { count: item.certificate_count }), open: null });
            }
            return relations;
        },
        matched(group, item) {
            const relationFields = RELATION_MATCHES[group];
            const direct = item.matched.filter((field) => relationFields.indexOf(field) === -1).map((field) => t('search.match.' + field));
            const related = item.matched.filter((field) => relationFields.indexOf(field) !== -1).map((field) => t('search.match.' + field));
            const parts = [];
            if (direct.length) {
                parts.push(t('search.matched', { fields: direct.join(', ') }));
            }
            if (related.length) {
                parts.push(t('search.matched_relation', { fields: related.join(', ') }));
            }
            return parts.join(' · ');
        },
    };

    CertiSub.components.GlobalSearch = {
        data() {
            return { query: '', result: null, loading: false, open: false, active: -1, error: '', sequence: 0 };
        },
        computed: {
            entries() {
                if (!this.result) {
                    return [];
                }
                const entries = [];
                GROUPS.forEach((group) => {
                    this.result.groups[group].items.forEach((item) => {
                        entries.push({ group, item, index: entries.length });
                    });
                });
                return entries;
            },
            sections() {
                if (!this.result) {
                    return [];
                }
                return GROUPS
                    .map((group) => ({
                        group,
                        total: this.result.groups[group].total,
                        entries: this.entries.filter((entry) => entry.group === group),
                    }))
                    .filter((section) => section.total > 0);
            },
            totalCount() {
                return this.sections.reduce((sum, section) => sum + section.total, 0);
            },
            ready() {
                return this.query.trim().length >= 2;
            },
            shortcut() {
                return /Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘K' : 'Ctrl+K';
            },
        },
        watch: {
            query() {
                this.active = -1;
                if (!this.ready) {
                    this.sequence += 1;
                    this.result = null;
                    this.loading = false;
                    this.error = '';
                    return;
                }
                this.loading = true;
                this.open = true;
                this.runSearch();
            },
        },
        created() {
            this.runSearch = CertiSub.debounce(() => this.fetch(), 250);
        },
        mounted() {
            document.addEventListener('keydown', this.onShortcut);
            document.addEventListener('mousedown', this.onOutside);
        },
        beforeUnmount() {
            document.removeEventListener('keydown', this.onShortcut);
            document.removeEventListener('mousedown', this.onOutside);
        },
        methods: {
            async fetch() {
                const sequence = ++this.sequence;
                try {
                    const data = await api.get(endpoints.search, { q: this.query.trim(), limit: 5 });
                    if (sequence === this.sequence) {
                        this.result = data.search;
                        this.error = '';
                    }
                } catch (error) {
                    if (sequence === this.sequence) {
                        this.error = error.message;
                    }
                } finally {
                    if (sequence === this.sequence) {
                        this.loading = false;
                    }
                }
            },
            onShortcut(event) {
                const target = event.target;
                const typing = target && (['INPUT', 'TEXTAREA', 'SELECT'].indexOf(target.tagName) !== -1 || target.isContentEditable);
                if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
                    event.preventDefault();
                    this.focus();
                } else if (event.key === '/' && !typing && CertiSub.ui.modals.length === 0 && !CertiSub.ui.drawer) {
                    event.preventDefault();
                    this.focus();
                }
            },
            onOutside(event) {
                if (this.open && !this.$el.contains(event.target)) {
                    this.open = false;
                }
            },
            focus() {
                this.$refs.input.focus();
                this.$refs.input.select();
                if (this.ready) {
                    this.open = true;
                }
            },
            onKeydown(event) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.open = this.ready;
                    this.active = Math.min(this.active + 1, this.entries.length - 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.active = Math.max(this.active - 1, -1);
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    if (this.active >= 0 && this.entries[this.active]) {
                        this.choose(this.entries[this.active]);
                    } else {
                        this.showAll();
                    }
                } else if (event.key === 'Escape') {
                    event.stopPropagation();
                    if (this.open) {
                        this.open = false;
                    } else {
                        this.query = '';
                        this.$refs.input.blur();
                    }
                }
            },
            choose(entry) {
                this.open = false;
                this.$refs.input.blur();
                CertiSub.search.open(entry.group, entry.item);
            },
            showAll() {
                if (!this.ready) {
                    return;
                }
                CertiSub.ui.searchQuery = this.query.trim();
                this.open = false;
                this.$refs.input.blur();
                if (CertiSub.ui.view === 'search') {
                    CertiSub.ui.searchNonce = Date.now();
                } else {
                    CertiSub.navigate('search');
                }
            },
            optionId(index) {
                return 'global-search-option-' + index;
            },
        },
        template: `
            <div class="relative w-full max-w-2xl no-print">
                <label for="global-search-input" class="sr-only">{{ t('search.label') }}</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">🔍</span>
                    <input id="global-search-input" ref="input" v-model="query" type="search" autocomplete="off" spellcheck="false"
                           class="input pl-9 pr-20" :placeholder="t('search.placeholder')" maxlength="100"
                           role="combobox" aria-autocomplete="list" aria-controls="global-search-results"
                           :aria-expanded="open && ready ? 'true' : 'false'"
                           :aria-activedescendant="active >= 0 ? optionId(active) : null"
                           @focus="open = ready" @keydown="onKeydown">
                    <kbd class="hidden sm:block absolute right-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400 border border-slate-200 rounded px-1.5 py-0.5 bg-slate-50" aria-hidden="true">{{ shortcut }}</kbd>
                </div>

                <div v-if="open && ready" class="absolute left-0 right-0 mt-2 z-30 card shadow-xl max-h-[70vh] overflow-y-auto">
                    <p v-if="loading && !result" class="px-4 py-6 text-sm text-slate-400 text-center" role="status">{{ t('search.searching') }}</p>
                    <p v-else-if="error" class="px-4 py-4 text-sm text-red-600" role="alert">{{ error }}</p>
                    <p v-else-if="result && totalCount === 0" class="px-4 py-6 text-sm text-slate-500 text-center" role="status">{{ t('search.no_results', { query: result.query }) }}</p>
                    <ul id="global-search-results" role="listbox" :aria-label="t('search.results')" :class="loading ? 'opacity-60' : ''">
                        <template v-for="section in sections" :key="section.group">
                            <li role="presentation" class="px-4 pt-3 pb-1 text-xs font-semibold text-slate-400 uppercase tracking-wider flex justify-between">
                                <span>{{ t('search.group.' + section.group) }}</span>
                                <span>{{ section.total }}</span>
                            </li>
                            <li v-for="entry in section.entries" :key="section.group + entry.item.id" :id="optionId(entry.index)" role="option"
                                :aria-selected="active === entry.index ? 'true' : 'false'"
                                :class="['px-4 py-2.5 cursor-pointer border-l-2', active === entry.index ? 'bg-brand-50 border-brand-500' : 'border-transparent hover:bg-slate-50']"
                                @mousemove="active = entry.index" @click="choose(entry)">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-medium text-slate-800 truncate">{{ CertiSub.search.title(entry.group, entry.item) }}</span>
                                    <span v-if="entry.item.archived_at" class="badge bg-slate-200 text-slate-600">{{ t('common.archived') }}</span>
                                    <span v-else-if="entry.item.priority && entry.item.priority !== 'ok'" :class="['badge', badge.priority(entry.item.priority)]">{{ labels.priority(entry.item.priority) }}</span>
                                </div>
                                <div v-if="CertiSub.search.subtitle(entry.group, entry.item)" class="text-xs text-slate-500 truncate">{{ CertiSub.search.subtitle(entry.group, entry.item) }}</div>
                                <div class="text-xs text-slate-600 truncate">
                                    <span v-for="(relation, index) in CertiSub.search.relations(entry.group, entry.item)" :key="index" class="mr-3">{{ relation.icon }} {{ relation.text }}</span>
                                </div>
                                <div class="text-[11px] text-slate-400 truncate">{{ CertiSub.search.matched(entry.group, entry.item) }}</div>
                            </li>
                        </template>
                    </ul>
                    <button v-if="result && totalCount > 0" type="button" class="w-full text-left px-4 py-3 border-t border-slate-100 text-sm text-brand-700 hover:bg-slate-50" @click="showAll">
                        {{ t('search.show_all', { count: totalCount }) }} ↵
                    </button>
                </div>
            </div>
        `,
    };

    /**
     * Pełna lista wyników (#/search) — do 50 rekordów w każdej grupie, powiązania jako odnośniki.
     */
    CertiSub.components.SearchView = {
        data() {
            return { ui: CertiSub.ui, query: CertiSub.ui.searchQuery || '', result: null, loading: false, error: '', sequence: 0 };
        },
        computed: {
            ready() {
                return this.query.trim().length >= 2;
            },
            sections() {
                if (!this.result) {
                    return [];
                }
                return GROUPS.map((group) => ({ group, ...this.result.groups[group] }));
            },
            totalCount() {
                return this.sections.reduce((sum, section) => sum + section.total, 0);
            },
        },
        watch: {
            query(value) {
                CertiSub.ui.searchQuery = value;
                this.runSearch();
            },
            'ui.searchNonce'() {
                this.query = CertiSub.ui.searchQuery;
                this.fetch();
            },
        },
        created() {
            this.runSearch = CertiSub.debounce(() => this.fetch(), 300);
        },
        mounted() {
            if (this.ready) {
                this.fetch();
            }
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
        },
        methods: {
            async fetch() {
                const sequence = ++this.sequence;
                if (!this.ready) {
                    this.result = null;
                    this.loading = false;
                    return;
                }
                this.loading = true;
                try {
                    const data = await api.get(endpoints.search, { q: this.query.trim(), limit: 50 });
                    if (sequence === this.sequence) {
                        this.result = data.search;
                        this.error = '';
                    }
                } catch (error) {
                    if (sequence === this.sequence) {
                        this.error = error.message;
                    }
                } finally {
                    if (sequence === this.sequence) {
                        this.loading = false;
                    }
                }
            },
            open(group, item) {
                CertiSub.search.open(group, item);
            },
        },
        template: `
            <section>
                <PageHeader :title="t('search.title')" :description="t('search.description')" />

                <div class="card p-4 mb-4">
                    <label for="search-view-input" class="sr-only">{{ t('search.label') }}</label>
                    <input id="search-view-input" ref="input" v-model="query" type="search" class="input" maxlength="100" autocomplete="off"
                           :placeholder="t('search.placeholder_full')">
                    <p class="text-xs text-slate-400 mt-2">{{ t('search.hint') }}</p>
                </div>

                <p v-if="!ready" class="text-sm text-slate-500">{{ t('search.too_short') }}</p>
                <p v-else-if="error" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</p>
                <p v-else-if="loading && !result" class="text-sm text-slate-400" role="status">{{ t('search.searching') }}</p>
                <p v-else-if="result && totalCount === 0" class="text-sm text-slate-500" role="status">{{ t('search.no_results', { query: result.query }) }}</p>

                <div v-if="ready && result && totalCount > 0" :class="['grid gap-4 xl:grid-cols-3', loading ? 'opacity-60' : '']">
                    <div v-for="section in sections" :key="section.group" class="card">
                        <h3 class="px-4 py-3 border-b border-slate-100 text-sm font-semibold text-slate-700 flex justify-between">
                            <span>{{ t('search.group.' + section.group) }}</span>
                            <span class="text-slate-400">{{ section.items.length < section.total ? t('search.shown_of', { shown: section.items.length, total: section.total }) : section.total }}</span>
                        </h3>
                        <p v-if="section.total === 0" class="px-4 py-6 text-sm text-slate-400 text-center">{{ t('search.group_empty') }}</p>
                        <ul class="divide-y divide-slate-100">
                            <li v-for="item in section.items" :key="item.id" class="px-4 py-3">
                                <button type="button" class="text-left w-full group" @click="open(section.group, item)">
                                    <span class="flex items-center gap-2 flex-wrap">
                                        <span class="font-medium text-slate-800 group-hover:text-brand-700 group-hover:underline">{{ CertiSub.search.title(section.group, item) }}</span>
                                        <span v-if="item.archived_at" class="badge bg-slate-200 text-slate-600">{{ t('common.archived') }}</span>
                                        <span v-else-if="item.priority && item.priority !== 'ok'" :class="['badge', badge.priority(item.priority)]">{{ labels.priority(item.priority) }}</span>
                                    </span>
                                    <span v-if="CertiSub.search.subtitle(section.group, item)" class="block text-xs text-slate-500 mt-0.5 break-words">{{ CertiSub.search.subtitle(section.group, item) }}</span>
                                </button>
                                <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                    <template v-for="(relation, index) in CertiSub.search.relations(section.group, item)" :key="index">
                                        <button v-if="relation.open" type="button" class="text-brand-700 hover:underline" @click="relation.open()">{{ relation.icon }} {{ relation.text }}</button>
                                        <span v-else class="text-slate-600">{{ relation.icon }} {{ relation.text }}</span>
                                    </template>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1">{{ CertiSub.search.matched(section.group, item) }}</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>
        `,
    };
})(window.CertiSub);
