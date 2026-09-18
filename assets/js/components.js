/*
 * Wspólne elementy interfejsu: okno formularza, panel szczegółów, pole formularza,
 * stany tabel, powiadomienia, okno potwierdzenia i oś czasu zdarzeń.
 */
(function (CertiSub) {
    'use strict';

    const { t, format, labels } = CertiSub;

    CertiSub.components.ModalShell = {
        props: {
            title: { type: String, required: true },
            subtitle: { type: String, default: '' },
            size: { type: String, default: 'md' },
            busy: { type: Boolean, default: false },
        },
        emits: ['close'],
        computed: {
            widthClass() {
                return { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }[this.size] || 'max-w-lg';
            },
        },
        methods: {
            requestClose() {
                if (!this.busy) {
                    this.$emit('close');
                }
            },
        },
        template: `
            <div class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 overflow-y-auto" role="dialog" aria-modal="true" @keydown.esc="requestClose">
                <div class="fixed inset-0 bg-slate-900/50" @click="requestClose"></div>
                <div :class="['relative bg-white rounded-2xl border border-slate-200 shadow-xl w-full my-8', widthClass]">
                    <div class="px-6 pt-6 pb-4 border-b border-slate-100 flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">{{ title }}</h3>
                            <p v-if="subtitle" class="text-sm text-slate-500 mt-1">{{ subtitle }}</p>
                        </div>
                        <button type="button" class="text-slate-400 hover:text-slate-700 text-xl leading-none" :aria-label="t('common.close')" @click="requestClose">×</button>
                    </div>
                    <div class="px-6 py-5">
                        <slot></slot>
                    </div>
                    <div v-if="$slots.footer" class="px-6 py-4 bg-slate-50 border-t border-slate-100 rounded-b-2xl flex flex-wrap justify-end gap-3">
                        <slot name="footer"></slot>
                    </div>
                </div>
            </div>
        `,
    };

    CertiSub.components.DrawerShell = {
        props: {
            title: { type: String, default: '' },
            subtitle: { type: String, default: '' },
            loading: { type: Boolean, default: false },
            error: { type: String, default: '' },
        },
        emits: ['close'],
        template: `
            <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" @keydown.esc="$emit('close')">
                <div class="fixed inset-0 bg-slate-900/30" @click="$emit('close')"></div>
                <aside class="relative w-full max-w-2xl h-full bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-slate-200 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-xl font-bold text-slate-900 break-words">{{ loading ? t('common.loading') : title }}</h3>
                            <p v-if="subtitle && !loading" class="text-sm text-slate-500 mt-1">{{ subtitle }}</p>
                        </div>
                        <button type="button" class="text-slate-400 hover:text-slate-700 text-2xl leading-none" :aria-label="t('common.close')" @click="$emit('close')">×</button>
                    </div>
                    <div v-if="$slots.actions && !loading && !error" class="px-6 py-3 border-b border-slate-100 bg-slate-50 flex flex-wrap gap-2">
                        <slot name="actions"></slot>
                    </div>
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div v-if="loading" class="space-y-3" role="status" :aria-label="t('common.loading')">
                            <div class="skeleton h-4 w-2/3"></div>
                            <div class="skeleton h-4 w-1/2"></div>
                            <div class="skeleton h-4 w-3/4"></div>
                            <div class="skeleton h-24 w-full mt-6"></div>
                        </div>
                        <div v-else-if="error" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ error }}</div>
                        <slot v-else></slot>
                    </div>
                </aside>
            </div>
        `,
    };

    CertiSub.components.FormField = {
        props: {
            label: { type: String, required: true },
            error: { type: String, default: '' },
            required: { type: Boolean, default: false },
            hint: { type: String, default: '' },
        },
        template: `
            <label class="block">
                <span class="block text-sm font-medium text-slate-700 mb-1">
                    {{ label }}<span v-if="required" class="text-red-500" aria-hidden="true"> *</span>
                </span>
                <slot></slot>
                <span v-if="error" class="block text-xs text-red-600 mt-1">{{ error }}</span>
                <span v-else-if="hint" class="block text-xs text-slate-400 mt-1">{{ hint }}</span>
            </label>
        `,
    };

    /**
     * Wiersze szkieletu tabeli — pokazują układ, zanim przyjdą dane (§2.5).
     */
    CertiSub.components.SkeletonRows = {
        props: {
            rows: { type: Number, default: 5 },
            cols: { type: Number, required: true },
        },
        template: `
            <tr v-for="row in rows" :key="row" class="border-b border-slate-100" aria-hidden="true">
                <td v-for="col in cols" :key="col" class="td">
                    <div :class="['skeleton h-4', col === 1 ? 'w-3/4' : 'w-1/2']"></div>
                </td>
            </tr>
        `,
    };

    /**
     * Szkielet pól formularza — okna edycji dociągają dane słowników przed pokazaniem pól.
     */
    CertiSub.components.FormSkeleton = {
        props: {
            rows: { type: Number, default: 4 },
        },
        template: `
            <div class="grid md:grid-cols-2 gap-4" role="status" :aria-label="t('common.loading')">
                <div v-for="row in rows" :key="row" class="space-y-2">
                    <div class="skeleton h-3 w-1/3"></div>
                    <div class="skeleton h-9 w-full rounded-lg"></div>
                </div>
            </div>
        `,
    };

    CertiSub.components.TableState = {
        props: {
            loading: { type: Boolean, default: false },
            error: { type: String, default: '' },
            empty: { type: Boolean, default: false },
            colspan: { type: Number, required: true },
            emptyText: { type: String, default: '' },
            skeletonRows: { type: Number, default: 5 },
        },
        template: `
            <SkeletonRows v-if="loading" :rows="skeletonRows" :cols="colspan" />
            <tr v-if="loading" class="sr-only">
                <td :colspan="colspan" role="status">{{ t('common.loading') }}</td>
            </tr>
            <tr v-else-if="error">
                <td :colspan="colspan" class="px-4 py-6 text-center text-red-600">{{ error }}</td>
            </tr>
            <tr v-else-if="empty">
                <td :colspan="colspan" class="px-4 py-10 text-center text-slate-400">{{ emptyText || t('common.empty') }}</td>
            </tr>
        `,
    };

    CertiSub.components.PageHeader = {
        props: {
            title: { type: String, required: true },
            description: { type: String, default: '' },
        },
        template: `
            <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">{{ title }}</h2>
                    <p v-if="description" class="text-slate-500 text-sm mt-1">{{ description }}</p>
                </div>
                <div v-if="$slots.default" class="flex flex-wrap gap-2 shrink-0"><slot></slot></div>
            </div>
        `,
    };

    CertiSub.components.ToastStack = {
        data() {
            return { toasts: CertiSub.toasts };
        },
        methods: {
            dismiss: CertiSub.dismiss,
        },
        template: `
            <div class="fixed bottom-4 right-4 z-[60] flex flex-col gap-2 w-80 max-w-[calc(100vw-2rem)]" aria-live="polite">
                <div v-for="toast in toasts" :key="toast.id"
                     :class="['rounded-lg shadow-lg px-4 py-3 text-sm flex items-start gap-3 border',
                              toast.type === 'error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800']">
                    <span class="flex-1">{{ toast.message }}</span>
                    <button type="button" class="opacity-60 hover:opacity-100" :aria-label="t('common.close')" @click="dismiss(toast.id)">×</button>
                </div>
            </div>
        `,
    };

    CertiSub.components.ConfirmDialog = {
        data() {
            return { state: CertiSub.confirmState };
        },
        methods: {
            answer(result) {
                CertiSub.settleConfirm(result);
            },
        },
        template: `
            <div v-if="state.open" class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="alertdialog" aria-modal="true">
                <div class="fixed inset-0 bg-slate-900/50" @click="answer(false)"></div>
                <div class="relative bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-md p-6">
                    <h3 class="text-lg font-bold text-slate-900 mb-2">{{ state.title }}</h3>
                    <p class="text-sm text-slate-600 leading-relaxed mb-6 whitespace-pre-line">{{ state.message }}</p>
                    <div class="flex gap-3">
                        <button type="button" class="flex-1 btn-secondary" @click="answer(false)">{{ t('common.cancel') }}</button>
                        <button type="button" :class="['flex-1', state.danger ? 'btn-danger' : 'btn-primary']" @click="answer(true)">{{ state.confirmLabel }}</button>
                    </div>
                </div>
            </div>
        `,
    };

    /**
     * Opis zdarzenia z historii (F17): etykieta z tłumaczeń (event.<encja>.<typ>), ikona, kategoria
     * i szczegóły zmian z pola payload. Wspólny dla osi czasu i dziennika zdarzeń administratora.
     */
    function eventValue(field, raw) {
        if (raw === null || raw === undefined || raw === '') {
            return '—';
        }
        if (field === 'certificate_type') {
            return labels.type(raw);
        }
        if (field === 'status') {
            return labels.status(raw);
        }
        if (field === 'payment_status') {
            return labels.payment(raw);
        }
        if (field === 'billing_cycle') {
            return labels.billing(raw);
        }
        if (field === 'role') {
            return labels.role(raw);
        }
        if (field === 'auto_renew') {
            return raw === true || raw === 1 || raw === '1' ? t('common.yes') : t('common.no');
        }
        if (/_date$|^valid_from$/.test(field)) {
            return format.date(raw);
        }
        if (/_id$/.test(field)) {
            return '#' + raw;
        }
        if (typeof raw === 'object') {
            return JSON.stringify(raw);
        }
        return String(raw);
    }

    const EVENT_CATEGORIES = {
        certificate: 'records',
        beneficiary: 'records',
        payer: 'records',
        renewal_task: 'tasks',
        invitation: 'invitations',
    };

    CertiSub.events = {
        categories: ['records', 'tasks', 'invitations', 'system'],
        category(event) {
            return EVENT_CATEGORIES[event.entity_type] || 'system';
        },
        label(event) {
            const key = 'event.' + event.entity_type + '.' + event.event_type;
            const text = t(key);
            return text === key ? t('event.generic', { type: event.event_type }) : text;
        },
        icon(event) {
            const type = event.event_type;
            if (type.indexOf('archived') !== -1 || type === 'task_closed' || type === 'account_deactivated') {
                return '📦';
            }
            if (type.indexOf('created') !== -1 || type === 'task_opened' || type === 'attachment_uploaded') {
                return '➕';
            }
            if (type.indexOf('invitation') !== -1 || type.indexOf('reminder') !== -1) {
                return '✉️';
            }
            if (type === 'restored' || type === 'account_reactivated') {
                return '♻️';
            }
            if (type === 'renewed') {
                return '🔁';
            }
            if (type === 'email_received' || type === 'eml_imported') {
                return '📨';
            }
            if (type === 'data_imported') {
                return '📥';
            }
            if (type === 'data_exported') {
                return '📤';
            }
            if (event.entity_type === 'system') {
                return '⚙️';
            }
            return '✏️';
        },
        details(event) {
            const payload = event.payload || {};
            const lines = [];
            if (payload.changes) {
                Object.keys(payload.changes).forEach((field) => {
                    const change = payload.changes[field];
                    lines.push(t('field.' + field) + ': ' + eventValue(field, change.from) + ' → ' + eventValue(field, change.to));
                });
            }
            if (event.event_type === 'task_priority_changed') {
                lines.push(labels.priority(payload.from) + ' → ' + labels.priority(payload.to));
            } else if (event.entity_type === 'renewal_task' && payload.from && payload.status) {
                lines.push(t('task.status.' + payload.from) + ' → ' + t('task.status.' + payload.status));
            } else if (event.event_type === 'task_opened' && payload.priority) {
                lines.push(labels.priority(payload.priority));
            }
            if (event.event_type === 'task_assigned') {
                lines.push((payload.from_name || t('task.unassigned')) + ' → ' + (payload.to_name || t('task.unassigned')));
            } else if (payload.from_name && payload.to_name) {
                lines.push(payload.from_name + ' → ' + payload.to_name);
            }
            if (event.event_type === 'renewal_scan') {
                lines.push(t('event.system.renewal_scan_detail', {
                    scanned: payload.scanned || 0,
                    created: payload.created || 0,
                    updated: payload.updated || 0,
                }));
            }
            if (event.event_type === 'email_received') {
                if (payload.from) {
                    lines.push(t('event.detail.from', { from: payload.from_name ? payload.from_name + ' <' + payload.from + '>' : payload.from }));
                }
                if (payload.subject) {
                    lines.push(t('event.detail.subject', { subject: payload.subject }));
                }
                if (payload.excerpt) {
                    lines.push(payload.excerpt);
                }
            } else if (event.event_type === 'data_imported') {
                lines.push(t('event.detail.imported', {
                    file: payload.file_name || '—',
                    created: payload.created || 0,
                    updated: payload.updated || 0,
                    skipped: payload.skipped || 0,
                    errors: payload.errors || 0,
                }));
            } else if (event.event_type === 'data_exported') {
                lines.push(t('event.detail.exported', {
                    dataset: t('exchange.dataset.' + payload.dataset),
                    format: String(payload.format || '').toUpperCase(),
                    records: payload.records || 0,
                }));
            } else if (event.event_type === 'eml_imported') {
                lines.push(t('event.detail.subject', { subject: payload.subject || '—' }));
                if (payload.from) {
                    lines.push(t('event.detail.from', { from: payload.from }));
                }
            }
            if (payload.recipient_email) {
                lines.push(payload.recipient_email);
            }
            if (payload.new_certificate_id) {
                lines.push(t('event.certificate.renewed_detail', { id: payload.new_certificate_id }));
            }
            if (payload.error) {
                lines.push(payload.error);
            }
            if (payload.note) {
                lines.push(payload.note);
            }
            return lines;
        },
        /**
         * Czy nazwę certyfikatu w zdarzeniu można otworzyć — rekord z archiwum tylko z dostępem do archiwum.
         */
        certificateLink(event) {
            return Boolean(event.certificate_id && event.certificate_name)
                && (!event.certificate_archived_at || CertiSub.can('archive.view'));
        },
    };

    /**
     * Oś czasu zdarzeń (F17). Opcjonalnie z filtrem kategorii i podziałem na dni,
     * a nazwy certyfikatów otwierają ich szczegóły.
     */
    CertiSub.components.EventTimeline = {
        props: {
            events: { type: Array, default: () => [] },
            showCertificate: { type: Boolean, default: false },
            filterable: { type: Boolean, default: false },
            groupByDay: { type: Boolean, default: false },
        },
        data() {
            return { category: '' };
        },
        computed: {
            categories() {
                const counts = {};
                this.events.forEach((event) => {
                    const category = CertiSub.events.category(event);
                    counts[category] = (counts[category] || 0) + 1;
                });
                return CertiSub.events.categories
                    .filter((name) => counts[name])
                    .map((name) => ({ name, count: counts[name] }));
            },
            visible() {
                if (!this.category) {
                    return this.events;
                }
                return this.events.filter((event) => CertiSub.events.category(event) === this.category);
            },
            groups() {
                if (!this.groupByDay) {
                    return [{ day: 'all', events: this.visible }];
                }
                const groups = [];
                this.visible.forEach((event) => {
                    const day = String(event.occurred_at || '').slice(0, 10);
                    const last = groups[groups.length - 1];
                    if (last && last.day === day) {
                        last.events.push(event);
                    } else {
                        groups.push({ day, events: [event] });
                    }
                });
                return groups;
            },
        },
        methods: {
            label: (event) => CertiSub.events.label(event),
            icon: (event) => CertiSub.events.icon(event),
            details: (event) => CertiSub.events.details(event),
            canOpen: (event) => CertiSub.events.certificateLink(event),
            openCertificate(event) {
                CertiSub.openDrawer('certificate', event.certificate_id);
            },
            chipClass(active) {
                return ['badge border transition', active ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'];
            },
        },
        template: `
            <div>
                <div v-if="filterable && categories.length > 1" class="flex flex-wrap gap-2 mb-4 no-print" role="group" :aria-label="t('timeline.filter')">
                    <button type="button" :class="chipClass(category === '')" :aria-pressed="category === ''" @click="category = ''">
                        {{ t('timeline.category.all') }} · {{ events.length }}
                    </button>
                    <button v-for="item in categories" :key="item.name" type="button" :class="chipClass(category === item.name)"
                            :aria-pressed="category === item.name" @click="category = item.name">
                        {{ t('timeline.category.' + item.name) }} · {{ item.count }}
                    </button>
                </div>
                <p v-if="visible.length === 0" class="text-sm text-slate-400">{{ t('timeline.empty') }}</p>
                <div v-for="group in groups" :key="group.day" :class="groupByDay ? 'mb-5 break-inside-avoid' : ''">
                    <p v-if="groupByDay" class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">{{ format.date(group.day) }}</p>
                    <ol class="relative border-l-2 border-slate-200 ml-2 space-y-4">
                        <li v-for="event in group.events" :key="event.id" class="ml-5">
                            <span class="absolute -left-[11px] flex items-center justify-center w-5 h-5 rounded-full bg-white border border-slate-200 text-[10px]" aria-hidden="true">{{ icon(event) }}</span>
                            <div class="text-sm font-medium text-slate-800">{{ label(event) }}</div>
                            <div class="text-xs text-slate-500">
                                {{ groupByDay ? format.time(event.occurred_at) : format.dateTime(event.occurred_at) }}
                                <span> · {{ event.user_name || t('eventlog.system_user') }}</span>
                                <template v-if="showCertificate && event.certificate_name">
                                    ·
                                    <button v-if="canOpen(event)" type="button" class="text-brand-700 hover:underline" @click="openCertificate(event)">{{ event.certificate_name }}</button>
                                    <span v-else>{{ event.certificate_name }}</span>
                                </template>
                            </div>
                            <ul v-if="details(event).length" class="mt-1 text-xs text-slate-600 space-y-0.5">
                                <li v-for="(line, index) in details(event)" :key="index" class="break-words">{{ line }}</li>
                            </ul>
                        </li>
                    </ol>
                </div>
            </div>
        `,
    };

    /**
     * Przyciski eksportu do CSV i XML (F9) — tylko dla ról z uprawnieniem export.run.
     * CSV: etykiety i wartości w języku interfejsu (arkusz), XML: nazwy pól i kody (inne systemy).
     */
    CertiSub.components.ExportButtons = {
        props: {
            dataset: { type: String, required: true },
            params: { type: Object, default: () => ({}) },
        },
        data() {
            return { busy: '' };
        },
        methods: {
            async run(fileFormat) {
                this.busy = fileFormat;
                try {
                    const name = await CertiSub.download(CertiSub.endpoints.export, Object.assign({ dataset: this.dataset, format: fileFormat }, this.params));
                    CertiSub.notify(t('exchange.export.done', { file: name }));
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.busy = '';
                }
            },
        },
        template: `
            <div v-if="can('export.run')" class="inline-flex items-stretch no-print" role="group" :aria-label="t('exchange.export.label')">
                <span class="inline-flex items-center px-3 text-xs text-slate-500 border border-r-0 border-slate-300 rounded-l-lg bg-slate-50" aria-hidden="true">⬇ {{ t('exchange.export.short') }}</span>
                <button type="button" class="px-3 py-2 text-sm font-medium text-slate-700 border border-slate-300 bg-white hover:bg-slate-50 disabled:opacity-60"
                        :title="t('exchange.export.csv_hint')" :disabled="busy !== ''" @click="run('csv')">{{ busy === 'csv' ? '…' : 'CSV' }}</button>
                <button type="button" class="px-3 py-2 text-sm font-medium text-slate-700 border border-l-0 border-slate-300 rounded-r-lg bg-white hover:bg-slate-50 disabled:opacity-60"
                        :title="t('exchange.export.xml_hint')" :disabled="busy !== ''" @click="run('xml')">{{ busy === 'xml' ? '…' : 'XML' }}</button>
            </div>
        `,
    };

    CertiSub.components.DetailGrid = {
        props: {
            rows: { type: Array, required: true },
        },
        template: `
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div v-for="row in rows" :key="row.label" :class="row.wide ? 'sm:col-span-2' : ''">
                    <dt class="text-slate-400 text-xs uppercase tracking-wide">{{ row.label }}</dt>
                    <dd class="text-slate-800 mt-0.5 break-words whitespace-pre-line">{{ row.value === null || row.value === undefined || row.value === '' ? '—' : row.value }}</dd>
                </div>
            </dl>
        `,
    };
})(window.CertiSub);
