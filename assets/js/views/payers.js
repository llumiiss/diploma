/*
 * Płatnicy: lista, szczegóły z osobami i certyfikatami oraz formularz.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    async function archivePayer(item) {
        const ok = await CertiSub.confirm({
            title: t('payer.archive_title'),
            message: t('payer.archive_confirm', { name: item.company_name }),
            confirmLabel: t('common.archive'),
            danger: true,
        });
        if (!ok) {
            return false;
        }
        try {
            await api.post(endpoints.payers, { action: 'archive', id: item.id });
            CertiSub.notify(t('payer.archived'));
            CertiSub.data.refresh('payers', 'options');
            return true;
        } catch (error) {
            CertiSub.notifyError(error);
            return false;
        }
    }

    CertiSub.components.PayersView = {
        data() {
            return { store: CertiSub.store, search: '' };
        },
        computed: {
            state() {
                return this.store.payers;
            },
            filtered() {
                const query = this.search.trim().toLowerCase();
                if (!query) {
                    return this.state.items;
                }
                return this.state.items.filter((item) => [item.company_name, item.tax_id, item.contact_person, item.city, item.email]
                    .some((value) => value && String(value).toLowerCase().indexOf(query) !== -1));
            },
        },
        mounted() {
            CertiSub.data.payers();
        },
        methods: {
            open(item) {
                CertiSub.openDrawer('payer', item.id);
            },
            openCard(item) {
                CertiSub.navigate('payers', item.id);
            },
            add() {
                CertiSub.openModal('PayerForm', {});
            },
            edit(item) {
                CertiSub.openModal('PayerForm', { payerId: item.id });
            },
            archive(item) {
                archivePayer(item);
            },
        },
        template: `
            <section>
                <PageHeader :title="t('payer.plural')" :description="t('payer.list_description')">
                    <ExportButtons dataset="payers" />
                    <button v-if="can('payers.create')" type="button" class="btn-primary" @click="add">+ {{ t('payer.add') }}</button>
                </PageHeader>

                <div class="card p-4 mb-4">
                    <input v-model="search" type="search" class="input" :placeholder="t('payer.search_placeholder')" :aria-label="t('common.search')">
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[960px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('payer.singular') }}</th>
                                <th class="th">{{ t('field.tax_id') }}</th>
                                <th class="th">{{ t('field.contact_person') }}</th>
                                <th class="th">{{ t('field.city') }}</th>
                                <th class="th text-right">{{ t('beneficiary.plural') }}</th>
                                <th class="th text-right">{{ t('certificate.plural') }}</th>
                                <th class="th text-right">{{ t('payer.annual_cost') }}</th>
                                <th class="th">{{ t('common.earliest_expiry') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in filtered" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ item.company_name }}</div>
                                    <div v-if="item.email" class="text-xs text-slate-500">{{ item.email }}</div>
                                </td>
                                <td class="td font-mono text-xs">{{ item.tax_id || '—' }}</td>
                                <td class="td">{{ item.contact_person }}</td>
                                <td class="td">{{ item.city || '—' }}</td>
                                <td class="td text-right">{{ item.beneficiary_count }}</td>
                                <td class="td text-right">{{ item.certificate_count }}</td>
                                <td class="td text-right whitespace-nowrap">{{ format.money(item.total_annual_cost) }}</td>
                                <td class="td whitespace-nowrap">{{ format.date(item.earliest_expiry) }}</td>
                                <td class="td text-right whitespace-nowrap" @click.stop>
                                    <button v-if="can('reports.view')" type="button" class="btn-ghost" :title="t('report.card')" @click="openCard(item)">📈 {{ t('report.card_short') }}</button>
                                    <button v-if="can('payers.update')" type="button" class="btn-ghost" @click="edit(item)">{{ t('common.edit') }}</button>
                                    <button v-if="can('payers.archive')" type="button" class="btn-ghost text-red-600" @click="archive(item)">{{ t('common.archive') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="9" :loading="state.loading && !state.loaded" :error="state.error"
                                        :empty="state.loaded && filtered.length === 0" :empty-text="t('payer.empty')" />
                        </tbody>
                    </table>
                </div>
            </section>
        `,
    };

    CertiSub.components.PayerDrawer = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { payer: null, loading: true, error: '' };
        },
        computed: {
            rows() {
                const p = this.payer;
                const address = [p.address_line, [p.postal_code, p.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
                return [
                    { label: t('field.tax_id'), value: p.tax_id },
                    { label: t('field.contact_person'), value: p.contact_person },
                    { label: t('field.email'), value: p.email },
                    { label: t('field.phone'), value: p.phone },
                    { label: t('payer.address'), value: address, wide: true },
                ];
            },
            totalCost() {
                // Koszt w przeliczeniu na rok (miesięczny × 12, wieloletni ÷ lata ważności) — §5 pkt 12.
                return this.payer.certificates.reduce((sum, item) => sum + Number(item.annualized_cost || 0), 0);
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
                    const data = await api.get(endpoints.payers, { id: this.id });
                    this.payer = data.payer;
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
                CertiSub.openModal('PayerForm', { payerId: this.id }, () => this.load());
            },
            async archive() {
                if (await archivePayer(this.payer)) {
                    this.load();
                }
            },
            async restore() {
                try {
                    await api.post(endpoints.payers, { action: 'restore', id: this.id });
                    CertiSub.notify(t('payer.restored'));
                    CertiSub.data.refresh('payers', 'options');
                    this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            addBeneficiary() {
                CertiSub.openModal('BeneficiaryForm', { preset: { payer_id: String(this.id) } }, () => this.load());
            },
            addCertificate() {
                CertiSub.openModal('CertificateForm', { preset: { payer_id: String(this.id) } }, () => this.load());
            },
            openCertificate(item) {
                CertiSub.openDrawer('certificate', item.id);
            },
            openBeneficiary(item) {
                CertiSub.openDrawer('beneficiary', item.id);
            },
            openCard() {
                CertiSub.navigate('payers', this.id);
            },
        },
        template: `
            <DrawerShell :title="payer ? payer.company_name : ''" :subtitle="t('payer.singular')" :loading="loading" :error="error" @close="close">
                <template #actions>
                    <button v-if="payer && can('reports.view')" type="button" class="btn-secondary" @click="openCard">📈 {{ t('report.card') }}</button>
                    <template v-if="payer && !payer.archived_at">
                        <button v-if="can('payers.update')" type="button" class="btn-secondary" @click="edit">✏️ {{ t('common.edit') }}</button>
                        <button v-if="can('beneficiaries.create')" type="button" class="btn-secondary" @click="addBeneficiary">+ {{ t('beneficiary.add') }}</button>
                        <button v-if="can('certificates.create')" type="button" class="btn-secondary" @click="addCertificate">+ {{ t('certificate.add') }}</button>
                        <button v-if="can('payers.archive')" type="button" class="btn-secondary text-red-700" @click="archive">📦 {{ t('common.archive') }}</button>
                    </template>
                    <button v-else-if="payer && can('payers.archive')" type="button" class="btn-secondary" @click="restore">♻️ {{ t('common.restore') }}</button>
                </template>

                <template v-if="payer">
                    <div v-if="payer.archived_at" class="mb-4 px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 text-sm">
                        {{ t('common.archived_on', { date: format.dateTime(payer.archived_at) }) }}
                    </div>
                    <DetailGrid :rows="rows" />

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('beneficiary.plural') }}</h4>
                    <div class="card divide-y divide-slate-100">
                        <button v-for="item in payer.beneficiaries" :key="item.id" type="button"
                                class="w-full text-left px-4 py-3 hover:bg-slate-50" @click="openBeneficiary(item)">
                            <span class="block font-medium text-slate-800">{{ format.person(item.first_name, item.last_name) }}</span>
                            <span class="block text-xs text-slate-500">{{ [item.email, item.phone].filter(Boolean).join(' · ') || '—' }}</span>
                        </button>
                        <p v-if="payer.beneficiaries.length === 0" class="px-4 py-6 text-sm text-slate-400 text-center">{{ t('payer.no_beneficiaries') }}</p>
                    </div>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">
                        {{ t('certificate.plural') }}
                        <span v-if="payer.certificates.length" class="normal-case font-normal text-slate-400">· {{ format.money(totalCost) }}</span>
                    </h4>
                    <div class="card divide-y divide-slate-100">
                        <button v-for="item in payer.certificates" :key="item.id" type="button"
                                class="w-full text-left px-4 py-3 hover:bg-slate-50 flex items-center justify-between gap-3" @click="openCertificate(item)">
                            <span>
                                <span class="block font-medium text-slate-800">{{ item.name }}</span>
                                <span class="block text-xs text-slate-500">{{ labels.type(item.certificate_type) }}<span v-if="item.beneficiary_first_name"> · {{ format.person(item.beneficiary_first_name, item.beneficiary_last_name) }}</span></span>
                            </span>
                            <span class="text-sm whitespace-nowrap">{{ format.date(item.expiry_date) }}</span>
                        </button>
                        <p v-if="payer.certificates.length === 0" class="px-4 py-6 text-sm text-slate-400 text-center">{{ t('payer.no_certificates') }}</p>
                    </div>
                </template>
            </DrawerShell>
        `,
    };

    function emptyPayerForm() {
        return { company_name: '', tax_id: '', contact_person: '', email: '', phone: '', address_line: '', postal_code: '', city: '' };
    }

    CertiSub.components.PayerForm = {
        props: {
            payerId: { type: Number, default: null },
        },
        emits: ['close', 'saved'],
        data() {
            return { form: emptyPayerForm(), loading: false, saving: false, errors: {}, message: '', existing: null };
        },
        computed: {
            isEdit() {
                return this.payerId !== null;
            },
        },
        async mounted() {
            if (!this.isEdit) {
                return;
            }
            this.loading = true;
            try {
                const data = await api.get(endpoints.payers, { id: this.payerId });
                const form = emptyPayerForm();
                Object.keys(form).forEach((key) => {
                    form[key] = data.payer[key] === null || data.payer[key] === undefined ? '' : String(data.payer[key]);
                });
                this.form = form;
            } catch (error) {
                this.message = error.message;
            } finally {
                this.loading = false;
            }
        },
        methods: {
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                this.existing = null;
                try {
                    const result = await api.post(endpoints.payers, {
                        action: this.isEdit ? 'update' : 'create',
                        id: this.payerId,
                        data: this.form,
                    });
                    CertiSub.notify(t(this.isEdit ? 'payer.updated' : 'payer.created'));
                    CertiSub.data.refresh('payers', 'certificates', 'beneficiaries', 'options');
                    this.$emit('saved', result.payer);
                    this.$emit('close');
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                    this.existing = error.data && error.data.existing ? error.data.existing : null;
                } finally {
                    this.saving = false;
                }
            },
            openExisting() {
                CertiSub.openDrawer('payer', this.existing.id);
                this.$emit('close');
            },
        },
        template: `
            <ModalShell :title="isEdit ? t('payer.edit_title') : t('payer.add_title')" :subtitle="t('common.required_hint')"
                        size="lg" :busy="saving" @close="$emit('close')">
                <FormSkeleton v-if="loading" />
                <form v-else id="payer-form" class="grid md:grid-cols-2 gap-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="md:col-span-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                        {{ message }}
                        <button v-if="existing" type="button" class="block mt-2 font-semibold underline" @click="openExisting">{{ t('payer.open_existing') }}</button>
                    </div>
                    <FormField class="md:col-span-2" :label="t('field.company_name')" :error="errors.company_name" required>
                        <input v-model="form.company_name" type="text" maxlength="255" :class="['input', errors.company_name ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.tax_id')" :error="errors.tax_id" :hint="t('payer.hint.tax_id')">
                        <input v-model="form.tax_id" type="text" maxlength="20" :class="['input font-mono', errors.tax_id ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.contact_person')" :error="errors.contact_person" required>
                        <input v-model="form.contact_person" type="text" maxlength="200" :class="['input', errors.contact_person ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.email')" :error="errors.email" :hint="t('payer.hint.email')">
                        <input v-model="form.email" type="email" maxlength="255" :class="['input', errors.email ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.phone')" :error="errors.phone">
                        <input v-model="form.phone" type="tel" maxlength="50" :class="['input', errors.phone ? 'input-error' : '']">
                    </FormField>
                    <FormField class="md:col-span-2" :label="t('field.address_line')" :error="errors.address_line">
                        <input v-model="form.address_line" type="text" maxlength="255" class="input">
                    </FormField>
                    <FormField :label="t('field.postal_code')" :error="errors.postal_code">
                        <input v-model="form.postal_code" type="text" maxlength="16" :class="['input', errors.postal_code ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.city')" :error="errors.city">
                        <input v-model="form.city" type="text" maxlength="120" class="input">
                    </FormField>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="payer-form" class="btn-primary" :disabled="saving || loading">{{ saving ? t('common.saving') : t('common.save') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
