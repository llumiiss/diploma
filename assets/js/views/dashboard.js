/*
 * Pulpit panelu firmowego: wskaźniki, płatności wymagające działania i priorytetowe odnowienia.
 */
(function (CertiSub) {
    'use strict';

    const { t } = CertiSub;

    /**
     * Statystyki realizacji zadań wg statusów (F18) — słupki bez biblioteki wykresów.
     */
    CertiSub.components.TaskStatsPanel = {
        props: {
            stats: { type: Object, required: true },
        },
        computed: {
            total() {
                const s = this.stats.by_status;
                return s.todo + s.in_progress + s.done + s.abandoned;
            },
            bars() {
                const colors = { todo: 'bg-slate-400', in_progress: 'bg-blue-500', done: 'bg-emerald-500', abandoned: 'bg-slate-300' };
                return ['todo', 'in_progress', 'done', 'abandoned'].map((status) => ({
                    status,
                    label: t('task.status.' + status),
                    count: this.stats.by_status[status],
                    percent: this.total > 0 ? Math.round(this.stats.by_status[status] / this.total * 100) : 0,
                    color: colors[status],
                }));
            },
            priorities() {
                return ['expired', 'critical', 'warning'].map((priority) => ({ priority, count: this.stats.open_by_priority[priority] || 0 }));
            },
        },
        methods: {
            openTasks() {
                window.location.hash = '#/todo';
            },
        },
        template: `
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">📈 {{ t('task.stats_title') }}</h3>
                        <p class="text-xs text-slate-400 mt-1">{{ t('task.stats_description', { total: total }) }}</p>
                    </div>
                    <button type="button" class="btn-ghost" @click="openTasks">{{ t('task.open_list') }} →</button>
                </div>
                <div class="grid lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-3">
                        <div v-for="bar in bars" :key="bar.status">
                            <div class="flex justify-between text-xs mb-1">
                                <span class="font-medium text-slate-700">{{ bar.label }}</span>
                                <span class="text-slate-500">{{ bar.count }} · {{ bar.percent }}%</span>
                            </div>
                            <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden" role="img" :aria-label="bar.label + ': ' + bar.count">
                                <div :class="['h-full rounded-full', bar.color]" :style="{ width: bar.percent + '%' }"></div>
                            </div>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm content-start">
                        <div class="bg-red-50 rounded-lg px-3 py-2">
                            <dt class="text-xs text-red-500">{{ t('task.stat.overdue') }}</dt>
                            <dd class="text-xl font-bold text-red-700">{{ stats.overdue }}</dd>
                        </div>
                        <div class="bg-slate-50 rounded-lg px-3 py-2">
                            <dt class="text-xs text-slate-500">{{ t('task.stat.mine') }}</dt>
                            <dd class="text-xl font-bold text-slate-800">{{ stats.mine_open }}</dd>
                        </div>
                        <div class="bg-emerald-50 rounded-lg px-3 py-2">
                            <dt class="text-xs text-emerald-600">{{ t('task.stat.completion_rate') }}</dt>
                            <dd class="text-xl font-bold text-emerald-700">{{ stats.completion_rate === null ? '—' : stats.completion_rate + '%' }}</dd>
                        </div>
                        <div class="bg-blue-50 rounded-lg px-3 py-2">
                            <dt class="text-xs text-blue-600">{{ t('task.stat.avg_days') }}</dt>
                            <dd class="text-xl font-bold text-blue-700">{{ stats.average_days_to_done === null ? '—' : stats.average_days_to_done }}</dd>
                        </div>
                        <div v-for="item in priorities" :key="item.priority" class="col-span-2 flex justify-between text-xs">
                            <span :class="['badge', badge.priority(item.priority)]">{{ labels.priority(item.priority) }}</span>
                            <span class="text-slate-600">{{ t('task.open_count', { count: item.count }) }}</span>
                        </div>
                    </dl>
                </div>
                <div v-if="stats.by_assignee.length" class="mt-5 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-slate-500 border-b border-slate-100">
                                <th class="text-left py-1.5 pr-3">{{ t('task.assignee') }}</th>
                                <th v-for="bar in bars" :key="'h' + bar.status" class="text-right py-1.5 px-2">{{ bar.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in stats.by_assignee" :key="row.user_id || 'none'" class="border-b border-slate-50">
                                <td class="py-1.5 pr-3">{{ row.name || t('task.unassigned') }}</td>
                                <td v-for="bar in bars" :key="row.user_id + bar.status" class="text-right py-1.5 px-2">{{ row.counts[bar.status] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `,
    };

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
            if (CertiSub.can('tasks.view')) {
                CertiSub.data.taskStats();
            }
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

                <TaskStatsPanel v-if="store.taskStats" :stats="store.taskStats" class="mb-8" />

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
