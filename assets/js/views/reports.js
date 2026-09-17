/*
 * Raporty perspektyw z opisu pracy (F6, F7, F8): karta użytkownika certyfikatu (#/beneficiaries/N),
 * karta płatnika (#/payers/N), wejście do perspektywy administratora i harmonogram wygaśnięć.
 * Karty i harmonogram można wydrukować — elementy sterujące mają klasę no-print.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints, format, labels } = CertiSub;

    function printPage() {
        window.print();
    }

    function openCertificate(id) {
        CertiSub.openDrawer('certificate', id);
    }

    CertiSub.components.KpiTile = {
        props: {
            label: { type: String, required: true },
            value: { type: [String, Number], default: '—' },
            hint: { type: String, default: '' },
            tone: { type: String, default: 'default' },
        },
        computed: {
            toneClass() {
                return {
                    danger: 'text-red-700',
                    warning: 'text-amber-700',
                    success: 'text-emerald-700',
                }[this.tone] || 'text-slate-900';
            },
        },
        template: `
            <div class="card p-4 break-inside-avoid">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ label }}</p>
                <p :class="['text-2xl font-bold mt-1 break-words', toneClass]">{{ value }}</p>
                <p v-if="hint" class="text-xs text-slate-500 mt-1 break-words">{{ hint }}</p>
            </div>
        `,
    };

    CertiSub.components.ReportSection = {
        props: {
            title: { type: String, required: true },
            count: { type: Number, default: null },
            description: { type: String, default: '' },
        },
        template: `
            <section class="card mb-6">
                <header class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">
                            {{ title }}<span v-if="count !== null" class="ml-2 text-sm font-normal text-slate-400">{{ count }}</span>
                        </h3>
                        <p v-if="description" class="text-xs text-slate-500 mt-0.5">{{ description }}</p>
                    </div>
                    <div v-if="$slots.actions" class="no-print flex flex-wrap gap-2"><slot name="actions"></slot></div>
                </header>
                <slot></slot>
            </section>
        `,
    };

    /**
     * Bieżące certyfikaty z datami odnowienia, priorytetem, zadaniem i kontaktem.
     */
    CertiSub.components.CertificateReportTable = {
        props: {
            certificates: { type: Array, required: true },
            showBeneficiary: { type: Boolean, default: false },
            showPayer: { type: Boolean, default: false },
            showCost: { type: Boolean, default: false },
        },
        computed: {
            columns() {
                return 6 + (this.showBeneficiary ? 1 : 0) + (this.showPayer ? 1 : 0) + (this.showCost ? 1 : 0);
            },
        },
        methods: {
            openCertificate,
            openBeneficiary: (id) => CertiSub.openBeneficiaryCard(id),
            openPayer: (id) => CertiSub.openPayerCard(id),
            openTask: (id) => CertiSub.openDrawer('task', id),
            leadText(item) {
                return item.renewal_lead_custom
                    ? t('report.lead_custom', { days: item.renewal_lead_days })
                    : t('report.lead_default', { days: item.renewal_lead_days });
            },
        },
        template: `
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[900px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="th">{{ t('report.column.certificate') }}</th>
                            <th v-if="showBeneficiary" class="th">{{ t('field.beneficiary_id') }}</th>
                            <th v-if="showPayer" class="th">{{ t('field.payer_id') }}</th>
                            <th class="th">{{ t('report.column.validity') }}</th>
                            <th class="th">{{ t('report.column.renewal_from') }}</th>
                            <th class="th">{{ t('report.column.days_left') }}</th>
                            <th class="th">{{ t('report.column.task') }}</th>
                            <th class="th">{{ t('report.column.contact') }}</th>
                            <th v-if="showCost" class="th text-right">{{ t('report.column.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in certificates" :key="item.id" :class="['border-b border-slate-100', badge.row(item.priority)]">
                            <td class="td">
                                <button type="button" class="font-medium text-slate-800 hover:text-brand-700 hover:underline text-left" @click="openCertificate(item.id)">{{ item.name }}</button>
                                <div class="text-xs text-slate-500">{{ labels.type(item.certificate_type) }}<span v-if="item.serial_number"> · {{ item.serial_number }}</span></div>
                                <div v-if="item.previous_certificate_id" class="text-xs text-slate-400">↩ {{ t('report.renewal_of', { id: item.previous_certificate_id }) }}</div>
                            </td>
                            <td v-if="showBeneficiary" class="td">
                                <button v-if="item.beneficiary" type="button" class="text-brand-700 hover:underline text-left" @click="openBeneficiary(item.beneficiary.id)">{{ item.beneficiary.name }}</button>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td v-if="showPayer" class="td">
                                <button type="button" class="text-brand-700 hover:underline text-left" @click="openPayer(item.payer.id)">{{ item.payer.name }}</button>
                            </td>
                            <td class="td whitespace-nowrap">
                                <span v-if="item.valid_from">{{ format.date(item.valid_from) }} – </span>{{ format.date(item.expiry_date) }}
                            </td>
                            <td class="td whitespace-nowrap">
                                {{ format.date(item.renewal_from) }}
                                <div class="text-xs text-slate-500">{{ leadText(item) }}</div>
                                <span v-if="item.in_renewal_window" class="badge bg-blue-50 text-blue-700 border border-blue-200 mt-1">{{ t('report.in_window') }}</span>
                            </td>
                            <td class="td whitespace-nowrap">
                                <span :class="['badge', badge.priority(item.priority)]">{{ labels.priority(item.priority) }}</span>
                                <div class="text-xs text-slate-500 mt-1">{{ format.days(item.days_left) }}</div>
                            </td>
                            <td class="td">
                                <template v-if="item.open_task">
                                    <button type="button" :class="['badge', CertiSub.taskBadge(item.open_task.status)]" @click="openTask(item.open_task.id)">{{ t('task.status.' + item.open_task.status) }}</button>
                                    <div class="text-xs text-slate-500 mt-1">{{ item.open_task.assignee_name || t('task.unassigned') }}</div>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="td text-xs text-slate-600 whitespace-nowrap">
                                <template v-if="item.invitation_count">
                                    {{ t('report.invitations_count', { count: item.invitation_count }) }}
                                    <div v-if="item.last_contact_at">{{ t('report.last_contact', { date: format.date(item.last_contact_at) }) }}</div>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td v-if="showCost" class="td text-right whitespace-nowrap">
                                {{ format.money(item.annual_cost, item.currency) }}
                                <div class="text-xs text-slate-500">{{ labels.billing(item.billing_cycle) }}</div>
                                <div v-if="item.billing_cycle !== 'annual'" class="text-xs text-slate-400">{{ t('report.cost_annualized', { amount: format.money(item.annualized_cost, item.currency) }) }}</div>
                            </td>
                        </tr>
                        <tr v-if="certificates.length === 0">
                            <td :colspan="columns" class="px-4 py-8 text-center text-slate-400">{{ t('report.empty.certificates') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `,
    };

    /**
     * Certyfikaty z archiwum wraz z informacją, który certyfikat je zastąpił (łańcuch odnowień).
     */
    CertiSub.components.CertificateHistoryTable = {
        props: {
            certificates: { type: Array, required: true },
            showBeneficiary: { type: Boolean, default: false },
            showPayer: { type: Boolean, default: false },
        },
        methods: {
            openCertificate,
            openBeneficiary: (id) => CertiSub.openBeneficiaryCard(id),
            openPayer: (id) => CertiSub.openPayerCard(id),
        },
        template: `
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[720px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="th">{{ t('report.column.certificate') }}</th>
                            <th v-if="showBeneficiary" class="th">{{ t('field.beneficiary_id') }}</th>
                            <th v-if="showPayer" class="th">{{ t('field.payer_id') }}</th>
                            <th class="th">{{ t('report.column.validity') }}</th>
                            <th class="th">{{ t('report.column.archived_at') }}</th>
                            <th class="th">{{ t('report.column.replaced_by') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in certificates" :key="item.id" class="border-b border-slate-100">
                            <td class="td">
                                <button type="button" class="font-medium text-slate-800 hover:text-brand-700 hover:underline text-left" @click="openCertificate(item.id)">{{ item.name }}</button>
                                <div class="text-xs text-slate-500">{{ labels.type(item.certificate_type) }}<span v-if="item.serial_number"> · {{ item.serial_number }}</span></div>
                            </td>
                            <td v-if="showBeneficiary" class="td">
                                <button v-if="item.beneficiary" type="button" class="text-brand-700 hover:underline text-left" @click="openBeneficiary(item.beneficiary.id)">{{ item.beneficiary.name }}</button>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td v-if="showPayer" class="td">
                                <button type="button" class="text-brand-700 hover:underline text-left" @click="openPayer(item.payer.id)">{{ item.payer.name }}</button>
                            </td>
                            <td class="td whitespace-nowrap"><span v-if="item.valid_from">{{ format.date(item.valid_from) }} – </span>{{ format.date(item.expiry_date) }}</td>
                            <td class="td whitespace-nowrap">{{ format.date(item.archived_at) }}</td>
                            <td class="td">
                                <button v-if="item.next_certificate_id" type="button" class="text-brand-700 hover:underline" @click="openCertificate(item.next_certificate_id)">🔁 #{{ item.next_certificate_id }}</button>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="certificates.length === 0">
                            <td :colspan="4 + (showBeneficiary ? 1 : 0) + (showPayer ? 1 : 0)" class="px-4 py-8 text-center text-slate-400">{{ t('report.empty.history') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `,
    };

    /**
     * Ścieżka realizacji: wszystkie zadania odnowień (także zamknięte).
     */
    CertiSub.components.TaskReportTable = {
        props: {
            tasks: { type: Array, required: true },
        },
        methods: {
            openTask: (id) => CertiSub.openDrawer('task', id),
            openCertificate,
        },
        template: `
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[820px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="th">{{ t('report.column.certificate') }}</th>
                            <th class="th">{{ t('field.status') }}</th>
                            <th class="th">{{ t('report.column.priority') }}</th>
                            <th class="th">{{ t('task.due') }}</th>
                            <th class="th">{{ t('task.assignee') }}</th>
                            <th class="th">{{ t('report.column.opened') }}</th>
                            <th class="th">{{ t('report.column.closed') }}</th>
                            <th class="th">{{ t('report.column.note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="task in tasks" :key="task.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="openTask(task.id)">
                            <td class="td font-medium text-slate-800">{{ task.certificate_name }}</td>
                            <td class="td"><span :class="['badge', CertiSub.taskBadge(task.status)]">{{ t('task.status.' + task.status) }}</span></td>
                            <td class="td"><span :class="['badge', badge.priority(task.priority)]">{{ labels.priority(task.priority) }}</span></td>
                            <td class="td whitespace-nowrap">{{ format.date(task.due_date) }}</td>
                            <td class="td">{{ task.assignee_name || t('task.unassigned') }}</td>
                            <td class="td whitespace-nowrap">{{ format.date(task.created_at) }}</td>
                            <td class="td whitespace-nowrap">{{ task.closed_at ? format.date(task.closed_at) : '—' }}</td>
                            <td class="td text-xs text-slate-600 max-w-xs break-words">{{ task.resolution_note || '—' }}</td>
                        </tr>
                        <tr v-if="tasks.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-slate-400">{{ t('report.empty.tasks') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `,
    };

    CertiSub.components.InvitationReportTable = {
        props: {
            invitations: { type: Array, required: true },
        },
        methods: {
            openInvitation: (id) => CertiSub.openDrawer('invitation', id),
        },
        template: `
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[760px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="th">{{ t('report.column.certificate') }}</th>
                            <th class="th">{{ t('invitation.recipient') }}</th>
                            <th class="th">{{ t('field.status') }}</th>
                            <th class="th">{{ t('report.column.sent_at') }}</th>
                            <th class="th">{{ t('report.column.reminders') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in invitations" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="openInvitation(item.id)">
                            <td class="td font-medium text-slate-800">
                                {{ item.certificate_name }}
                                <div class="text-xs text-slate-500 font-normal break-words">{{ item.subject }}</div>
                            </td>
                            <td class="td">
                                {{ item.recipient_name || item.recipient_email }}
                                <div class="text-xs text-slate-500">{{ t('invitation.recipient_type.' + item.recipient_type) }} · {{ item.recipient_email }}</div>
                            </td>
                            <td class="td"><span :class="['badge', CertiSub.invitationBadge(item.status)]">{{ t('invitation.status.' + item.status) }}</span></td>
                            <td class="td whitespace-nowrap">{{ item.sent_at ? format.dateTime(item.sent_at) : '—' }}</td>
                            <td class="td whitespace-nowrap">
                                {{ item.reminder_count }}
                                <div v-if="item.next_reminder_at && (item.status === 'sent' || item.status === 'failed')" class="text-xs text-slate-500">{{ t('report.next_reminder', { date: format.date(item.next_reminder_at) }) }}</div>
                            </td>
                        </tr>
                        <tr v-if="invitations.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ t('report.empty.invitations') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `,
    };

    /**
     * Harmonogram wygaśnięć: wykres słupkowy liczby certyfikatów wg miesiąca i lista pod nim.
     * Lista jest tekstową wersją wykresu (wykres jest ukryty dla czytników ekranu).
     */
    CertiSub.components.ExpirySchedule = {
        props: {
            buckets: { type: Array, required: true },
            showBeneficiary: { type: Boolean, default: true },
            showPayer: { type: Boolean, default: true },
        },
        computed: {
            max() {
                return Math.max(1, ...this.buckets.map((bucket) => bucket.count));
            },
            filled() {
                return this.buckets.filter((bucket) => bucket.count > 0);
            },
        },
        methods: {
            openCertificate,
            openBeneficiary: (id) => CertiSub.openBeneficiaryCard(id),
            openPayer: (id) => CertiSub.openPayerCard(id),
            openTask: (id) => CertiSub.openDrawer('task', id),
            barHeight(bucket) {
                return bucket.count === 0 ? '2px' : Math.max(8, Math.round((bucket.count / this.max) * 100)) + '%';
            },
            bucketLabel(bucket, style) {
                return bucket.overdue ? t('report.schedule.overdue') : format.month(bucket.month, style);
            },
        },
        template: `
            <div>
                <div class="px-5 pt-5 overflow-x-auto" aria-hidden="true">
                    <div class="flex items-end gap-1.5 h-40 min-w-[560px]">
                        <div v-for="bucket in buckets" :key="bucket.key" class="flex-1 h-full flex flex-col items-center justify-end">
                            <span class="text-xs font-semibold text-slate-700 mb-1">{{ bucket.count || '' }}</span>
                            <div :class="['w-full rounded-t', bucket.overdue ? 'bg-red-400' : 'bg-brand-500']" :style="{ height: barHeight(bucket) }"></div>
                        </div>
                    </div>
                    <div class="flex gap-1.5 min-w-[560px] border-t border-slate-200 pt-1">
                        <span v-for="bucket in buckets" :key="bucket.key" :class="['flex-1 text-center text-[10px] leading-tight', bucket.overdue ? 'text-red-600 font-semibold' : 'text-slate-500']">{{ bucketLabel(bucket, 'short') }}</span>
                    </div>
                </div>

                <p v-if="filled.length === 0" class="px-5 py-8 text-center text-sm text-slate-400">{{ t('report.schedule.empty') }}</p>
                <div v-for="bucket in filled" :key="bucket.key" class="px-5 py-4 border-t border-slate-100 mt-4">
                    <h4 :class="['text-sm font-semibold mb-2', bucket.overdue ? 'text-red-700' : 'text-slate-700']">
                        {{ bucketLabel(bucket, 'long') }} <span class="font-normal text-slate-400">· {{ bucket.count }}</span>
                    </h4>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="item in bucket.certificates" :key="item.id" class="py-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="w-24 shrink-0 whitespace-nowrap text-slate-600">{{ format.date(item.expiry_date) }}</span>
                            <span :class="['badge', badge.priority(item.priority)]">{{ format.days(item.days_left) }}</span>
                            <button type="button" class="font-medium text-slate-800 hover:text-brand-700 hover:underline text-left" @click="openCertificate(item.id)">{{ item.name }}</button>
                            <button v-if="showBeneficiary && item.beneficiary" type="button" class="text-xs text-brand-700 hover:underline" @click="openBeneficiary(item.beneficiary.id)">👤 {{ item.beneficiary.name }}</button>
                            <button v-if="showPayer" type="button" class="text-xs text-brand-700 hover:underline" @click="openPayer(item.payer.id)">🏢 {{ item.payer.name }}</button>
                            <button v-if="item.open_task" type="button" :class="['badge ml-auto', CertiSub.taskBadge(item.open_task.status)]" @click="openTask(item.open_task.id)">{{ t('task.status.' + item.open_task.status) }}</button>
                            <span v-else class="badge ml-auto bg-slate-50 text-slate-500 border border-slate-200">{{ t('report.no_task') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        `,
    };

    CertiSub.components.ReportsView = {
        data() {
            return {
                store: CertiSub.store,
                beneficiaryId: '',
                payerId: '',
                months: 12,
                payerFilter: '',
                typeFilter: '',
                schedule: null,
                loading: false,
                error: '',
            };
        },
        computed: {
            adminLinks() {
                return [
                    { view: 'events', icon: '🧾', label: t('nav.events'), permission: 'events.view_all' },
                    { view: 'accounts', icon: '🔐', label: t('nav.accounts'), permission: 'accounts.manage' },
                    { view: 'templates', icon: '🧩', label: t('nav.templates'), permission: 'templates.manage' },
                    { view: 'settings', icon: '⚙️', label: t('nav.settings'), permission: 'settings.manage' },
                ].filter((link) => CertiSub.can(link.permission));
            },
            costText() {
                if (!this.schedule || this.schedule.annual_cost.length === 0) {
                    return '';
                }
                return this.schedule.annual_cost.map((item) => format.money(item.amount, item.currency)).join(' + ');
            },
        },
        watch: {
            months() {
                this.loadSchedule();
            },
            payerFilter() {
                this.loadSchedule();
            },
            typeFilter() {
                this.loadSchedule();
            },
        },
        mounted() {
            CertiSub.data.beneficiaries();
            CertiSub.data.payers();
            this.loadSchedule();
        },
        methods: {
            async loadSchedule() {
                this.loading = true;
                this.error = '';
                try {
                    const data = await api.get(endpoints.reports, {
                        view: 'schedule',
                        months: this.months,
                        payer_id: this.payerFilter,
                        certificate_type: this.typeFilter,
                    });
                    this.schedule = data.report;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            openBeneficiary() {
                if (this.beneficiaryId) {
                    CertiSub.navigate('beneficiaries', this.beneficiaryId);
                }
            },
            openPayer() {
                if (this.payerId) {
                    CertiSub.navigate('payers', this.payerId);
                }
            },
            go(view) {
                CertiSub.navigate(view);
            },
            print: printPage,
        },
        template: `
            <section>
                <PageHeader :title="t('report.title')" :description="t('report.description')" />

                <div class="grid gap-4 lg:grid-cols-3 mb-8 no-print">
                    <div class="card p-5 flex flex-col">
                        <h3 class="font-semibold text-slate-900">👤 {{ t('report.perspective.beneficiary.title') }}</h3>
                        <p class="text-sm text-slate-500 mt-1 mb-4 flex-1">{{ t('report.perspective.beneficiary.description') }}</p>
                        <form class="flex gap-2" @submit.prevent="openBeneficiary">
                            <label class="sr-only" for="report-beneficiary">{{ t('report.choose_beneficiary') }}</label>
                            <select id="report-beneficiary" v-model="beneficiaryId" class="input">
                                <option value="">{{ t('report.choose_beneficiary') }}</option>
                                <option v-for="item in store.beneficiaries.items" :key="item.id" :value="String(item.id)">{{ item.last_name }} {{ item.first_name }}{{ item.payer_name ? ' — ' + item.payer_name : '' }}</option>
                            </select>
                            <button type="submit" class="btn-primary shrink-0" :disabled="!beneficiaryId">{{ t('report.open_card') }}</button>
                        </form>
                    </div>
                    <div class="card p-5 flex flex-col">
                        <h3 class="font-semibold text-slate-900">🏢 {{ t('report.perspective.payer.title') }}</h3>
                        <p class="text-sm text-slate-500 mt-1 mb-4 flex-1">{{ t('report.perspective.payer.description') }}</p>
                        <form class="flex gap-2" @submit.prevent="openPayer">
                            <label class="sr-only" for="report-payer">{{ t('report.choose_payer') }}</label>
                            <select id="report-payer" v-model="payerId" class="input">
                                <option value="">{{ t('report.choose_payer') }}</option>
                                <option v-for="item in store.payers.items" :key="item.id" :value="String(item.id)">{{ item.company_name }}{{ item.city ? ' — ' + item.city : '' }}</option>
                            </select>
                            <button type="submit" class="btn-primary shrink-0" :disabled="!payerId">{{ t('report.open_card') }}</button>
                        </form>
                    </div>
                    <div class="card p-5 flex flex-col">
                        <h3 class="font-semibold text-slate-900">🛡️ {{ t('report.perspective.admin.title') }}</h3>
                        <p class="text-sm text-slate-500 mt-1 mb-4 flex-1">{{ t('report.perspective.admin.description') }}</p>
                        <div v-if="adminLinks.length" class="flex flex-wrap gap-2">
                            <button v-for="link in adminLinks" :key="link.view" type="button" class="btn-secondary" @click="go(link.view)">{{ link.icon }} {{ link.label }}</button>
                        </div>
                        <p v-else class="text-sm text-slate-400">{{ t('report.perspective.admin.locked') }}</p>
                    </div>
                </div>

                <ReportSection :title="t('report.schedule.title')" :count="schedule ? schedule.total : null" :description="t('report.schedule.description')">
                    <template #actions>
                        <label class="sr-only" for="schedule-months">{{ t('report.schedule.months') }}</label>
                        <select id="schedule-months" v-model.number="months" class="input w-auto">
                            <option v-for="value in [3, 6, 12, 24]" :key="value" :value="value">{{ t('report.schedule.months_option', { count: value }) }}</option>
                        </select>
                        <label class="sr-only" for="schedule-payer">{{ t('field.payer_id') }}</label>
                        <select id="schedule-payer" v-model="payerFilter" class="input w-auto max-w-[14rem]">
                            <option value="">{{ t('report.schedule.all_payers') }}</option>
                            <option v-for="item in store.payers.items" :key="item.id" :value="String(item.id)">{{ item.company_name }}</option>
                        </select>
                        <label class="sr-only" for="schedule-type">{{ t('field.certificate_type') }}</label>
                        <select id="schedule-type" v-model="typeFilter" class="input w-auto">
                            <option value="">{{ t('report.schedule.all_types') }}</option>
                            <option v-for="type in labels.types" :key="type" :value="type">{{ labels.type(type) }}</option>
                        </select>
                        <button type="button" class="btn-secondary" @click="print">🖨️ {{ t('report.print') }}</button>
                    </template>
                    <p v-if="error" class="m-5 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</p>
                    <div v-else-if="loading && !schedule" class="p-5 space-y-3" aria-hidden="true">
                        <div class="h-32 bg-slate-100 rounded"></div>
                        <div class="h-4 bg-slate-100 rounded w-2/3"></div>
                    </div>
                    <div v-else-if="schedule" :class="loading ? 'opacity-60' : ''">
                        <p class="px-5 pt-4 text-sm text-slate-600">
                            {{ t('report.generated_at', { date: format.dateTime(schedule.generated_at) }) }}
                            <span v-if="costText"> · {{ t('report.schedule.cost', { amount: costText }) }}</span>
                        </p>
                        <ExpirySchedule :buckets="schedule.buckets" />
                    </div>
                </ReportSection>
            </section>
        `,
    };

    function kpiTone(summary) {
        if (summary.expired > 0) {
            return 'danger';
        }
        return summary.critical > 0 ? 'warning' : 'default';
    }

    const cardMethods = {
        print: printPage,
        async load() {
            this.loading = true;
            this.error = '';
            try {
                const data = await api.get(endpoints.reports, { view: this.reportType, id: this.id });
                this.report = data.report;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
    };

    const cardSkeleton = `
        <div class="space-y-4" aria-hidden="true">
            <div class="h-8 bg-slate-200 rounded w-1/3"></div>
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
                <div v-for="n in 6" :key="n" class="h-24 bg-slate-200 rounded-xl"></div>
            </div>
            <div class="h-64 bg-slate-200 rounded-xl"></div>
        </div>
    `;

    /**
     * Karta użytkownika certyfikatu — perspektywa Użytkownika (F6).
     */
    CertiSub.components.BeneficiaryCardView = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { report: null, loading: true, error: '', reportType: 'beneficiary' };
        },
        computed: {
            person() {
                return this.report.beneficiary;
            },
            summary() {
                return this.report.summary;
            },
            name() {
                return format.person(this.person.first_name, this.person.last_name);
            },
            rows() {
                const p = this.person;
                return [
                    { label: t('field.email'), value: p.email },
                    { label: t('field.phone'), value: p.phone },
                    { label: t('field.payer_id'), value: p.payer_name },
                    { label: t('common.created_at'), value: format.dateTime(p.created_at) },
                    { label: t('field.notes'), value: p.notes, wide: true },
                ];
            },
            kpis() {
                const s = this.summary;
                return [
                    {
                        label: t('report.kpi.active_certificates'),
                        value: s.active_certificates,
                        hint: [
                            s.archived_certificates ? t('report.kpi.archived_hint', { count: s.archived_certificates }) : '',
                            s.renewals ? t('report.kpi.renewals', { count: s.renewals }) : '',
                        ].filter(Boolean).join(' · '),
                    },
                    {
                        label: t('report.kpi.next_expiry'),
                        value: s.next_expiry ? format.date(s.next_expiry.expiry_date) : '—',
                        hint: s.next_expiry ? s.next_expiry.name + ' · ' + format.days(s.next_expiry.days_left) : '',
                    },
                    {
                        label: t('report.kpi.attention'),
                        value: s.expired + s.critical,
                        hint: t('report.kpi.attention_hint', { expired: s.expired, critical: s.critical, warning: s.expiring_soon, days: this.report.thresholds.warning }),
                        tone: kpiTone(s),
                    },
                    {
                        label: t('report.kpi.open_tasks'),
                        value: s.open_tasks,
                        hint: t('report.kpi.tasks_hint', { done: s.tasks_by_status.done, abandoned: s.tasks_by_status.abandoned }),
                    },
                    {
                        label: t('report.kpi.invitations'),
                        value: s.invitations_sent,
                        hint: t('report.kpi.reminders_hint', { count: s.reminders_sent }),
                    },
                    {
                        label: t('report.kpi.last_contact'),
                        value: s.last_contact_at ? format.date(s.last_contact_at) : '—',
                        hint: s.last_contact_at ? '' : t('report.kpi.never'),
                    },
                ];
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            ...cardMethods,
            back() {
                CertiSub.navigate('beneficiaries');
            },
            edit() {
                CertiSub.openModal('BeneficiaryForm', { beneficiaryId: this.id }, () => this.load());
            },
            details() {
                CertiSub.openDrawer('beneficiary', this.id);
            },
            openPayer() {
                CertiSub.openPayerCard(this.person.payer_id);
            },
        },
        template: `
            <section>
                <button type="button" class="no-print text-sm text-brand-700 hover:underline mb-4" @click="back">← {{ t('beneficiary.plural') }}</button>
                ${cardSkeleton.replace('<div class="space-y-4"', '<div v-if="loading && !report" class="space-y-4"')}
                <p v-else-if="error" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</p>
                <template v-else-if="report">
                    <PageHeader :title="name" :description="t('report.beneficiary.subtitle')">
                        <button v-if="can('beneficiaries.update') && !person.archived_at" type="button" class="btn-secondary no-print" @click="edit">✏️ {{ t('common.edit') }}</button>
                        <button type="button" class="btn-secondary no-print" @click="details">{{ t('report.details') }}</button>
                        <button type="button" class="btn-secondary no-print" :disabled="loading" @click="load">↻ {{ t('report.refresh') }}</button>
                        <button type="button" class="btn-primary no-print" @click="print">🖨️ {{ t('report.print') }}</button>
                    </PageHeader>
                    <p class="text-xs text-slate-400 -mt-4 mb-4">{{ t('report.generated_at', { date: format.dateTime(report.generated_at) }) }}</p>

                    <div v-if="person.archived_at" class="mb-4 px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 text-sm">
                        {{ t('common.archived_on', { date: format.dateTime(person.archived_at) }) }}
                    </div>

                    <div class="card p-5 mb-6">
                        <DetailGrid :rows="rows" />
                        <p v-if="person.payer_id" class="mt-3 text-sm no-print">
                            <button type="button" class="text-brand-700 hover:underline" @click="openPayer">🏢 {{ t('report.open_payer_card') }} →</button>
                        </p>
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6">
                        <KpiTile v-for="kpi in kpis" :key="kpi.label" :label="kpi.label" :value="kpi.value" :hint="kpi.hint" :tone="kpi.tone || 'default'" />
                    </div>

                    <ReportSection :title="t('report.section.certificates')" :count="report.certificates.length"
                                   :description="t('report.section.certificates_hint', { days: report.thresholds.warning })">
                        <CertificateReportTable :certificates="report.certificates" show-payer />
                    </ReportSection>

                    <ReportSection v-if="report.archive_access" :title="t('report.section.history')" :count="report.history.length" :description="t('report.section.history_hint')">
                        <CertificateHistoryTable :certificates="report.history" show-payer />
                    </ReportSection>

                    <ReportSection :title="t('report.section.tasks')" :count="report.tasks.length" :description="t('report.section.tasks_hint')">
                        <TaskReportTable :tasks="report.tasks" />
                    </ReportSection>

                    <ReportSection :title="t('report.section.invitations')" :count="report.invitations.length">
                        <InvitationReportTable :invitations="report.invitations" />
                    </ReportSection>

                    <ReportSection :title="t('report.section.timeline')" :count="report.timeline.length" :description="t('report.section.timeline_hint')">
                        <div class="p-5">
                            <EventTimeline :events="report.timeline" show-certificate filterable group-by-day />
                        </div>
                    </ReportSection>
                </template>
            </section>
        `,
    };

    /**
     * Karta płatnika — perspektywa Płatnika (F7).
     */
    CertiSub.components.PayerCardView = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { report: null, loading: true, error: '', reportType: 'payer' };
        },
        computed: {
            payer() {
                return this.report.payer;
            },
            summary() {
                return this.report.summary;
            },
            rows() {
                const p = this.payer;
                const address = [p.address_line, [p.postal_code, p.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
                return [
                    { label: t('field.tax_id'), value: p.tax_id },
                    { label: t('field.contact_person'), value: p.contact_person },
                    { label: t('field.email'), value: p.email },
                    { label: t('field.phone'), value: p.phone },
                    { label: t('payer.address'), value: address },
                    { label: t('common.created_at'), value: format.dateTime(p.created_at) },
                ];
            },
            costText() {
                const totals = this.summary.annual_cost;
                return totals.length ? totals.map((item) => format.money(item.amount, item.currency)).join(' + ') : '—';
            },
            kpis() {
                const s = this.summary;
                return [
                    { label: t('report.kpi.beneficiaries'), value: s.beneficiaries },
                    {
                        label: t('report.kpi.active_certificates'),
                        value: s.active_certificates,
                        hint: [
                            s.archived_certificates ? t('report.kpi.archived_hint', { count: s.archived_certificates }) : '',
                            s.renewals ? t('report.kpi.renewals', { count: s.renewals }) : '',
                        ].filter(Boolean).join(' · '),
                    },
                    {
                        label: t('report.kpi.next_expiry'),
                        value: s.next_expiry ? format.date(s.next_expiry.expiry_date) : '—',
                        hint: s.next_expiry ? s.next_expiry.name + ' · ' + format.days(s.next_expiry.days_left) : '',
                    },
                    {
                        label: t('report.kpi.attention'),
                        value: s.expired + s.critical,
                        hint: t('report.kpi.attention_hint', { expired: s.expired, critical: s.critical, warning: s.expiring_soon, days: this.report.thresholds.warning }),
                        tone: kpiTone(s),
                    },
                    {
                        label: t('report.kpi.open_tasks'),
                        value: s.open_tasks,
                        hint: t('report.kpi.contact_hint', { sent: s.invitations_sent, date: s.last_contact_at ? format.date(s.last_contact_at) : '—' }),
                    },
                    { label: t('report.kpi.annual_cost'), value: this.costText, hint: t('report.kpi.annual_cost_hint') },
                ];
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            ...cardMethods,
            back() {
                CertiSub.navigate('payers');
            },
            edit() {
                CertiSub.openModal('PayerForm', { payerId: this.id }, () => this.load());
            },
            details() {
                CertiSub.openDrawer('payer', this.id);
            },
            openBeneficiary(id) {
                CertiSub.openBeneficiaryCard(id);
            },
        },
        template: `
            <section>
                <button type="button" class="no-print text-sm text-brand-700 hover:underline mb-4" @click="back">← {{ t('payer.plural') }}</button>
                ${cardSkeleton.replace('<div class="space-y-4"', '<div v-if="loading && !report" class="space-y-4"')}
                <p v-else-if="error" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</p>
                <template v-else-if="report">
                    <PageHeader :title="payer.company_name" :description="t('report.payer.subtitle')">
                        <button v-if="can('payers.update') && !payer.archived_at" type="button" class="btn-secondary no-print" @click="edit">✏️ {{ t('common.edit') }}</button>
                        <button type="button" class="btn-secondary no-print" @click="details">{{ t('report.details') }}</button>
                        <button type="button" class="btn-secondary no-print" :disabled="loading" @click="load">↻ {{ t('report.refresh') }}</button>
                        <button type="button" class="btn-primary no-print" @click="print">🖨️ {{ t('report.print') }}</button>
                    </PageHeader>
                    <p class="text-xs text-slate-400 -mt-4 mb-4">{{ t('report.generated_at', { date: format.dateTime(report.generated_at) }) }}</p>

                    <div v-if="payer.archived_at" class="mb-4 px-4 py-3 rounded-lg bg-slate-100 border border-slate-200 text-slate-700 text-sm">
                        {{ t('common.archived_on', { date: format.dateTime(payer.archived_at) }) }}
                    </div>

                    <div class="card p-5 mb-6">
                        <DetailGrid :rows="rows" />
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-3 mb-6">
                        <KpiTile v-for="kpi in kpis" :key="kpi.label" :label="kpi.label" :value="kpi.value" :hint="kpi.hint || ''" :tone="kpi.tone || 'default'" />
                    </div>

                    <ReportSection :title="t('report.section.beneficiaries')" :count="report.beneficiaries.length" :description="t('report.section.beneficiaries_hint')">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[720px]">
                                <thead class="bg-slate-50 border-b border-slate-200">
                                    <tr>
                                        <th class="th">{{ t('beneficiary.singular') }}</th>
                                        <th class="th">{{ t('report.column.contact') }}</th>
                                        <th class="th">{{ t('report.column.relation') }}</th>
                                        <th class="th text-right">{{ t('certificate.plural') }}</th>
                                        <th class="th">{{ t('common.earliest_expiry') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in report.beneficiaries" :key="item.id" class="border-b border-slate-100">
                                        <td class="td">
                                            <button type="button" class="font-medium text-slate-800 hover:text-brand-700 hover:underline text-left" @click="openBeneficiary(item.id)">{{ format.person(item.first_name, item.last_name) }}</button>
                                            <span v-if="item.archived_at" class="badge bg-slate-200 text-slate-600 ml-2">{{ t('common.archived') }}</span>
                                        </td>
                                        <td class="td text-slate-600">{{ [item.email, item.phone].filter(Boolean).join(' · ') || '—' }}</td>
                                        <td class="td text-xs text-slate-600">{{ item.linked_directly ? t('report.relation.direct') : t('report.relation.certificate') }}</td>
                                        <td class="td text-right">{{ item.certificate_count }}</td>
                                        <td class="td whitespace-nowrap">
                                            <template v-if="item.next_expiry">
                                                {{ format.date(item.next_expiry) }}
                                                <div class="text-xs text-slate-500">{{ format.days(item.days_left) }}</div>
                                            </template>
                                            <span v-else class="text-slate-400">—</span>
                                        </td>
                                    </tr>
                                    <tr v-if="report.beneficiaries.length === 0">
                                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ t('report.empty.beneficiaries') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </ReportSection>

                    <ReportSection :title="t('report.section.schedule')" :description="t('report.schedule.description')">
                        <ExpirySchedule :buckets="report.schedule" :show-payer="false" />
                    </ReportSection>

                    <ReportSection :title="t('report.section.certificates')" :count="report.certificates.length"
                                   :description="t('report.section.certificates_hint', { days: report.thresholds.warning })">
                        <CertificateReportTable :certificates="report.certificates" show-beneficiary show-cost />
                    </ReportSection>

                    <ReportSection v-if="report.archive_access" :title="t('report.section.history')" :count="report.history.length" :description="t('report.section.history_hint')">
                        <CertificateHistoryTable :certificates="report.history" show-beneficiary />
                    </ReportSection>

                    <ReportSection :title="t('report.section.tasks')" :count="report.tasks.length" :description="t('report.section.tasks_hint')">
                        <TaskReportTable :tasks="report.tasks" />
                    </ReportSection>

                    <ReportSection :title="t('report.section.invitations')" :count="report.invitations.length">
                        <InvitationReportTable :invitations="report.invitations" />
                    </ReportSection>

                    <ReportSection :title="t('report.section.timeline')" :count="report.timeline.length" :description="t('report.section.timeline_hint')">
                        <div class="p-5">
                            <EventTimeline :events="report.timeline" show-certificate filterable group-by-day />
                        </div>
                    </ReportSection>
                </template>
            </section>
        `,
    };
})(window.CertiSub);
