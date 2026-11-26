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
                const matches = CertiSub.textFilter.matcher(this.search);
                if (!this.search.trim()) {
                    return this.state.items;
                }
                return this.state.items.filter((item) => matches([item.first_name + ' ' + item.last_name, item.email, item.phone, item.payer_name, item.other_companies, item.former_companies]));
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
                    <ExportButtons dataset="beneficiaries" />
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
                                <td class="td">
                                    {{ item.payer_name || '—' }}
                                    <div v-if="item.other_companies" class="text-xs text-emerald-700">+ {{ item.other_companies }}</div>
                                    <div v-if="item.former_companies" class="text-xs text-slate-400">{{ t('employment.formerly', { companies: item.former_companies }) }}</div>
                                </td>
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
            askAdmin() {
                const b = this.beneficiary;
                CertiSub.openComposer({
                    type: 'request',
                    subject: t('notification.about', { name: CertiSub.format.person(b.first_name, b.last_name) }),
                    related_type: 'beneficiary',
                    related_id: b.id,
                    related_label: CertiSub.format.person(b.first_name, b.last_name),
                });
            },
            openPayer() {
                CertiSub.openDrawer('payer', this.beneficiary.payer_id);
            },
            changeCompany(mode, employment) {
                CertiSub.openModal('CompanyChangeForm', {
                    beneficiary: this.beneficiary,
                    mode,
                    employment: employment || null,
                }, () => this.load());
            },
            async makePrimary(employment) {
                try {
                    await api.post(endpoints.beneficiaries, { action: 'set_primary_company', id: this.id, data: { payer_id: employment.payer_id } });
                    CertiSub.notify(t('employment.primary_set'));
                    CertiSub.data.refresh('beneficiaries', 'payers', 'options');
                    this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            openCompany(employment) {
                CertiSub.openDrawer('payer', employment.payer_id);
            },
            openCard() {
                CertiSub.navigate('beneficiaries', this.id);
            },
        },
        template: `
            <DrawerShell :title="beneficiary ? format.person(beneficiary.first_name, beneficiary.last_name) : ''"
                         :subtitle="t('beneficiary.singular')" :loading="loading" :error="error" @close="close">
                <template #actions>
                    <button v-if="beneficiary && can('notifications.use')" type="button" class="btn-secondary" @click="askAdmin">📨 {{ t('notification.ask_admin') }}</button>
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

                    <div class="flex flex-wrap items-center justify-between gap-2 mt-6 mb-3">
                        <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">{{ t('employment.title') }}</h4>
                        <div v-if="!beneficiary.archived_at && can('beneficiaries.update')" class="flex flex-wrap gap-2">
                            <button type="button" class="btn-secondary" @click="changeCompany('move')">🔀 {{ t('employment.move') }}</button>
                            <button type="button" class="btn-secondary" @click="changeCompany('add')">+ {{ t('employment.add') }}</button>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mb-2">{{ t('employment.hint') }}</p>
                    <div class="card divide-y divide-slate-100">
                        <div v-for="item in beneficiary.employments" :key="item.id" class="px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <button type="button" class="font-medium text-slate-800 hover:underline text-left" @click="openCompany(item)">{{ item.payer_name }}</button>
                                <span v-if="item.is_primary" class="ml-2 badge bg-brand-50 text-brand-700 border border-brand-200">{{ t('employment.primary') }}</span>
                                <span v-else-if="item.is_current" class="ml-2 badge bg-emerald-50 text-emerald-700 border border-emerald-200">{{ t('employment.current') }}</span>
                                <span v-else class="ml-2 badge bg-slate-100 text-slate-600 border border-slate-200">{{ t('employment.former') }}</span>
                                <div class="text-xs text-slate-500">
                                    {{ format.date(item.started_on) }} – {{ item.ended_on ? format.date(item.ended_on) : t('employment.now') }}
                                    <span v-if="item.payer_tax_id"> · {{ t('field.tax_id') }} {{ item.payer_tax_id }}</span>
                                </div>
                                <div v-if="item.note" class="text-xs text-slate-500 italic">{{ item.note }}</div>
                            </div>
                            <div v-if="!beneficiary.archived_at && can('beneficiaries.update')" class="flex flex-wrap gap-1">
                                <button v-if="item.is_current && !item.is_primary" type="button" class="btn-ghost" @click="makePrimary(item)">{{ t('employment.make_primary') }}</button>
                                <button v-if="item.is_current" type="button" class="btn-ghost text-red-700" @click="changeCompany('end', item)">{{ t('employment.end') }}</button>
                                <button type="button" class="btn-ghost" :title="t('employment.correct_hint')" @click="changeCompany('correct', item)">✏️ {{ t('employment.correct') }}</button>
                            </div>
                        </div>
                        <p v-if="!beneficiary.employments || beneficiary.employments.length === 0" class="px-4 py-6 text-sm text-slate-400 text-center">{{ t('employment.empty') }}</p>
                    </div>

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

    function localToday() {
        const now = new Date();
        const pad = (value) => String(value).padStart(2, '0');
        return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }

    /*
     * Zmiana firmy osoby (Etap 12): przenieś / dodaj firmę / zakończ / popraw okres.
     * Certyfikaty zostają przy osobie — przenosi się tylko (na życzenie) płatnika.
     */
    CertiSub.components.CompanyChangeForm = {
        props: {
            beneficiary: { type: Object, required: true },
            mode: { type: String, required: true },
            employment: { type: Object, default: null },
        },
        emits: ['close', 'saved'],
        data() {
            const e = this.employment;
            return {
                payers: [],
                loading: this.mode === 'move' || this.mode === 'add',
                saving: false,
                errors: {},
                message: '',
                form: {
                    payer_id: '',
                    from_payer_id: this.beneficiary.payer_id ? String(this.beneficiary.payer_id) : '',
                    date: localToday(),
                    note: e && this.mode === 'correct' ? (e.note || '') : '',
                    transfer_payer: false,
                    primary: false,
                    started_on: e ? e.started_on : '',
                    ended_on: e && e.ended_on ? e.ended_on : '',
                },
            };
        },
        computed: {
            current() {
                return (this.beneficiary.employments || []).filter((item) => item.is_current);
            },
            payerOptions() {
                const taken = this.current.map((item) => item.payer_id);
                return this.payers
                    .filter((payer) => !taken.includes(payer.id))
                    .map((payer) => ({ value: payer.id, label: payer.company_name + (payer.city ? ' — ' + payer.city : ''), search: payer.tax_id || '' }));
            },
            title() {
                return t('employment.form.' + this.mode + '_title');
            },
            person() {
                return CertiSub.format.person(this.beneficiary.first_name, this.beneficiary.last_name);
            },
        },
        async mounted() {
            if (!this.loading) {
                return;
            }
            try {
                const data = await api.get(endpoints.payers, { view: 'options' });
                this.payers = data.options;
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
                const f = this.form;
                let action;
                let data;
                if (this.mode === 'move') {
                    action = 'move_company';
                    data = { payer_id: f.payer_id, from_payer_id: f.from_payer_id, date: f.date, note: f.note, transfer_payer: f.transfer_payer };
                } else if (this.mode === 'add') {
                    action = 'add_company';
                    data = { payer_id: f.payer_id, date: f.date, note: f.note, primary: f.primary };
                } else if (this.mode === 'end') {
                    action = 'end_company';
                    data = { employment_id: this.employment.id, date: f.date, note: f.note };
                } else {
                    action = 'correct_company';
                    data = { employment_id: this.employment.id, started_on: f.started_on, ended_on: f.ended_on, note: f.note };
                }
                try {
                    const result = await api.post(endpoints.beneficiaries, { action, id: this.beneficiary.id, data });
                    CertiSub.notify(t('employment.saved.' + this.mode));
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
            <ModalShell :title="title" :subtitle="person" size="lg" :busy="saving" @close="$emit('close')">
                <FormSkeleton v-if="loading" />
                <form v-else id="company-change-form" class="grid md:grid-cols-2 gap-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="md:col-span-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <p class="md:col-span-2 text-sm text-slate-600">{{ t('employment.form.' + mode + '_description') }}</p>

                    <template v-if="mode === 'move' || mode === 'add'">
                        <FormField v-if="mode === 'move' && current.length > 1" class="md:col-span-2" :label="t('employment.form.from')" :error="errors.from_payer_id">
                            <select v-model="form.from_payer_id" class="input">
                                <option v-for="item in current" :key="item.id" :value="String(item.payer_id)">{{ item.payer_name }}</option>
                            </select>
                        </FormField>
                        <FormField class="md:col-span-2" :label="t('employment.form.to')" :error="errors.payer_id" required>
                            <SearchSelect v-model="form.payer_id" :options="payerOptions" :empty-label="t('common.choose')"
                                          :invalid="Boolean(errors.payer_id)" :aria-label="t('employment.form.to')" />
                        </FormField>
                    </template>

                    <div v-if="mode === 'end' || mode === 'correct'" class="md:col-span-2 px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 text-sm">
                        <strong>{{ employment.payer_name }}</strong>
                        · {{ format.date(employment.started_on) }} – {{ employment.ended_on ? format.date(employment.ended_on) : t('employment.now') }}
                    </div>

                    <FormField v-if="mode !== 'correct'" :label="t('employment.form.date_' + mode)" :error="errors.date">
                        <input v-model="form.date" type="date" :class="['input', errors.date ? 'input-error' : '']">
                    </FormField>
                    <template v-else>
                        <FormField :label="t('employment.form.started_on')" :error="errors.started_on" required>
                            <input v-model="form.started_on" type="date" :class="['input', errors.started_on ? 'input-error' : '']">
                        </FormField>
                        <FormField :label="t('employment.form.ended_on')" :error="errors.ended_on" :hint="t('employment.form.ended_on_hint')">
                            <input v-model="form.ended_on" type="date" :class="['input', errors.ended_on ? 'input-error' : '']">
                        </FormField>
                    </template>

                    <FormField class="md:col-span-2" :label="t('employment.form.note')" :error="errors.note">
                        <input v-model="form.note" type="text" maxlength="500" class="input">
                    </FormField>

                    <label v-if="mode === 'move'" class="md:col-span-2 flex items-start gap-2 text-sm text-slate-700">
                        <input v-model="form.transfer_payer" type="checkbox" class="mt-1">
                        <span>{{ t('employment.form.transfer_payer') }}<span class="block text-xs text-slate-500">{{ t('employment.form.transfer_payer_hint') }}</span></span>
                    </label>
                    <label v-if="mode === 'add'" class="md:col-span-2 flex items-start gap-2 text-sm text-slate-700">
                        <input v-model="form.primary" type="checkbox" class="mt-1">
                        <span>{{ t('employment.form.primary') }}</span>
                    </label>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="company-change-form" :class="mode === 'end' ? 'btn-danger' : 'btn-primary'" :disabled="saving || loading">{{ saving ? t('common.saving') : t('employment.form.submit_' + mode) }}</button>
                </template>
            </ModalShell>
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
            payerOptions() {
                return this.payers.map((payer) => ({
                    value: payer.id,
                    label: payer.company_name + (payer.city ? ' — ' + payer.city : ''),
                    search: payer.tax_id || '',
                }));
            },
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
                <FormSkeleton v-if="loading" />
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
                    <FormField class="md:col-span-2" :label="t('field.payer_id')" :error="errors.payer_id" :hint="isEdit ? t('beneficiary.hint.payer_edit') : t('beneficiary.hint.payer')" required>
                        <div class="flex gap-2">
                            <SearchSelect v-model="form.payer_id" :options="payerOptions" :empty-label="t('common.choose')"
                                          :invalid="Boolean(errors.payer_id)" :aria-label="t('field.payer_id')" />
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
