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
            };
        },
        computed: {
            tabs() {
                return [
                    { id: 'certificates', label: t('certificate.plural'), count: this.archive.certificates.length },
                    { id: 'beneficiaries', label: t('beneficiary.plural'), count: this.archive.beneficiaries.length },
                    { id: 'payers', label: t('payer.plural'), count: this.archive.payers.length },
                ];
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
        },
        template: `
            <section>
                <PageHeader :title="t('archive.title')" :description="t('archive.description')" />

                <div class="flex flex-wrap gap-2 mb-4" role="tablist">
                    <button v-for="item in tabs" :key="item.id" type="button" role="tab" :aria-selected="tab === item.id"
                            :class="['px-4 py-2 rounded-lg text-sm font-medium border transition', tab === item.id ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']"
                            @click="tab = item.id">
                        {{ item.label }} <span class="ml-1 opacity-75">({{ item.count }})</span>
                    </button>
                </div>

                <div class="card overflow-x-auto">
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
