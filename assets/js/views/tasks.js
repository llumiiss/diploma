/*
 * Lista ToDo odnowień (F12): zadania z priorytetami, statusy, przydział, zaproszenia i odnowienie.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    const TASK_BADGES = {
        todo: 'bg-slate-100 text-slate-700 border border-slate-200',
        in_progress: 'bg-blue-50 text-blue-700 border border-blue-200',
        done: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
        abandoned: 'bg-slate-200 text-slate-600 border border-slate-300',
    };

    const INVITATION_BADGES = {
        queued: 'bg-slate-100 text-slate-700',
        sent: 'bg-blue-100 text-blue-800',
        failed: 'bg-red-100 text-red-800',
        responded: 'bg-emerald-100 text-emerald-800',
        closed: 'bg-slate-200 text-slate-600',
    };

    CertiSub.taskBadge = (status) => TASK_BADGES[status] || TASK_BADGES.todo;
    CertiSub.invitationBadge = (status) => INVITATION_BADGES[status] || INVITATION_BADGES.queued;

    /**
     * Zmiana statusu zadania; porzucenie i zakończenie pytają o notatkę.
     */
    CertiSub.changeTaskStatus = function (task, status) {
        return new Promise((resolve) => {
            const needsNote = status === 'abandoned' || status === 'done';
            const run = async (note) => {
                try {
                    const result = await api.post(endpoints.tasks, { action: 'status', id: task.id, status, note: note || null });
                    CertiSub.notify(t('task.status_changed', { status: t('task.status.' + status) }));
                    CertiSub.data.refresh('tasks', 'taskStats', 'certificates', 'summary');
                    resolve(result.task);
                } catch (error) {
                    if (needsNote) {
                        throw error;
                    }
                    CertiSub.notifyError(error);
                    resolve(null);
                }
            };
            if (needsNote) {
                CertiSub.openModal('TaskNoteDialog', { task, status, submit: run }, (updated) => resolve(updated));
            } else {
                run(null);
            }
        });
    };

    CertiSub.components.TasksView = {
        data() {
            return {
                store: CertiSub.store,
                status: 'open',
                assignee: '',
                priority: '',
                search: '',
                owners: [],
                scanning: false,
            };
        },
        computed: {
            state() {
                return this.store.tasks;
            },
            stats() {
                return this.store.taskStats;
            },
            filtered() {
                const query = this.search.trim().toLowerCase();
                return this.state.items.filter((task) => {
                    if (this.priority && task.priority !== this.priority) {
                        return false;
                    }
                    if (!query) {
                        return true;
                    }
                    return [task.certificate_name, task.serial_number, task.beneficiary_name, task.payer_name, task.assignee_name]
                        .some((value) => value && String(value).toLowerCase().indexOf(query) !== -1);
                });
            },
        },
        watch: {
            status() {
                this.reload();
            },
            assignee() {
                this.reload();
            },
        },
        async mounted() {
            CertiSub.data.taskFilters = { status: this.status, assignee: this.assignee };
            this.reload();
            CertiSub.data.taskStats();
            if (CertiSub.can('tasks.assign')) {
                try {
                    const options = await CertiSub.data.certificateOptions();
                    this.owners = options.owners;
                } catch (error) {
                    this.owners = [];
                }
            }
        },
        methods: {
            reload() {
                CertiSub.data.taskFilters = { status: this.status, assignee: this.assignee };
                CertiSub.data.tasks(true);
            },
            open(task) {
                CertiSub.openDrawer('task', task.id);
            },
            async scan() {
                this.scanning = true;
                try {
                    const result = await api.post(endpoints.tasks, { action: 'scan' });
                    CertiSub.notify(t('task.scan_result', result.scan));
                    CertiSub.data.refresh('tasks', 'taskStats', 'summary');
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.scanning = false;
                }
            },
            start(task) {
                CertiSub.changeTaskStatus(task, 'in_progress');
            },
            invite(task) {
                CertiSub.openModal('InvitationComposer', { certificateId: task.certificate_id }, () => CertiSub.data.refresh('tasks', 'taskStats'));
            },
        },
        template: `
            <section>
                <PageHeader :title="t('task.title')" :description="t('task.description', { days: CertiSub.boot.thresholds.warning })">
                    <button v-if="can('scanner.run')" type="button" class="btn-secondary" :disabled="scanning" @click="scan">
                        🔎 {{ scanning ? t('task.scanning') : t('task.run_scanner') }}
                    </button>
                </PageHeader>

                <div v-if="stats" class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                    <div class="card px-4 py-3">
                        <p class="text-xs text-slate-400 uppercase font-semibold">{{ t('task.stat.open') }}</p>
                        <p class="text-2xl font-bold text-brand-700">{{ stats.open }}</p>
                    </div>
                    <div class="card px-4 py-3 border-red-200">
                        <p class="text-xs text-red-400 uppercase font-semibold">{{ t('task.stat.overdue') }}</p>
                        <p class="text-2xl font-bold text-red-600">{{ stats.overdue }}</p>
                    </div>
                    <div class="card px-4 py-3">
                        <p class="text-xs text-slate-400 uppercase font-semibold">{{ t('task.stat.mine') }}</p>
                        <p class="text-2xl font-bold text-slate-800">{{ stats.mine_open }}</p>
                    </div>
                    <div v-if="can('tasks.assign')" class="card px-4 py-3 border-amber-200">
                        <p class="text-xs text-amber-500 uppercase font-semibold">{{ t('task.stat.unassigned') }}</p>
                        <p class="text-2xl font-bold text-amber-600">{{ stats.unassigned_open }}</p>
                    </div>
                    <div class="card px-4 py-3 border-emerald-200">
                        <p class="text-xs text-emerald-500 uppercase font-semibold">{{ t('task.stat.closed_30') }}</p>
                        <p class="text-2xl font-bold text-emerald-700">{{ stats.closed_last_30_days.done + stats.closed_last_30_days.abandoned }}</p>
                    </div>
                </div>

                <div class="card p-4 mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <input v-model="search" type="search" class="input" :placeholder="t('task.search_placeholder')" :aria-label="t('common.search')">
                    <select v-model="status" class="input" :aria-label="t('field.status')">
                        <option value="open">{{ t('task.filter.open') }}</option>
                        <option value="todo">{{ t('task.status.todo') }}</option>
                        <option value="in_progress">{{ t('task.status.in_progress') }}</option>
                        <option value="closed">{{ t('task.filter.closed') }}</option>
                        <option value="all">{{ t('task.filter.all') }}</option>
                    </select>
                    <select v-model="assignee" class="input" :aria-label="t('task.assignee')">
                        <option value="">{{ t('task.filter.all_assignees') }}</option>
                        <option value="me">{{ t('task.filter.mine') }}</option>
                        <option v-if="can('tasks.assign')" value="unassigned">{{ t('task.filter.unassigned') }}</option>
                        <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                    </select>
                    <select v-model="priority" class="input" :aria-label="t('dash.priority')">
                        <option value="">{{ t('dash.filter.all_priorities') }}</option>
                        <option value="expired">{{ t('priority.label.expired') }}</option>
                        <option value="critical">{{ t('priority.label.critical') }}</option>
                        <option value="warning">{{ t('priority.label.warning') }}</option>
                    </select>
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[980px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('dash.priority') }}</th>
                                <th class="th">{{ t('certificate.singular') }}</th>
                                <th class="th">{{ t('task.parties') }}</th>
                                <th class="th">{{ t('task.due') }}</th>
                                <th class="th">{{ t('task.assignee') }}</th>
                                <th class="th">{{ t('field.status') }}</th>
                                <th class="th">{{ t('invitation.singular') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="task in filtered" :key="task.id" :class="[task.is_open ? badge.row(task.priority) : '', 'border-b border-slate-100 hover:bg-slate-50 cursor-pointer']" @click="open(task)">
                                <td class="td"><span :class="['badge', badge.priority(task.priority)]">{{ labels.priority(task.priority) }}</span></td>
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ task.certificate_name }}</div>
                                    <div v-if="task.serial_number" class="text-xs text-slate-500 font-mono">{{ task.serial_number }}</div>
                                </td>
                                <td class="td">
                                    <div>{{ task.beneficiary_name || '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ task.payer_name }}</div>
                                </td>
                                <td class="td whitespace-nowrap">
                                    <div>{{ format.date(task.due_date) }}</div>
                                    <div :class="['text-xs', task.is_overdue ? 'text-red-600 font-semibold' : 'text-slate-500']">{{ format.days(task.days_left) }}</div>
                                </td>
                                <td class="td">{{ task.assignee_name || t('task.unassigned') }}</td>
                                <td class="td"><span :class="['badge', CertiSub.taskBadge(task.status)]">{{ t('task.status.' + task.status) }}</span></td>
                                <td class="td">
                                    <span v-if="task.last_invitation_status" :class="['badge', CertiSub.invitationBadge(task.last_invitation_status)]">{{ t('invitation.status.' + task.last_invitation_status) }}</span>
                                    <span v-else class="text-slate-400 text-xs">{{ t('invitation.none') }}</span>
                                </td>
                                <td class="td text-right whitespace-nowrap" @click.stop>
                                    <button v-if="task.status === 'todo' && can('tasks.update')" type="button" class="btn-ghost" @click="start(task)">▶ {{ t('task.action.start') }}</button>
                                    <button v-if="task.is_open && can('invitations.send')" type="button" class="btn-ghost" @click="invite(task)">✉️ {{ t('invitation.send') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="8" :loading="state.loading && !state.loaded" :error="state.error"
                                        :empty="state.loaded && filtered.length === 0" :empty-text="t('task.empty')" />
                        </tbody>
                    </table>
                </div>
            </section>
        `,
    };

    CertiSub.components.TaskDrawer = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { task: null, loading: true, error: '', owners: [], assigning: false };
        },
        computed: {
            rows() {
                const task = this.task;
                return [
                    { label: t('dash.priority'), value: CertiSub.labels.priority(task.priority) },
                    { label: t('field.status'), value: t('task.status.' + task.status) },
                    { label: t('task.due'), value: CertiSub.format.date(task.due_date) + ' (' + CertiSub.format.days(task.days_left) + ')' },
                    { label: t('task.assignee'), value: task.assignee_name || t('task.unassigned') },
                    { label: t('field.user_id'), value: task.owner_name },
                    { label: t('common.created_at'), value: CertiSub.format.dateTime(task.created_at) },
                    { label: t('task.closed_at'), value: task.closed_at ? CertiSub.format.dateTime(task.closed_at) : null },
                    { label: t('task.resolution_note'), value: task.resolution_note, wide: true },
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
                    const data = await api.get(endpoints.tasks, { id: this.id });
                    this.task = data.task;
                    if (CertiSub.can('tasks.assign') && this.owners.length === 0) {
                        const options = await CertiSub.data.certificateOptions();
                        this.owners = options.owners;
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
            async setStatus(status) {
                const updated = await CertiSub.changeTaskStatus(this.task, status);
                if (updated) {
                    this.task = updated;
                }
            },
            async assign(event) {
                const value = event.target.value;
                this.assigning = true;
                try {
                    const result = await api.post(endpoints.tasks, { action: 'assign', id: this.id, user_id: value ? Number(value) : null });
                    this.task = result.task;
                    CertiSub.notify(t('task.assigned'));
                    CertiSub.data.refresh('tasks', 'taskStats');
                } catch (error) {
                    CertiSub.notifyError(error);
                    event.target.value = this.task.assigned_user_id ? String(this.task.assigned_user_id) : '';
                } finally {
                    this.assigning = false;
                }
            },
            invite() {
                CertiSub.openModal('InvitationComposer', { certificateId: this.task.certificate_id }, () => this.load());
            },
            renew() {
                CertiSub.openModal('RenewForm', { task: this.task }, (result) => {
                    this.load();
                    if (result && result.certificate) {
                        CertiSub.openDrawer('certificate', result.certificate.id);
                    }
                });
            },
            openCertificate() {
                CertiSub.openDrawer('certificate', this.task.certificate_id);
            },
            openInvitation(invitation) {
                CertiSub.openDrawer('invitation', invitation.id);
            },
        },
        template: `
            <DrawerShell :title="task ? task.certificate_name : ''" :subtitle="t('task.singular')" :loading="loading" :error="error" @close="close">
                <template #actions>
                    <template v-if="task && task.is_open">
                        <button v-if="task.status === 'todo' && can('tasks.update')" type="button" class="btn-secondary" @click="setStatus('in_progress')">▶ {{ t('task.action.start') }}</button>
                        <button v-if="can('invitations.send')" type="button" class="btn-secondary" @click="invite">✉️ {{ t('invitation.send') }}</button>
                        <button v-if="can('tasks.update') && can('certificates.create') && !task.certificate_archived_at" type="button" class="btn-primary" @click="renew">🔁 {{ t('task.action.renew') }}</button>
                        <button v-if="can('tasks.update')" type="button" class="btn-secondary" @click="setStatus('done')">✔ {{ t('task.action.done') }}</button>
                        <button v-if="task.status === 'in_progress' && can('tasks.update')" type="button" class="btn-secondary" @click="setStatus('todo')">↩ {{ t('task.action.back_to_todo') }}</button>
                        <button v-if="can('tasks.update')" type="button" class="btn-secondary text-red-700" @click="setStatus('abandoned')">✖ {{ t('task.action.abandon') }}</button>
                    </template>
                    <button v-else-if="task && can('tasks.assign') && !task.certificate_archived_at" type="button" class="btn-secondary" @click="setStatus('todo')">↺ {{ t('task.action.reopen') }}</button>
                </template>

                <template v-if="task">
                    <div v-if="task.is_overdue" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm font-medium">{{ t('task.overdue_notice', { days: Math.abs(task.days_left) }) }}</div>
                    <DetailGrid :rows="rows" />

                    <label v-if="can('tasks.assign') && task.is_open" class="block mt-4">
                        <span class="block text-sm font-medium text-slate-700 mb-1">{{ t('task.assign') }}</span>
                        <select class="input" :disabled="assigning" :value="task.assigned_user_id ? String(task.assigned_user_id) : ''" @change="assign">
                            <option value="">{{ t('task.unassigned') }}</option>
                            <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }} ({{ labels.role(owner.role) }})</option>
                        </select>
                    </label>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('certificate.links') }}</h4>
                    <button type="button" class="card p-4 w-full text-left hover:border-brand-300" @click="openCertificate">
                        <div class="font-medium text-slate-800">{{ task.certificate_name }}</div>
                        <div class="text-xs text-slate-500">{{ labels.type(task.certificate_type) }} · {{ format.date(task.expiry_date) }}</div>
                        <div class="text-xs text-slate-500">{{ task.beneficiary_name || t('certificate.no_beneficiary') }} · {{ task.payer_name }}</div>
                    </button>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('invitation.plural') }}</h4>
                    <div class="card divide-y divide-slate-100">
                        <button v-for="invitation in task.invitations" :key="invitation.id" type="button" class="w-full text-left px-4 py-3 hover:bg-slate-50 flex items-center justify-between gap-3" @click="openInvitation(invitation)">
                            <span>
                                <span class="block text-sm font-medium text-slate-800">{{ invitation.subject }}</span>
                                <span class="block text-xs text-slate-500">{{ invitation.recipient_email }} · {{ format.dateTime(invitation.sent_at || invitation.created_at) }}</span>
                            </span>
                            <span :class="['badge', CertiSub.invitationBadge(invitation.status)]">{{ t('invitation.status.' + invitation.status) }}</span>
                        </button>
                        <p v-if="task.invitations.length === 0" class="px-4 py-6 text-sm text-slate-400 text-center">{{ t('invitation.none_for_task') }}</p>
                    </div>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('timeline.title') }}</h4>
                    <EventTimeline :events="task.events" />
                </template>
            </DrawerShell>
        `,
    };

    CertiSub.components.TaskNoteDialog = {
        props: {
            task: { type: Object, required: true },
            status: { type: String, required: true },
            submit: { type: Function, required: true },
        },
        emits: ['close', 'saved'],
        data() {
            return { note: '', saving: false, error: '' };
        },
        computed: {
            required() {
                return this.status === 'abandoned';
            },
        },
        methods: {
            async confirm() {
                this.saving = true;
                this.error = '';
                try {
                    await this.submit(this.note.trim());
                    this.$emit('close');
                } catch (error) {
                    this.error = (error.errors && error.errors.note) || error.message;
                } finally {
                    this.saving = false;
                }
            },
        },
        template: `
            <ModalShell :title="t('task.dialog.' + status)" :subtitle="task.certificate_name" size="sm" :busy="saving" @close="$emit('close')">
                <form id="task-note-form" @submit.prevent="confirm">
                    <FormField :label="required ? t('task.note_reason') : t('task.note_optional')" :error="error" :required="required">
                        <textarea v-model="note" rows="4" maxlength="2000" class="input" :placeholder="t('task.note_placeholder.' + status)"></textarea>
                    </FormField>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="task-note-form" :class="status === 'abandoned' ? 'btn-danger' : 'btn-primary'" :disabled="saving">{{ saving ? t('common.saving') : t('task.action.' + (status === 'abandoned' ? 'abandon' : 'done')) }}</button>
                </template>
            </ModalShell>
        `,
    };

    CertiSub.components.RenewForm = {
        props: {
            task: { type: Object, required: true },
        },
        emits: ['close', 'saved'],
        data() {
            const nextDay = (value) => {
                const parts = String(value).split('-').map(Number);
                const date = new Date(parts[0], parts[1] - 1, parts[2] + 1);
                const pad = (number) => String(number).padStart(2, '0');
                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            };
            return {
                form: {
                    valid_from: nextDay(this.task.expiry_date),
                    expiry_date: '',
                    serial_number: '',
                    issuer: this.task.issuer || '',
                    payment_status: 'paid',
                    last_payment_date: '',
                    note: '',
                },
                saving: false,
                errors: {},
                message: '',
            };
        },
        methods: {
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.tasks, { action: 'renew', id: this.task.id, data: this.form });
                    CertiSub.notify(t('task.renewed'));
                    CertiSub.data.refresh('tasks', 'taskStats', 'certificates', 'summary', 'beneficiaries', 'payers');
                    this.$emit('saved', result);
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
            <ModalShell :title="t('task.renew_title')" :subtitle="t('task.renew_description', { name: task.certificate_name, date: format.date(task.expiry_date) })"
                        size="lg" :busy="saving" @close="$emit('close')">
                <form id="renew-form" class="grid md:grid-cols-2 gap-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="md:col-span-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <FormField :label="t('field.valid_from')" :error="errors.valid_from">
                        <input v-model="form.valid_from" type="date" class="input">
                    </FormField>
                    <FormField :label="t('task.new_expiry')" :error="errors.expiry_date" required>
                        <input v-model="form.expiry_date" type="date" :class="['input', errors.expiry_date ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('task.new_serial')" :error="errors.serial_number">
                        <input v-model="form.serial_number" type="text" maxlength="128" class="input font-mono">
                    </FormField>
                    <FormField :label="t('field.issuer')" :error="errors.issuer">
                        <input v-model="form.issuer" type="text" maxlength="255" class="input">
                    </FormField>
                    <FormField :label="t('field.payment_status')" :error="errors.payment_status">
                        <select v-model="form.payment_status" class="input">
                            <option v-for="status in labels.paymentStatuses" :key="status" :value="status">{{ labels.payment(status) }}</option>
                        </select>
                    </FormField>
                    <FormField :label="t('field.last_payment_date')" :error="errors.last_payment_date">
                        <input v-model="form.last_payment_date" type="date" class="input">
                    </FormField>
                    <FormField class="md:col-span-2" :label="t('task.note_optional')">
                        <textarea v-model="form.note" rows="2" maxlength="2000" class="input"></textarea>
                    </FormField>
                    <p class="md:col-span-2 text-xs text-slate-500">{{ t('task.renew_hint') }}</p>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="renew-form" class="btn-primary" :disabled="saving">{{ saving ? t('common.saving') : t('task.action.renew') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
