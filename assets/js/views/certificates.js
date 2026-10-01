/*
 * Ewidencja certyfikatów: lista z filtrami, szczegóły z historią oraz formularz dodawania i edycji.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints, labels } = CertiSub;

    /**
     * Wspólne akcje na certyfikacie — używane przez listę i panel szczegółów.
     */
    const certificateActions = {
        edit(id) {
            CertiSub.openModal('CertificateForm', { certificateId: id });
        },
        async archive(item) {
            const ok = await CertiSub.confirm({
                title: t('certificate.archive_title'),
                message: t('certificate.archive_confirm', { name: item.name }),
                confirmLabel: t('common.archive'),
                danger: true,
            });
            if (!ok) {
                return false;
            }
            try {
                await api.post(endpoints.certificates, { action: 'archive', id: item.id });
                CertiSub.notify(t('certificate.archived'));
                CertiSub.data.refresh('certificates', 'summary', 'beneficiaries', 'payers');
                return true;
            } catch (error) {
                CertiSub.notifyError(error);
                return false;
            }
        },
        async restore(item) {
            try {
                await api.post(endpoints.certificates, { action: 'restore', id: item.id });
                CertiSub.notify(t('certificate.restored'));
                CertiSub.data.refresh('certificates', 'summary', 'beneficiaries', 'payers');
                return true;
            } catch (error) {
                CertiSub.notifyError(error);
                return false;
            }
        },
    };
    CertiSub.certificateActions = certificateActions;

    CertiSub.components.CertificatesView = {
        props: {
            mode: { type: String, default: 'all' },
        },
        data() {
            return {
                store: CertiSub.store,
                search: '',
                filterType: '',
                filterPayment: '',
                filterPriority: '',
                filterStatus: '',
            };
        },
        computed: {
            state() {
                return this.store.certificates;
            },
            warningDays() {
                return CertiSub.boot.thresholds.warning;
            },
            criticalDays() {
                return CertiSub.boot.thresholds.critical;
            },
            filtered() {
                let list = this.state.items;

                if (this.mode === 'todo') {
                    list = list.filter((item) =>
                        item.status === 'pending'
                        || item.days_left <= this.warningDays
                        || item.display_payment_status === 'overdue'
                    );
                }

                if (this.filterType) {
                    list = list.filter((item) => item.certificate_type === this.filterType);
                }
                if (this.filterPayment) {
                    list = list.filter((item) => item.display_payment_status === this.filterPayment);
                }
                if (this.filterPriority) {
                    list = list.filter((item) => item.priority === this.filterPriority);
                }
                if (this.filterStatus) {
                    list = list.filter((item) => item.status === this.filterStatus);
                }

                const query = this.search.trim().toLowerCase();
                if (!query) {
                    return list;
                }

                return list.filter((item) => [
                    item.name, item.serial_number, item.issuer, item.beneficiary_name,
                    item.company_name, item.owner_name, item.type_label,
                ].some((value) => value && String(value).toLowerCase().indexOf(query) !== -1));
            },
        },
        mounted() {
            CertiSub.data.certificates();
        },
        methods: {
            open(item) {
                CertiSub.openDrawer('certificate', item.id);
            },
            add() {
                CertiSub.openModal('CertificateForm', {});
            },
            edit(item) {
                certificateActions.edit(item.id);
            },
            archive(item) {
                certificateActions.archive(item);
            },
            reset() {
                this.search = '';
                this.filterType = '';
                this.filterPayment = '';
                this.filterPriority = '';
                this.filterStatus = '';
            },
        },
        template: `
            <section>
                <PageHeader :title="mode === 'todo' ? t('dash.todo_list') : t('certificate.plural')"
                            :description="mode === 'todo' ? t('dash.todo_desc_corporate', { days: warningDays }) : t('certificate.list_description')">
                    <ExportButtons v-if="mode !== 'todo'" dataset="certificates" />
                    <button v-if="can('certificates.create')" type="button" class="btn-primary" @click="add">+ {{ t('certificate.add') }}</button>
                </PageHeader>

                <div class="card p-4 mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <input v-model="search" type="search" class="input xl:col-span-2" :placeholder="t('certificate.search_placeholder')" :aria-label="t('common.search')">
                    <select v-model="filterType" class="input" :aria-label="t('field.certificate_type')">
                        <option value="">{{ t('dash.filter.all_types') }}</option>
                        <option v-for="type in labels.types" :key="type" :value="type">{{ labels.type(type) }}</option>
                    </select>
                    <select v-model="filterStatus" class="input" :aria-label="t('field.status')">
                        <option value="">{{ t('certificate.filter.all_statuses') }}</option>
                        <option v-for="status in labels.statuses" :key="status" :value="status">{{ labels.status(status) }}</option>
                    </select>
                    <select v-model="filterPayment" class="input" :aria-label="t('field.payment_status')">
                        <option value="">{{ t('dash.filter.all_payments') }}</option>
                        <option v-for="status in labels.paymentStatuses" :key="status" :value="status">{{ labels.payment(status) }}</option>
                    </select>
                    <select v-model="filterPriority" class="input" :aria-label="t('dash.priority')">
                        <option value="">{{ t('dash.filter.all_priorities') }}</option>
                        <option value="expired">{{ t('dash.filter.expired') }}</option>
                        <option value="critical">{{ t('dash.filter.critical', { days: criticalDays }) }}</option>
                        <option value="warning">{{ t('dash.filter.warning', { days: warningDays }) }}</option>
                        <option value="ok">{{ t('priority.label.ok') }}</option>
                    </select>
                </div>
                <div class="flex items-center justify-between mb-3 text-xs text-slate-400">
                    <span>{{ t('certificate.showing', { shown: filtered.length, total: state.items.length }) }}</span>
                    <button v-if="search || filterType || filterPayment || filterPriority || filterStatus" type="button" class="btn-ghost" @click="reset">{{ t('common.clear_filters') }}</button>
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[1000px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('certificate.singular') }}</th>
                                <th class="th">{{ t('field.certificate_type') }}</th>
                                <th class="th">{{ t('field.beneficiary_id') }}</th>
                                <th class="th">{{ t('field.payer_id') }}</th>
                                <th class="th">{{ t('field.user_id') }}</th>
                                <th class="th">{{ t('field.expiry_date') }}</th>
                                <th class="th text-right">{{ t('field.discount_percent') }}</th>
                                <th class="th">{{ t('field.payment_status') }}</th>
                                <th class="th">{{ t('field.status') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in filtered" :key="item.id" :class="badge.row(item.priority)"
                                class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ item.name }}</div>
                                    <div v-if="item.serial_number || item.issuer" class="text-xs text-slate-500">
                                        {{ [item.serial_number, item.issuer].filter(Boolean).join(' · ') }}
                                    </div>
                                </td>
                                <td class="td text-slate-600">{{ item.type_label }}</td>
                                <td class="td">{{ item.beneficiary_name || '—' }}</td>
                                <td class="td">{{ item.company_name }}</td>
                                <td class="td text-slate-600">{{ item.owner_name }}</td>
                                <td class="td whitespace-nowrap">
                                    <div>{{ format.date(item.expiry_date) }}</div>
                                    <span :class="['badge mt-1', badge.priority(item.priority)]">{{ format.days(item.days_left) }}</span>
                                </td>
                                <td class="td text-right whitespace-nowrap">{{ format.discount(item.discount_percent) }}</td>
                                <td class="td"><span :class="['badge', badge.payment(item.display_payment_status)]">{{ item.payment_label }}</span></td>
                                <td class="td"><span :class="['badge', badge.status(item.status)]">{{ item.status_label }}</span></td>
                                <td class="td text-right whitespace-nowrap" @click.stop>
                                    <button v-if="can('certificates.update')" type="button" class="btn-ghost" @click="edit(item)">{{ t('common.edit') }}</button>
                                    <button v-if="can('certificates.archive')" type="button" class="btn-ghost text-red-600" @click="archive(item)">{{ t('common.archive') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="10" :loading="state.loading && !state.loaded" :error="state.error"
                                        :empty="state.loaded && filtered.length === 0" :empty-text="t('dash.no_results')" />
                        </tbody>
                    </table>
                </div>
            </section>
        `,
    };

    CertiSub.components.CertificateDrawer = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { certificate: null, loading: true, error: '', tasks: [], invitations: [] };
        },
        computed: {
            openTask() {
                return this.tasks.find((task) => task.is_open) || null;
            },
            rows() {
                const c = this.certificate;
                return [
                    { label: t('field.certificate_type'), value: c.type_label },
                    { label: t('field.status'), value: c.status_label },
                    { label: t('field.serial_number'), value: c.serial_number },
                    { label: t('field.issuer'), value: c.issuer },
                    { label: t('field.valid_from'), value: CertiSub.format.date(c.valid_from) },
                    { label: t('field.expiry_date'), value: CertiSub.format.date(c.expiry_date) + ' (' + CertiSub.format.days(c.days_left) + ')' },
                    { label: t('field.renewal_lead_days'), value: c.renewal_lead_days === null ? null : t('days.many', { count: c.renewal_lead_days }) },
                    { label: t('field.user_id'), value: c.owner_name },
                    { label: t('field.discount_percent'), value: CertiSub.format.discount(c.discount_percent) + ' · ' + labels.billing(c.billing_cycle) },
                    { label: t('field.payment_status'), value: c.payment_label },
                    { label: t('field.last_payment_date'), value: CertiSub.format.date(c.last_payment_date) },
                    { label: t('field.auto_renew'), value: c.auto_renew ? t('common.yes') : t('common.no') },
                    { label: t('field.notes'), value: c.notes, wide: true },
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
                    const data = await api.get(endpoints.certificates, { id: this.id });
                    this.certificate = data.certificate;
                    if (CertiSub.can('tasks.view')) {
                        const [tasks, invitations] = await Promise.all([
                            api.get(endpoints.tasks, { certificate_id: this.id, status: 'all' }),
                            api.get(endpoints.invitations, { certificate_id: this.id }),
                        ]);
                        this.tasks = tasks.tasks;
                        this.invitations = invitations.invitations;
                    }
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
                CertiSub.openModal('CertificateForm', { certificateId: this.id }, () => this.load());
            },
            async createTask() {
                try {
                    const result = await api.post(endpoints.tasks, { action: 'create', data: { certificate_id: this.id } });
                    CertiSub.notify(t('task.created'));
                    CertiSub.data.refresh('tasks', 'taskStats');
                    CertiSub.openDrawer('task', result.task.id);
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            invite() {
                CertiSub.openModal('InvitationComposer', { certificateId: this.id }, () => this.load());
            },
            openTaskDrawer(task) {
                CertiSub.openDrawer('task', task.id);
            },
            openInvitation(invitation) {
                CertiSub.openDrawer('invitation', invitation.id);
            },
            async archive() {
                if (await certificateActions.archive(this.certificate)) {
                    this.load();
                }
            },
            async restore() {
                if (await certificateActions.restore(this.certificate)) {
                    this.load();
                }
            },
            openBeneficiary() {
                CertiSub.openDrawer('beneficiary', this.certificate.beneficiary_id);
            },
            openPayer() {
                CertiSub.openDrawer('payer', this.certificate.payer_id);
            },
            openLinked(link) {
                CertiSub.openDrawer('certificate', link.id);
            },
        },
        template: `
            <DrawerShell :title="certificate ? certificate.name : ''" :subtitle="certificate ? certificate.type_label : ''"
                         :loading="loading" :error="error" @close="close">
                <template #actions>
                    <template v-if="certificate && !certificate.archived_at">
                        <button v-if="can('certificates.update')" type="button" class="btn-secondary" @click="edit">✏️ {{ t('common.edit') }}</button>
                        <button v-if="can('certificates.archive')" type="button" class="btn-secondary text-red-700" @click="archive">📦 {{ t('common.archive') }}</button>
                    </template>
                    <button v-else-if="certificate && can('certificates.archive')" type="button" class="btn-secondary" @click="restore">♻️ {{ t('common.restore') }}</button>
                </template>

                <template v-if="certificate">
                    <div v-if="certificate.archived_at" class="mb-4 px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 text-sm">
                        {{ t('common.archived_on', { date: format.dateTime(certificate.archived_at) }) }}
                    </div>
                    <div v-if="certificate.reminder_message && !certificate.archived_at" :class="['mb-4 px-4 py-3 rounded-lg text-sm font-medium', badge.priority(certificate.priority)]">
                        {{ certificate.reminder_message }}
                    </div>

                    <DetailGrid :rows="rows" />

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('certificate.links') }}</h4>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <button type="button" class="card p-4 text-left hover:border-brand-300 disabled:opacity-60" :disabled="!certificate.beneficiary_id" @click="openBeneficiary">
                            <div class="text-xs text-slate-400 uppercase">{{ t('field.beneficiary_id') }}</div>
                            <div class="font-medium text-slate-800">{{ certificate.beneficiary_name || t('certificate.no_beneficiary') }}</div>
                            <div v-if="certificate.beneficiary_email" class="text-xs text-slate-500">{{ certificate.beneficiary_email }}</div>
                        </button>
                        <button type="button" class="card p-4 text-left hover:border-brand-300" @click="openPayer">
                            <div class="text-xs text-slate-400 uppercase">{{ t('field.payer_id') }}</div>
                            <div class="font-medium text-slate-800">{{ certificate.company_name }}</div>
                            <div class="text-xs text-slate-500">{{ certificate.contact_person }}</div>
                        </button>
                    </div>

                    <div v-if="certificate.previous || certificate.next" class="mt-4 text-sm space-y-1">
                        <p v-if="certificate.previous">
                            {{ t('certificate.previous') }}:
                            <button type="button" class="text-brand-700 hover:underline" @click="openLinked(certificate.previous)">{{ certificate.previous.name }} ({{ format.date(certificate.previous.expiry_date) }})</button>
                        </p>
                        <p v-if="certificate.next">
                            {{ t('certificate.next') }}:
                            <button type="button" class="text-brand-700 hover:underline" @click="openLinked(certificate.next)">{{ certificate.next.name }} ({{ format.date(certificate.next.expiry_date) }})</button>
                        </p>
                    </div>

                    <template v-if="can('tasks.view')">
                        <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('task.renewal_section') }}</h4>
                        <div class="card p-4">
                            <div v-if="openTask" class="flex flex-wrap items-center justify-between gap-3">
                                <button type="button" class="text-left" @click="openTaskDrawer(openTask)">
                                    <span class="block text-sm font-medium text-slate-800">{{ t('task.open_task') }}: {{ t('task.status.' + openTask.status) }}</span>
                                    <span class="block text-xs text-slate-500">{{ labels.priority(openTask.priority) }} · {{ openTask.assignee_name || t('task.unassigned') }}</span>
                                </button>
                                <span :class="['badge', CertiSub.taskBadge(openTask.status)]">{{ t('task.status.' + openTask.status) }}</span>
                            </div>
                            <p v-else class="text-sm text-slate-500">{{ t('task.no_open_task') }}</p>
                            <div v-if="!certificate.archived_at" class="flex flex-wrap gap-2 mt-3">
                                <button v-if="!openTask && can('tasks.update')" type="button" class="btn-secondary" @click="createTask">+ {{ t('task.create') }}</button>
                                <button v-if="can('invitations.send')" type="button" class="btn-secondary" @click="invite">✉️ {{ t('invitation.send') }}</button>
                            </div>
                            <ul v-if="invitations.length" class="mt-3 divide-y divide-slate-100 border-t border-slate-100">
                                <li v-for="invitation in invitations" :key="invitation.id">
                                    <button type="button" class="w-full text-left py-2 flex items-center justify-between gap-3 text-sm" @click="openInvitation(invitation)">
                                        <span>
                                            <span class="block text-slate-800">{{ invitation.recipient_email }}</span>
                                            <span class="block text-xs text-slate-500">{{ format.dateTime(invitation.sent_at || invitation.created_at) }} · {{ t('invitation.reminders') }}: {{ invitation.reminder_count }}</span>
                                        </span>
                                        <span :class="['badge', CertiSub.invitationBadge(invitation.status)]">{{ t('invitation.status.' + invitation.status) }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </template>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('timeline.title') }}</h4>
                    <EventTimeline :events="certificate.events" />
                </template>
            </DrawerShell>
        `,
    };

    function emptyCertificateForm() {
        return {
            name: '',
            certificate_type: 'QUALIFIED_SIGNATURE',
            serial_number: '',
            issuer: '',
            valid_from: '',
            expiry_date: '',
            renewal_lead_days: '30',
            user_id: '',
            beneficiary_id: '',
            payer_id: '',
            status: 'active',
            discount_percent: '0',
            billing_cycle: 'annual',
            payment_status: 'paid',
            last_payment_date: '',
            auto_renew: false,
            notes: '',
        };
    }

    CertiSub.components.CertificateForm = {
        props: {
            certificateId: { type: Number, default: null },
            preset: { type: Object, default: () => ({}) },
        },
        emits: ['close', 'saved'],
        data() {
            return {
                form: emptyCertificateForm(),
                options: null,
                loading: true,
                saving: false,
                errors: {},
                message: '',
            };
        },
        computed: {
            isEdit() {
                return this.certificateId !== null;
            },
            canAssignOwner() {
                return CertiSub.can('certificates.assign_owner');
            },
            /** Typowe rabaty do jednego kliknięcia; w polu można wpisać dowolną wartość 0–100. */
            discountPresets() {
                return ['0', '-3', '-5', '-10', '-15', '-20'];
            },
        },
        async mounted() {
            try {
                this.options = await CertiSub.data.certificateOptions(true);
                if (this.isEdit) {
                    const data = await api.get(endpoints.certificates, { id: this.certificateId });
                    this.fill(data.certificate);
                } else {
                    Object.assign(this.form, this.preset || {});
                    if (!this.form.user_id) {
                        this.form.user_id = String(CertiSub.boot.user.id);
                    }
                    this.onBeneficiaryChange();
                }
            } catch (error) {
                this.message = error.message;
            } finally {
                this.loading = false;
            }
        },
        methods: {
            fill(certificate) {
                const form = emptyCertificateForm();
                Object.keys(form).forEach((key) => {
                    const value = certificate[key];
                    if (key === 'auto_renew') {
                        form[key] = Boolean(value);
                    } else if (value === null || value === undefined) {
                        form[key] = '';
                    } else {
                        form[key] = String(value);
                    }
                });
                form.discount_percent = Number(certificate.discount_percent || 0) > 0 ? '-' + Number(certificate.discount_percent) : '0';
                this.form = form;
            },
            onBeneficiaryChange() {
                if (!this.options || !this.form.beneficiary_id || this.form.payer_id) {
                    return;
                }
                const beneficiary = this.options.beneficiaries.find((item) => String(item.id) === String(this.form.beneficiary_id));
                if (beneficiary && beneficiary.payer_id) {
                    this.form.payer_id = String(beneficiary.payer_id);
                }
            },
            addPayer() {
                CertiSub.openModal('PayerForm', {}, async (payer) => {
                    this.options = await CertiSub.data.certificateOptions(true);
                    this.form.payer_id = String(payer.id);
                });
            },
            addBeneficiary() {
                CertiSub.openModal('BeneficiaryForm', { preset: { payer_id: this.form.payer_id } }, async (beneficiary) => {
                    this.options = await CertiSub.data.certificateOptions(true);
                    this.form.beneficiary_id = String(beneficiary.id);
                    this.onBeneficiaryChange();
                });
            },
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.certificates, {
                        action: this.isEdit ? 'update' : 'create',
                        id: this.certificateId,
                        data: this.form,
                    });
                    CertiSub.notify(t(this.isEdit ? 'certificate.updated' : 'certificate.created'));
                    CertiSub.data.refresh('certificates', 'summary', 'beneficiaries', 'payers', 'options');
                    this.$emit('saved', result.certificate);
                    this.$emit('close');
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                } finally {
                    this.saving = false;
                }
            },
            personName(item) {
                return CertiSub.format.person(item.first_name, item.last_name) + (item.email ? ' — ' + item.email : '');
            },
        },
        template: `
            <ModalShell :title="isEdit ? t('certificate.edit_title') : t('certificate.add_title')"
                        :subtitle="t('common.required_hint')" size="xl" :busy="saving" @close="$emit('close')">
                <FormSkeleton v-if="loading" />
                <form v-else id="certificate-form" class="space-y-6" novalidate @submit.prevent="submit">
                    <div v-if="message" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>

                    <fieldset class="grid md:grid-cols-2 gap-4">
                        <legend class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2 md:col-span-2">{{ t('certificate.section.basic') }}</legend>
                        <FormField class="md:col-span-2" :label="t('field.name')" :error="errors.name" required>
                            <input v-model="form.name" type="text" maxlength="255" :class="['input', errors.name ? 'input-error' : '']" required>
                        </FormField>
                        <FormField :label="t('field.certificate_type')" :error="errors.certificate_type" required>
                            <select v-model="form.certificate_type" class="input">
                                <option v-for="type in options.types" :key="type" :value="type">{{ labels.type(type) }}</option>
                            </select>
                        </FormField>
                        <FormField :label="t('field.status')" :error="errors.status">
                            <select v-model="form.status" class="input">
                                <option v-for="status in options.statuses" :key="status" :value="status">{{ labels.status(status) }}</option>
                            </select>
                        </FormField>
                        <FormField :label="t('field.serial_number')" :error="errors.serial_number" :hint="t('certificate.hint.serial')">
                            <input v-model="form.serial_number" type="text" maxlength="128" :class="['input font-mono', errors.serial_number ? 'input-error' : '']">
                        </FormField>
                        <FormField :label="t('field.issuer')" :error="errors.issuer">
                            <input v-model="form.issuer" type="text" maxlength="255" class="input" :placeholder="t('certificate.placeholder.issuer')">
                        </FormField>
                    </fieldset>

                    <fieldset class="grid md:grid-cols-3 gap-4">
                        <legend class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2 md:col-span-3">{{ t('certificate.section.validity') }}</legend>
                        <FormField :label="t('field.valid_from')" :error="errors.valid_from">
                            <input v-model="form.valid_from" type="date" :class="['input', errors.valid_from ? 'input-error' : '']">
                        </FormField>
                        <FormField :label="t('field.expiry_date')" :error="errors.expiry_date" required>
                            <input v-model="form.expiry_date" type="date" :class="['input', errors.expiry_date ? 'input-error' : '']" required>
                        </FormField>
                        <FormField :label="t('field.renewal_lead_days')" :error="errors.renewal_lead_days" :hint="t('certificate.hint.lead_days')">
                            <input v-model="form.renewal_lead_days" type="number" min="0" max="3650" class="input">
                        </FormField>
                    </fieldset>

                    <fieldset class="grid md:grid-cols-2 gap-4">
                        <legend class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2 md:col-span-2">{{ t('certificate.section.links') }}</legend>
                        <FormField :label="t('field.beneficiary_id')" :error="errors.beneficiary_id" :hint="t('certificate.hint.beneficiary')">
                            <div class="flex gap-2">
                                <select v-model="form.beneficiary_id" class="input" @change="onBeneficiaryChange">
                                    <option value="">{{ t('certificate.no_beneficiary') }}</option>
                                    <option v-for="item in options.beneficiaries" :key="item.id" :value="String(item.id)">{{ personName(item) }}</option>
                                </select>
                                <button v-if="can('beneficiaries.create')" type="button" class="btn-secondary shrink-0" :title="t('beneficiary.add')" @click="addBeneficiary">+</button>
                            </div>
                        </FormField>
                        <FormField :label="t('field.payer_id')" :error="errors.payer_id" required>
                            <div class="flex gap-2">
                                <select v-model="form.payer_id" :class="['input', errors.payer_id ? 'input-error' : '']">
                                    <option value="">{{ t('common.choose') }}</option>
                                    <option v-for="item in options.payers" :key="item.id" :value="String(item.id)">{{ item.company_name }}{{ item.tax_id ? ' (' + item.tax_id + ')' : '' }}</option>
                                </select>
                                <button v-if="can('payers.create')" type="button" class="btn-secondary shrink-0" :title="t('payer.add')" @click="addPayer">+</button>
                            </div>
                        </FormField>
                        <FormField v-if="canAssignOwner" :label="t('field.user_id')" :error="errors.user_id" :hint="t('certificate.hint.owner')">
                            <select v-model="form.user_id" class="input">
                                <option v-for="owner in options.owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }} ({{ labels.role(owner.role) }})</option>
                            </select>
                        </FormField>
                    </fieldset>

                    <fieldset class="grid md:grid-cols-3 gap-4">
                        <legend class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2 md:col-span-3">{{ t('certificate.section.billing') }}</legend>
                        <FormField :label="t('field.discount_percent')" :error="errors.discount_percent" :hint="t('certificate.hint.discount')">
                            <input v-model="form.discount_percent" type="text" inputmode="decimal" list="discount-presets" maxlength="8"
                                   :class="['input', errors.discount_percent ? 'input-error' : '']">
                            <datalist id="discount-presets">
                                <option v-for="preset in discountPresets" :key="preset" :value="preset"></option>
                            </datalist>
                            <div class="flex flex-wrap gap-1 mt-2">
                                <button v-for="preset in discountPresets" :key="'chip-' + preset" type="button"
                                        :class="['badge cursor-pointer', form.discount_percent === preset ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-slate-600 hover:bg-slate-200']"
                                        @click="form.discount_percent = preset">{{ preset === '0' ? '0%' : preset + '%' }}</button>
                            </div>
                        </FormField>
                        <FormField :label="t('field.billing_cycle')" :error="errors.billing_cycle">
                            <select v-model="form.billing_cycle" class="input">
                                <option v-for="cycle in options.billing_cycles" :key="cycle" :value="cycle">{{ labels.billing(cycle) }}</option>
                            </select>
                        </FormField>
                        <FormField :label="t('field.payment_status')" :error="errors.payment_status">
                            <select v-model="form.payment_status" class="input">
                                <option v-for="status in options.payment_statuses" :key="status" :value="status">{{ labels.payment(status) }}</option>
                            </select>
                        </FormField>
                        <FormField :label="t('field.last_payment_date')" :error="errors.last_payment_date">
                            <input v-model="form.last_payment_date" type="date" class="input">
                        </FormField>
                        <label class="flex items-center gap-2 text-sm text-slate-700 mt-6">
                            <input v-model="form.auto_renew" type="checkbox" class="rounded border-slate-300">
                            {{ t('field.auto_renew') }}
                        </label>
                    </fieldset>

                    <FormField :label="t('field.notes')" :error="errors.notes">
                        <textarea v-model="form.notes" rows="3" maxlength="5000" class="input"></textarea>
                    </FormField>
                </form>

                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="certificate-form" class="btn-primary" :disabled="saving || loading">{{ saving ? t('common.saving') : t('common.save') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
