/*
 * Filtr firm (Etap 10): wszystkie firmy, jedna wybrana albo lista wybranych.
 * Wybór zapisuje serwer przy koncie (api/preferences.php) i od razu zawęża listy, pulpit i zadania.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    const state = window.Vue.reactive({ loaded: false, mode: 'all', ids: [], available: [] });
    CertiSub.companyFilter = state;

    async function load() {
        try {
            const data = await api.get(endpoints.preferences, { view: 'company_filter' });
            Object.assign(state, data.company_filter, { loaded: true });
        } catch (error) {
            CertiSub.notifyError(error);
        }
    }

    CertiSub.components.CompanyFilterBar = {
        data() {
            return {
                state,
                open: false,
                draftMode: 'all',
                draftOne: '',
                draftList: [],
                search: '',
                saving: false,
                error: '',
            };
        },
        computed: {
            summary() {
                const count = this.state.available.length;
                if (this.state.mode === 'one') {
                    const company = this.state.available.find((item) => item.id === this.state.ids[0]);
                    return t('filter.summary.one', { name: company ? company.company_name : '#' + this.state.ids[0] });
                }
                if (this.state.mode === 'list') {
                    const selected = this.state.available.filter((item) => this.state.ids.indexOf(item.id) !== -1).length;
                    return t('filter.summary.list', { selected, total: count });
                }
                return t('filter.summary.all', { count });
            },
            active() {
                return this.state.mode !== 'all';
            },
            visibleCompanies() {
                const query = this.search.trim().toLowerCase();
                if (!query) {
                    return this.state.available;
                }
                return this.state.available.filter((item) => [item.company_name, item.tax_id, item.city]
                    .some((value) => value && String(value).toLowerCase().indexOf(query) !== -1));
            },
            canApply() {
                if (this.draftMode === 'one') {
                    return this.draftOne !== '';
                }
                if (this.draftMode === 'list') {
                    return this.draftList.length > 0;
                }
                return true;
            },
        },
        mounted() {
            if (!state.loaded) {
                load();
            }
        },
        methods: {
            toggle() {
                if (!this.open) {
                    this.draftMode = this.state.mode;
                    this.draftOne = this.state.mode === 'one' && this.state.ids.length ? String(this.state.ids[0]) : '';
                    this.draftList = this.state.mode === 'list' ? this.state.ids.slice() : [];
                    this.search = '';
                    this.error = '';
                }
                this.open = !this.open;
            },
            selectVisible() {
                const merged = new Set(this.draftList);
                this.visibleCompanies.forEach((item) => merged.add(item.id));
                this.draftList = Array.from(merged);
            },
            clearSelection() {
                this.draftList = [];
            },
            async save(mode, ids) {
                this.saving = true;
                this.error = '';
                try {
                    const data = await api.post(endpoints.preferences, { action: 'set_company_filter', data: { mode, ids } });
                    Object.assign(state, data.company_filter, { loaded: true });
                    this.open = false;
                    CertiSub.notify(t('filter.saved'));
                    // Otwarty widok ładuje się od nowa z nowym zakresem, a listy w pamięci są odświeżane.
                    CertiSub.ui.filterNonce += 1;
                    CertiSub.data.refresh('certificates', 'beneficiaries', 'payers', 'tasks', 'invitations', 'summary', 'taskStats');
                } catch (error) {
                    this.error = (error.errors && error.errors.ids) || error.message;
                } finally {
                    this.saving = false;
                }
            },
            apply() {
                if (this.draftMode === 'one') {
                    this.save('one', [Number(this.draftOne)]);
                } else if (this.draftMode === 'list') {
                    this.save('list', this.draftList);
                } else {
                    this.save('all', []);
                }
            },
            clear() {
                this.save('all', []);
            },
        },
        template: `
            <div class="no-print relative">
                <div :class="['flex flex-wrap items-center gap-3 rounded-lg border px-4 py-2 text-sm', active ? 'bg-brand-50 border-brand-200' : 'bg-white border-slate-200']">
                    <span class="font-semibold text-slate-500 uppercase text-xs tracking-wider">🏢 {{ t('filter.label') }}</span>
                    <span :class="['font-medium', active ? 'text-brand-800' : 'text-slate-700']">{{ state.loaded ? summary : '…' }}</span>
                    <span class="ml-auto flex items-center gap-2">
                        <button v-if="active" type="button" class="btn-ghost text-slate-600" :disabled="saving" @click="clear">✕ {{ t('filter.clear') }}</button>
                        <button type="button" class="btn-secondary" :aria-expanded="open" :disabled="!state.loaded" @click="toggle">{{ t('filter.change') }}</button>
                    </span>
                </div>

                <div v-if="open" class="absolute z-30 left-0 right-0 sm:right-auto sm:w-[28rem] mt-2 card p-4 shadow-lg" role="dialog" :aria-label="t('filter.label')">
                    <div v-if="error" class="mb-3 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</div>

                    <fieldset class="space-y-2">
                        <legend class="sr-only">{{ t('filter.label') }}</legend>
                        <label class="flex items-center gap-2 text-sm"><input v-model="draftMode" type="radio" value="all"> {{ t('filter.all') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="draftMode" type="radio" value="one"> {{ t('filter.one') }}</label>
                        <select v-if="draftMode === 'one'" v-model="draftOne" class="input" :aria-label="t('filter.one')">
                            <option value="">{{ t('filter.choose_company') }}</option>
                            <option v-for="item in state.available" :key="item.id" :value="String(item.id)">{{ item.company_name }}{{ item.city ? ' — ' + item.city : '' }}</option>
                        </select>
                        <label class="flex items-center gap-2 text-sm"><input v-model="draftMode" type="radio" value="list"> {{ t('filter.list') }}</label>
                    </fieldset>

                    <div v-if="draftMode === 'list'" class="mt-3">
                        <input v-model="search" type="search" class="input mb-2" :placeholder="t('filter.search')" :aria-label="t('filter.search')">
                        <div class="flex gap-3 text-xs mb-2">
                            <button type="button" class="text-brand-700 hover:underline" @click="selectVisible">{{ t('filter.select_all') }}</button>
                            <button type="button" class="text-slate-500 hover:underline" @click="clearSelection">{{ t('filter.select_none') }}</button>
                            <span class="ml-auto text-slate-400">{{ draftList.length }} / {{ state.available.length }}</span>
                        </div>
                        <div class="max-h-56 overflow-y-auto border border-slate-200 rounded-lg divide-y divide-slate-100">
                            <label v-for="item in visibleCompanies" :key="item.id" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50 cursor-pointer">
                                <input v-model="draftList" type="checkbox" :value="item.id" class="rounded border-slate-300">
                                <span>{{ item.company_name }}<span v-if="item.city" class="text-xs text-slate-500"> — {{ item.city }}</span></span>
                            </label>
                            <p v-if="visibleCompanies.length === 0" class="px-3 py-4 text-sm text-slate-400 text-center">{{ t('filter.none_available') }}</p>
                        </div>
                    </div>

                    <p class="text-xs text-slate-400 mt-3">{{ t('filter.hint') }}</p>
                    <div class="flex justify-end gap-2 mt-3">
                        <button type="button" class="btn-secondary" :disabled="saving" @click="open = false">{{ t('common.cancel') }}</button>
                        <button type="button" class="btn-primary" :disabled="saving || !canApply" @click="apply">{{ saving ? t('common.saving') : t('filter.apply') }}</button>
                    </div>
                </div>
            </div>
        `,
    };
})(window.CertiSub);
