/*
 * Archiwum (MANAGER, ADMIN): zarchiwizowane certyfikaty, użytkownicy certyfikatów i płatnicy
 * z możliwością przywrócenia. Nic nie jest usuwane — archiwizacja zachowuje historię (F5).
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    CertiSub.components.ArchiveView = {
        data() {
            return {
                tab: 'certificates',
                archive: { certificates: [], beneficiaries: [], payers: [] },
                loading: true,
                error: '',
                busyId: null,
                history: { items: [], total: 0, limit: 0 },
                historyLoading: false,
                historyLoaded: false,
                historyError: '',
                filters: { q: '', from: '', to: '', state: 'all' },
            };
        },
        computed: {
            tabs() {
                return [
                    { id: 'certificates', label: t('certificate.plural'), count: this.archive.certificates.length },
                    { id: 'beneficiaries', label: t('beneficiary.plural'), count: this.archive.beneficiaries.length },
                    { id: 'payers', label: t('payer.plural'), count: this.archive.payers.length },
                    { id: 'company_history', label: t('archive.history_tab'), count: this.historyLoaded ? this.history.total : '…' },
                ];
            },
            exportParams() {
                if (this.tab !== 'company_history') {
                    return { archived: 1 };
                }
                const params = {};
                Object.keys(this.filters).forEach((key) => {
                    if (this.filters[key] && !(key === 'state' && this.filters[key] === 'all')) {
                        params[key] = this.filters[key];
                    }
                });
                return params;
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const data = await api.get(endpoints.dashboard, { view: 'archive' });
                    this.archive = data.archive;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            async restore(kind, item) {
                const endpoint = { certificates: endpoints.certificates, beneficiaries: endpoints.beneficiaries, payers: endpoints.payers }[kind];
                const messageKey = { certificates: 'certificate.restored', beneficiaries: 'beneficiary.restored', payers: 'payer.restored' }[kind];
                this.busyId = kind + item.id;
                try {
                    await api.post(endpoint, { action: 'restore', id: item.id });
                    CertiSub.notify(t(messageKey));
                    CertiSub.data.refresh('certificates', 'beneficiaries', 'payers', 'summary', 'options');
                    await this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.busyId = null;
                }
            },
            open(type, item) {
                CertiSub.openDrawer(type, item.id);
            },
            selectTab(id) {
                this.tab = id;
                if (id === 'company_history' && !this.historyLoaded) {
                    this.searchHistory();
                }
            },
            async searchHistory() {
                this.historyLoading = true;
                this.historyError = '';
                try {
                    const params = Object.assign({ view: 'history' }, this.exportParams);
                    const data = await api.get(endpoints.beneficiaries, params);
                    this.history = data.history;
                    this.historyLoaded = true;
                } catch (error) {
                    this.historyError = error.message;
                } finally {
                    this.historyLoading = false;
                }
            },
            resetHistory() {
                this.filters = { q: '', from: '', to: '', state: 'all' };
                this.searchHistory();
            },
            async archivePerson(row) {
                const ok = await CertiSub.confirm({
                    title: t('beneficiary.archive_title'),
                    message: t('beneficiary.archive_confirm', { name: row.beneficiary_name }),
                    confirmLabel: t('common.archive'),
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                this.busyId = 'history' + row.id;
                try {
                    await api.post(endpoints.beneficiaries, { action: 'archive', id: row.beneficiary_id });
                    CertiSub.notify(t('beneficiary.archived'));
                    CertiSub.data.refresh('beneficiaries', 'payers', 'options');
                    await Promise.all([this.load(), this.searchHistory()]);
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.busyId = null;
                }
            },
            openPerson(row) {
                CertiSub.openDrawer('beneficiary', row.beneficiary_id);
            },
            openCompany(row) {
                CertiSub.openDrawer('payer', row.payer_id);
            },
            openCertificate(certificate) {
                CertiSub.openDrawer('certificate', certificate.id);
            },
            payerLine(certificate) {
                return certificate.payers.map((p) => p.payer_name + ' ' + CertiSub.format.date(p.started_on) + '–' + (p.ended_on ? CertiSub.format.date(p.ended_on) : t('employment.now'))).join('; ');
            },
        },
        template: `
            <section>
                <PageHeader :title="t('archive.title')" :description="t('archive.description')">
                    <ExportButtons :dataset="tab" :params="exportParams" />
                </PageHeader>

                <div class="flex flex-wrap gap-2 mb-4" role="tablist">
                    <button v-for="item in tabs" :key="item.id" type="button" role="tab" :aria-selected="tab === item.id"
                            :class="['px-4 py-2 rounded-lg text-sm font-medium border transition', tab === item.id ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']"
                            @click="selectTab(item.id)">
                        {{ item.label }} <span class="ml-1 opacity-75">({{ item.count }})</span>
                    </button>
                </div>

                <template v-if="tab === 'company_history'">
                    <form class="card p-4 mb-4 grid sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end" @submit.prevent="searchHistory">
                        <label class="sm:col-span-2 block">
                            <span class="block text-xs font-medium text-slate-500 mb-1">{{ t('common.search') }}</span>
                            <input v-model="filters.q" type="search" class="input" :placeholder="t('archive.history_search_placeholder')">
                        </label>
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-500 mb-1">{{ t('employment.from') }}</span>
                            <input v-model="filters.from" type="date" class="input">
                        </label>
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-500 mb-1">{{ t('employment.to') }}</span>
                            <input v-model="filters.to" type="date" class="input">
                        </label>
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-500 mb-1">{{ t('employment.status') }}</span>
                            <select v-model="filters.state" class="input">
                                <option value="all">{{ t('archive.history_state.all') }}</option>
                                <option value="current">{{ t('archive.history_state.current') }}</option>
                                <option value="past">{{ t('archive.history_state.past') }}</option>
                            </select>
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary" :disabled="historyLoading">🔎 {{ t('common.search') }}</button>
                            <button type="button" class="btn-secondary" :disabled="historyLoading" @click="resetHistory">{{ t('archive.history_clear') }}</button>
                        </div>
                        <p class="sm:col-span-2 lg:col-span-6 text-xs text-slate-500">{{ t('archive.history_hint') }}</p>
                    </form>

                    <div class="card overflow-x-auto">
                        <table class="w-full text-sm min-w-[980px]">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="th">{{ t('beneficiary.singular') }}</th>
                                    <th class="th">{{ t('payer.singular') }}</th>
                                    <th class="th">{{ t('employment.from') }}</th>
                                    <th class="th">{{ t('employment.to') }}</th>
                                    <th class="th">{{ t('certificate.plural') }}</th>
                                    <th class="th text-right">{{ t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in history.items" :key="row.id" class="border-b border-slate-100 align-top">
                                    <td class="td">
                                        <button type="button" class="font-medium text-slate-800 hover:underline text-left" @click="openPerson(row)">{{ row.beneficiary_name }}</button>
                                        <span v-if="row.beneficiary_archived_at" class="badge bg-slate-200 text-slate-600 ml-1">{{ t('common.archived') }}</span>
                                        <div class="text-xs text-slate-500">{{ row.email || '—' }}</div>
                                    </td>
                                    <td class="td">
                                        <button type="button" class="text-brand-700 hover:underline text-left" @click="openCompany(row)">{{ row.company_name }}</button>
                                        <div class="text-xs text-slate-500"><span v-if="row.tax_id">{{ t('field.tax_id') }} {{ row.tax_id }}</span><span v-if="row.payer_archived_at"> · {{ t('common.archived') }}</span></div>
                                        <span v-if="row.is_primary" class="badge bg-brand-50 text-brand-700">{{ t('employment.primary') }}</span>
                                        <div v-if="row.note" class="text-xs text-slate-500 italic">{{ row.note }}</div>
                                    </td>
                                    <td class="td whitespace-nowrap">{{ format.date(row.started_on) }}</td>
                                    <td class="td whitespace-nowrap">
                                        <span v-if="row.ended_on">{{ format.date(row.ended_on) }}</span>
                                        <span v-else class="badge bg-emerald-50 text-emerald-700">{{ t('employment.now') }}</span>
                                    </td>
                                    <td class="td">
                                        <div v-for="certificate in row.certificates" :key="certificate.id" class="mb-1">
                                            <button type="button" :class="['text-left hover:underline', certificate.archived_at ? 'text-slate-500' : 'text-slate-800']" @click="openCertificate(certificate)">
                                                {{ certificate.name }}<span v-if="certificate.serial_number" class="font-mono text-xs"> · {{ certificate.serial_number }}</span>
                                            </button>
                                            <span class="text-xs text-slate-500"> · {{ format.date(certificate.expiry_date) }}</span>
                                            <span v-if="certificate.paid_by_company" class="badge bg-amber-50 text-amber-800 ml-1" :title="t('archive.history_paid_hint')">💳 {{ t('archive.history_paid') }}</span>
                                            <div v-if="certificate.payers.length" class="text-xs text-slate-400">{{ payerLine(certificate) }}</div>
                                        </div>
                                        <span v-if="row.certificates.length === 0" class="text-slate-400">—</span>
                                    </td>
                                    <td class="td text-right whitespace-nowrap">
                                        <button v-if="!row.beneficiary_archived_at && can('beneficiaries.archive')" type="button" class="btn-ghost text-red-600"
                                                :disabled="busyId === 'history' + row.id" @click="archivePerson(row)">📦 {{ t('archive.history_archive_person') }}</button>
                                    </td>
                                </tr>
                                <TableState :colspan="6" :loading="historyLoading" :error="historyError" :empty="historyLoaded && history.items.length === 0" :empty-text="t('archive.history_empty')" />
                            </tbody>
                        </table>
                        <p v-if="history.total > history.items.length" class="px-4 py-3 text-xs text-slate-500">{{ t('archive.history_limited', { shown: history.items.length, total: history.total }) }}</p>
                    </div>
                </template>

                <div v-else class="card overflow-x-auto">
                    <table v-if="tab === 'certificates'" class="w-full text-sm min-w-[760px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('certificate.singular') }}</th>
                                <th class="th">{{ t('field.payer_id') }}</th>
                                <th class="th">{{ t('field.expiry_date') }}</th>
                                <th class="th">{{ t('archive.archived_at') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in archive.certificates" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open('certificate', item)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ item.name }}</div>
                                    <div class="text-xs text-slate-500">{{ item.type_label }}<span v-if="item.beneficiary_name"> · {{ item.beneficiary_name }}</span></div>
                                </td>
                                <td class="td">{{ item.company_name }}</td>
                                <td class="td whitespace-nowrap">{{ format.date(item.expiry_date) }}</td>
                                <td class="td whitespace-nowrap">{{ format.dateTime(item.archived_at) }}</td>
                                <td class="td text-right" @click.stop>
                                    <button type="button" class="btn-ghost" :disabled="busyId === 'certificates' + item.id" @click="restore('certificates', item)">♻️ {{ t('common.restore') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="5" :loading="loading" :error="error" :empty="archive.certificates.length === 0" :empty-text="t('archive.empty')" />
                        </tbody>
                    </table>

                    <table v-else-if="tab === 'beneficiaries'" class="w-full text-sm min-w-[640px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('beneficiary.singular') }}</th>
                                <th class="th">{{ t('field.payer_id') }}</th>
                                <th class="th">{{ t('archive.archived_at') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in archive.beneficiaries" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open('beneficiary', item)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ format.person(item.first_name, item.last_name) }}</div>
                                    <div class="text-xs text-slate-500">{{ item.email || '—' }}</div>
                                </td>
                                <td class="td">{{ item.payer_name || '—' }}</td>
                                <td class="td whitespace-nowrap">{{ format.dateTime(item.archived_at) }}</td>
                                <td class="td text-right" @click.stop>
                                    <button type="button" class="btn-ghost" :disabled="busyId === 'beneficiaries' + item.id" @click="restore('beneficiaries', item)">♻️ {{ t('common.restore') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="4" :loading="loading" :error="error" :empty="archive.beneficiaries.length === 0" :empty-text="t('archive.empty')" />
                        </tbody>
                    </table>

                    <table v-else class="w-full text-sm min-w-[640px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('payer.singular') }}</th>
                                <th class="th">{{ t('field.tax_id') }}</th>
                                <th class="th">{{ t('archive.archived_at') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in archive.payers" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open('payer', item)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ item.company_name }}</div>
                                    <div class="text-xs text-slate-500">{{ item.contact_person }}</div>
                                </td>
                                <td class="td font-mono text-xs">{{ item.tax_id || '—' }}</td>
                                <td class="td whitespace-nowrap">{{ format.dateTime(item.archived_at) }}</td>
                                <td class="td text-right" @click.stop>
                                    <button type="button" class="btn-ghost" :disabled="busyId === 'payers' + item.id" @click="restore('payers', item)">♻️ {{ t('common.restore') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="4" :loading="loading" :error="error" :empty="archive.payers.length === 0" :empty-text="t('archive.empty')" />
                        </tbody>
                    </table>
                </div>
            </section>
        `,
    };
})(window.CertiSub);
