/*
 * Użytkownicy certyfikatów (beneficjenci): lista, szczegóły i formularz.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    async function archiveBeneficiary(item) {
        const ok = await CertiSub.confirm({
            title: t('beneficiary.archive_title'),
            message: t('beneficiary.archive_confirm', { name: CertiSub.format.person(item.first_name, item.last_name) }),
            confirmLabel: t('common.archive'),
            danger: true,
        });
        if (!ok) {
            return false;
        }
        try {
            await api.post(endpoints.beneficiaries, { action: 'archive', id: item.id });
            CertiSub.notify(t('beneficiary.archived'));
            CertiSub.data.refresh('beneficiaries', 'payers', 'options');
            return true;
        } catch (error) {
            CertiSub.notifyError(error);
            return false;
        }
    }

    CertiSub.components.BeneficiariesView = {
        data() {
            return { store: CertiSub.store, search: '' };
        },
        computed: {
            state() {
                return this.store.beneficiaries;
            },
            filtered() {
                const query = this.search.trim().toLowerCase();
                if (!query) {
                    return this.state.items;
                }
                return this.state.items.filter((item) => [item.first_name + ' ' + item.last_name, item.email, item.phone, item.payer_name]
                    .some((value) => value && String(value).toLowerCase().indexOf(query) !== -1));
            },
        },
        mounted() {
            CertiSub.data.beneficiaries();
        },
        methods: {
            open(item) {
                CertiSub.openDrawer('beneficiary', item.id);
            },
            openCard(item) {
                CertiSub.navigate('beneficiaries', item.id);
            },
            add() {
                CertiSub.openModal('BeneficiaryForm', {});
            },
            edit(item) {
                CertiSub.openModal('BeneficiaryForm', { beneficiaryId: item.id });
            },
            archive(item) {
                archiveBeneficiary(item);
            },
            expiryDays(item) {
                return CertiSub.format.daysUntil(item.earliest_expiry);
            },
        },
        template: `
            <section>
                <PageHeader :title="t('beneficiary.plural')" :description="t('beneficiary.list_description')">
                    <button v-if="can('beneficiaries.create')" type="button" class="btn-primary" @click="add">+ {{ t('beneficiary.add') }}</button>
                </PageHeader>

                <div class="card p-4 mb-4">
                    <input v-model="search" type="search" class="input" :placeholder="t('beneficiary.search_placeholder')" :aria-label="t('common.search')">
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[860px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('beneficiary.singular') }}</th>
                                <th class="th">{{ t('field.email') }}</th>
                                <th class="th">{{ t('field.phone') }}</th>
                                <th class="th">{{ t('field.payer_id') }}</th>
                                <th class="th text-right">{{ t('certificate.plural') }}</th>
                                <th class="th">{{ t('common.earliest_expiry') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in filtered" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                <td class="td font-medium text-slate-800">{{ item.first_name }} {{ item.last_name }}</td>
                                <td class="td text-slate-600">{{ item.email || '—' }}</td>
                                <td class="td text-slate-600 whitespace-nowrap">{{ item.phone || '—' }}</td>
                                <td class="td">{{ item.payer_name || '—' }}</td>
                                <td class="td text-right">{{ item.certificate_count }}</td>
                                <td class="td whitespace-nowrap">
                                    <template v-if="item.earliest_expiry">
                                        {{ format.date(item.earliest_expiry) }}
                                        <div class="text-xs text-slate-500">{{ format.days(expiryDays(item)) }}</div>
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="td text-right whitespace-nowrap" @click.stop>
                                    <button v-if="can('reports.view')" type="button" class="btn-ghost" :title="t('report.card')" @click="openCard(item)">📈 {{ t('report.card_short') }}</button>
                                    <button v-if="can('beneficiaries.update')" type="button" class="btn-ghost" @click="edit(item)">{{ t('common.edit') }}</button>
                                    <button v-if="can('beneficiaries.archive')" type="button" class="btn-ghost text-red-600" @click="archive(item)">{{ t('common.archive') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="7" :loading="state.loading && !state.loaded" :error="state.error"
                                        :empty="state.loaded && filtered.length === 0" :empty-text="t('beneficiary.empty')" />
                        </tbody>
                    </table>
                </div>
            </section>
        `,
    };

    CertiSub.components.BeneficiaryDrawer = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { beneficiary: null, loading: true, error: '' };
        },
        computed: {
            rows() {
                const b = this.beneficiary;
                return [
                    { label: t('field.email'), value: b.email },
                    { label: t('field.phone'), value: b.phone },
                    { label: t('field.payer_id'), value: b.payer_name },
                    { label: t('common.created_at'), value: CertiSub.format.dateTime(b.created_at) },
                    { label: t('field.notes'), value: b.notes, wide: true },
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
                    const data = await api.get(endpoints.beneficiaries, { id: this.id });
                    this.beneficiary = data.beneficiary;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            close() {
                CertiSub.closeDrawer();
            },
            edit() {
                CertiSub.openModal('BeneficiaryForm', { beneficiaryId: this.id }, () => this.load());
            },
            async archive() {
                if (await archiveBeneficiary(this.beneficiary)) {
                    this.load();
                }
            },
            async restore() {
                try {
                    await api.post(endpoints.beneficiaries, { action: 'restore', id: this.id });
                    CertiSub.notify(t('beneficiary.restored'));
                    CertiSub.data.refresh('beneficiaries', 'payers', 'options');
                    this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            addCertificate() {
                CertiSub.openModal('CertificateForm', {
                    preset: { beneficiary_id: String(this.id), payer_id: this.beneficiary.payer_id ? String(this.beneficiary.payer_id) : '' },
                }, () => this.load());
            },
            openCertificate(item) {
                CertiSub.openDrawer('certificate', item.id);
            },
            openPayer() {
                CertiSub.openDrawer('payer', this.beneficiary.payer_id);
            },
            openCard() {
                CertiSub.navigate('beneficiaries', this.id);
            },
        },
        template: `
            <DrawerShell :title="beneficiary ? format.person(beneficiary.first_name, beneficiary.last_name) : ''"
                         :subtitle="t('beneficiary.singular')" :loading="loading" :error="error" @close="close">
                <template #actions>
                    <button v-if="beneficiary && can('reports.view')" type="button" class="btn-secondary" @click="openCard">📈 {{ t('report.card') }}</button>
                    <template v-if="beneficiary && !beneficiary.archived_at">
                        <button v-if="can('beneficiaries.update')" type="button" class="btn-secondary" @click="edit">✏️ {{ t('common.edit') }}</button>
                        <button v-if="can('certificates.create')" type="button" class="btn-secondary" @click="addCertificate">+ {{ t('certificate.add') }}</button>
                        <button v-if="can('beneficiaries.archive')" type="button" class="btn-secondary text-red-700" @click="archive">📦 {{ t('common.archive') }}</button>
                    </template>
                    <button v-else-if="beneficiary && can('beneficiaries.archive')" type="button" class="btn-secondary" @click="restore">♻️ {{ t('common.restore') }}</button>
                </template>

                <template v-if="beneficiary">
                    <div v-if="beneficiary.archived_at" class="mb-4 px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 text-sm">
                        {{ t('common.archived_on', { date: format.dateTime(beneficiary.archived_at) }) }}
                    </div>
                    <DetailGrid :rows="rows" />
                    <p v-if="beneficiary.payer_id" class="mt-3 text-sm">
                        <button type="button" class="text-brand-700 hover:underline" @click="openPayer">{{ t('payer.open_card') }} →</button>
                    </p>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('certificate.plural') }}</h4>
                    <div class="card divide-y divide-slate-100">
                        <button v-for="item in beneficiary.certificates" :key="item.id" type="button"
                                class="w-full text-left px-4 py-3 hover:bg-slate-50 flex items-center justify-between gap-3" @click="openCertificate(item)">
                            <span>
                                <span class="block font-medium text-slate-800">{{ item.name }}</span>
                                <span class="block text-xs text-slate-500">{{ labels.type(item.certificate_type) }} · {{ item.payer_name }}</span>
                            </span>
                            <span class="text-sm whitespace-nowrap">{{ format.date(item.expiry_date) }}</span>
                        </button>
                        <p v-if="beneficiary.certificates.length === 0" class="px-4 py-6 text-sm text-slate-400 text-center">{{ t('beneficiary.no_certificates') }}</p>
                    </div>
                </template>
            </DrawerShell>
        `,
    };

    function emptyBeneficiaryForm() {
        return { first_name: '', last_name: '', email: '', phone: '', payer_id: '', notes: '' };
    }

    CertiSub.components.BeneficiaryForm = {
        props: {
            beneficiaryId: { type: Number, default: null },
            preset: { type: Object, default: () => ({}) },
        },
        emits: ['close', 'saved'],
        data() {
            return { form: emptyBeneficiaryForm(), payers: [], loading: true, saving: false, errors: {}, message: '' };
        },
        computed: {
            isEdit() {
                return this.beneficiaryId !== null;
            },
        },
        async mounted() {
            try {
                const payerOptions = await api.get(endpoints.payers, { view: 'options' });
                this.payers = payerOptions.options;
                if (this.isEdit) {
                    const data = await api.get(endpoints.beneficiaries, { id: this.beneficiaryId });
                    const form = emptyBeneficiaryForm();
                    Object.keys(form).forEach((key) => {
                        form[key] = data.beneficiary[key] === null || data.beneficiary[key] === undefined ? '' : String(data.beneficiary[key]);
                    });
                    this.form = form;
                } else {
                    Object.assign(this.form, this.preset || {});
                }
            } catch (error) {
                this.message = error.message;
            } finally {
                this.loading = false;
            }
        },
        methods: {
            addPayer() {
                CertiSub.openModal('PayerForm', {}, async (payer) => {
                    const payerOptions = await api.get(endpoints.payers, { view: 'options' });
                    this.payers = payerOptions.options;
                    this.form.payer_id = String(payer.id);
                });
            },
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.beneficiaries, {
                        action: this.isEdit ? 'update' : 'create',
                        id: this.beneficiaryId,
                        data: this.form,
                    });
                    CertiSub.notify(t(this.isEdit ? 'beneficiary.updated' : 'beneficiary.created'));
                    CertiSub.data.refresh('beneficiaries', 'payers', 'certificates', 'options');
                    this.$emit('saved', result.beneficiary);
                    this.$emit('close');
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                } finally {
                    this.saving = false;
                }
            },
        },
        template: `
            <ModalShell :title="isEdit ? t('beneficiary.edit_title') : t('beneficiary.add_title')"
                        :subtitle="t('beneficiary.form_description')" size="lg" :busy="saving" @close="$emit('close')">
                <div v-if="loading" class="py-10 text-center text-slate-400">{{ t('common.loading') }}</div>
                <form v-else id="beneficiary-form" class="grid md:grid-cols-2 gap-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="md:col-span-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <FormField :label="t('field.first_name')" :error="errors.first_name" required>
                        <input v-model="form.first_name" type="text" maxlength="100" :class="['input', errors.first_name ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.last_name')" :error="errors.last_name" required>
                        <input v-model="form.last_name" type="text" maxlength="100" :class="['input', errors.last_name ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.email')" :error="errors.email">
                        <input v-model="form.email" type="email" maxlength="255" :class="['input', errors.email ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.phone')" :error="errors.phone">
                        <input v-model="form.phone" type="tel" maxlength="50" :class="['input', errors.phone ? 'input-error' : '']">
                    </FormField>
                    <FormField class="md:col-span-2" :label="t('field.payer_id')" :error="errors.payer_id" :hint="t('beneficiary.hint.payer')">
                        <div class="flex gap-2">
                            <select v-model="form.payer_id" :class="['input', errors.payer_id ? 'input-error' : '']">
                                <option value="">{{ t('beneficiary.no_payer') }}</option>
                                <option v-for="payer in payers" :key="payer.id" :value="String(payer.id)">{{ payer.company_name }}{{ payer.city ? ' — ' + payer.city : '' }}</option>
                            </select>
                            <button v-if="can('payers.create')" type="button" class="btn-secondary shrink-0" :title="t('payer.add')" @click="addPayer">+</button>
                        </div>
                    </FormField>
                    <FormField class="md:col-span-2" :label="t('field.notes')" :error="errors.notes">
                        <textarea v-model="form.notes" rows="3" maxlength="5000" class="input"></textarea>
                    </FormField>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="beneficiary-form" class="btn-primary" :disabled="saving || loading">{{ saving ? t('common.saving') : t('common.save') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
