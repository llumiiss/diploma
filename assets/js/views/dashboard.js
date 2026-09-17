/*
 * Pulpit panelu firmowego: wskaźniki, płatności wymagające działania i priorytetowe odnowienia.
 */
(function (CertiSub) {
    'use strict';

    CertiSub.components.DashboardView = {
        data() {
            return { store: CertiSub.store };
        },
        computed: {
            summary() {
                return this.store.summary;
            },
            certificates() {
                return this.store.certificates.items;
            },
            totalCertificates() {
                if (!this.summary) {
                    return 0;
                }
                const stats = this.summary.stats;
                return stats.pending + stats.active + stats.renewal_in_progress + stats.expired;
            },
            priorityList() {
                return this.certificates
                    .filter((item) => ['expired', 'critical', 'warning'].indexOf(item.priority) !== -1)
                    .slice()
                    .sort((a, b) => a.days_left - b.days_left)
                    .slice(0, 8);
            },
            paymentsDue() {
                return this.certificates.filter((item) => item.display_payment_status === 'due_soon' || item.display_payment_status === 'overdue');
            },
        },
        mounted() {
            CertiSub.data.summary();
            CertiSub.data.certificates();
        },
        methods: {
            open(item) {
                CertiSub.openDrawer('certificate', item.id);
            },
            addCertificate() {
                CertiSub.openModal('CertificateForm', {});
            },
        },
        template: `
            <section>
                <PageHeader :title="t('dashboard.title')" :description="summary ? t('dashboard.thresholds', { warning: summary.renewal_summary.warning_days, critical: summary.renewal_summary.critical_days }) : t('dashboard.description')">
                    <button v-if="can('certificates.create')" type="button" class="btn-primary" @click="addCertificate">+ {{ t('certificate.add') }}</button>
                </PageHeader>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                    <div class="card p-5">
                        <p class="text-xs font-semibold text-slate-400 uppercase">{{ t('dashboard.kpi.certificates') }}</p>
                        <p class="text-3xl font-bold text-brand-700 mt-1">{{ summary ? totalCertificates : '…' }}</p>
                        <p v-if="summary" class="text-xs text-slate-400 mt-2">{{ t('dash.active_expired', { active: summary.stats.active, expired: summary.stats.expired }) }}</p>
                    </div>
                    <div class="card p-5 border-red-200">
                        <p class="text-xs font-semibold text-red-400 uppercase">{{ t('dashboard.kpi.renewals', { days: summary ? summary.renewal_summary.warning_days : 30 }) }}</p>
                        <p class="text-3xl font-bold text-red-600 mt-1">{{ summary ? summary.renewal_summary.expiring_warning : '…' }}</p>
                        <p v-if="summary" class="text-xs text-slate-400 mt-2">{{ t('corporate.expiring_critical', { count: summary.renewal_summary.expiring_critical, days: summary.renewal_summary.critical_days }) }}</p>
                    </div>
                    <div class="card p-5 border-amber-200">
                        <p class="text-xs font-semibold text-amber-500 uppercase">{{ t('dash.payments_action') }}</p>
                        <p class="text-3xl font-bold text-amber-600 mt-1">{{ summary ? summary.payment_summary.due_soon + summary.payment_summary.overdue : '…' }}</p>
                        <p v-if="summary" class="text-xs text-slate-400 mt-2">{{ t('dash.overdue_count', { count: summary.payment_summary.overdue }) }}</p>
                    </div>
                    <div class="card p-5 border-emerald-200">
                        <p class="text-xs font-semibold text-emerald-500 uppercase">{{ t('corporate.commitment') }}</p>
                        <p class="text-2xl font-bold text-emerald-700 mt-1">{{ summary ? format.money(summary.renewal_summary.annual_commitment) : '…' }}</p>
                        <p class="text-xs text-slate-400 mt-2">{{ t('dash.annual_total') }}</p>
                    </div>
                </div>

                <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
                    <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-amber-700">{{ summary.stats.pending }}</p>
                        <p class="text-xs text-amber-600 font-medium">{{ t('status.pending') }}</p>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-blue-700">{{ summary.stats.renewal_in_progress }}</p>
                        <p class="text-xs text-blue-600 font-medium">{{ t('status.renewal_in_progress') }}</p>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-emerald-700">{{ summary.stats.active }}</p>
                        <p class="text-xs text-emerald-600 font-medium">{{ t('status.active') }}</p>
                    </div>
                    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-red-700">{{ summary.stats.expired }}</p>
                        <p class="text-xs text-red-600 font-medium">{{ t('status.expired') }}</p>
                    </div>
                </div>

                <div class="grid xl:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">🚨 {{ t('dash.priority_renewals') }}</h3>
                        <div class="card overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 border-b border-slate-200">
                                    <tr>
                                        <th class="th">{{ t('certificate.singular') }}</th>
                                        <th class="th">{{ t('field.expiry_date') }}</th>
                                        <th class="th">{{ t('dash.priority') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in priorityList" :key="'prio-' + item.id" :class="badge.row(item.priority)"
                                        class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                        <td class="td">
                                            <div class="font-medium text-slate-800">{{ item.name }}</div>
                                            <div class="text-xs text-slate-500">{{ item.beneficiary_name || item.company_name }}</div>
                                        </td>
                                        <td class="td whitespace-nowrap">
                                            <div>{{ format.date(item.expiry_date) }}</div>
                                            <div class="text-xs text-slate-500">{{ format.days(item.days_left) }}</div>
                                        </td>
                                        <td class="td"><span :class="['badge', badge.priority(item.priority)]">{{ item.priority_label }}</span></td>
                                    </tr>
                                    <TableState :colspan="3" :loading="store.certificates.loading && !store.certificates.loaded"
                                                :error="store.certificates.error" :empty="priorityList.length === 0" :empty-text="t('dashboard.no_priorities')" />
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">💳 {{ t('dash.payments_requiring') }}</h3>
                        <div class="card overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 border-b border-slate-200">
                                    <tr>
                                        <th class="th">{{ t('certificate.singular') }}</th>
                                        <th class="th text-right">{{ t('dash.amount') }}</th>
                                        <th class="th">{{ t('field.payment_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in paymentsDue" :key="'pay-' + item.id" :class="badge.row(item.priority)"
                                        class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                        <td class="td">
                                            <div class="font-medium text-slate-800">{{ item.name }}</div>
                                            <div class="text-xs text-slate-500">{{ item.company_name }}</div>
                                        </td>
                                        <td class="td text-right font-semibold whitespace-nowrap">{{ format.money(item.annual_cost, item.currency) }}</td>
                                        <td class="td"><span :class="['badge', badge.payment(item.display_payment_status)]">{{ item.payment_label }}</span></td>
                                    </tr>
                                    <TableState :colspan="3" :loading="store.certificates.loading && !store.certificates.loaded"
                                                :error="store.certificates.error" :empty="paymentsDue.length === 0" :empty-text="t('dash.no_payments')" />
                                </tbody>
                            </table>
                            <div v-if="summary" class="px-4 py-3 bg-amber-50 border-t border-amber-100 flex justify-between text-sm">
                                <span class="text-amber-800 font-medium">{{ t('dash.total_due') }}</span>
                                <span class="font-bold text-amber-900">{{ format.money(summary.payment_summary.total_due_amount) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        `,
    };
})(window.CertiSub);
