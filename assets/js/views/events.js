/*
 * Dziennik zdarzeń administratora (F8, F17): wszystkie zdarzenia systemu z filtrami
 * (obszar, zdarzenie, autor, zakres dat, tekst) i stronicowaniem. Tylko do odczytu.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints, format } = CertiSub;

    function emptyFilters() {
        return { q: '', entity_type: '', event_type: '', user: '', date_from: '', date_to: '' };
    }

    CertiSub.components.EventLogView = {
        data() {
            return {
                filters: emptyFilters(),
                page: 1,
                perPage: 50,
                journal: null,
                loading: false,
                error: '',
                errors: {},
                sequence: 0,
            };
        },
        computed: {
            facets() {
                return this.journal ? this.journal.facets : { event_types: [], users: [], has_system: false };
            },
            entityTypes() {
                const seen = [];
                this.facets.event_types.forEach((item) => {
                    if (seen.indexOf(item.entity_type) === -1) {
                        seen.push(item.entity_type);
                    }
                });
                return seen;
            },
            eventTypes() {
                const list = this.facets.event_types.filter((item) => !this.filters.entity_type || item.entity_type === this.filters.entity_type);
                const merged = {};
                list.forEach((item) => {
                    const label = CertiSub.events.label(item);
                    if (!merged[item.event_type]) {
                        merged[item.event_type] = { value: item.event_type, labels: [], count: 0 };
                    }
                    if (merged[item.event_type].labels.indexOf(label) === -1) {
                        merged[item.event_type].labels.push(label);
                    }
                    merged[item.event_type].count += item.count;
                });
                return Object.values(merged)
                    .map((item) => ({ value: item.value, label: item.labels.join(' / '), count: item.count }))
                    .sort((a, b) => a.label.localeCompare(b.label));
            },
            hasFilters() {
                return Object.keys(this.filters).some((key) => this.filters[key] !== '');
            },
            events() {
                return this.journal ? this.journal.events : [];
            },
        },
        watch: {
            'filters.entity_type'() {
                if (this.filters.event_type && !this.eventTypes.some((item) => item.value === this.filters.event_type)) {
                    this.filters.event_type = '';
                }
                this.reload();
            },
            'filters.event_type'() {
                this.reload();
            },
            'filters.user'() {
                this.reload();
            },
            'filters.date_from'() {
                this.reload();
            },
            'filters.date_to'() {
                this.reload();
            },
            'filters.q'() {
                this.reloadDebounced();
            },
            perPage() {
                this.reload();
            },
        },
        created() {
            this.reloadDebounced = CertiSub.debounce(() => this.reload(), 350);
        },
        mounted() {
            this.load();
        },
        methods: {
            reload() {
                this.page = 1;
                this.load();
            },
            async load() {
                const sequence = ++this.sequence;
                this.loading = true;
                this.error = '';
                try {
                    const data = await api.get(endpoints.events, Object.assign({}, this.filters, { page: this.page, per_page: this.perPage }));
                    if (sequence === this.sequence) {
                        this.journal = data.journal;
                        this.page = data.journal.page;
                        this.errors = {};
                    }
                } catch (error) {
                    if (sequence === this.sequence) {
                        this.error = error.message;
                        this.errors = error.errors || {};
                    }
                } finally {
                    if (sequence === this.sequence) {
                        this.loading = false;
                    }
                }
            },
            go(page) {
                this.page = page;
                this.load();
            },
            reset() {
                this.filters = emptyFilters();
                this.reload();
            },
            label: (event) => CertiSub.events.label(event),
            icon: (event) => CertiSub.events.icon(event),
            details: (event) => CertiSub.events.details(event),
            canOpenCertificate: (event) => CertiSub.events.certificateLink(event),
            openCertificate(event) {
                CertiSub.openDrawer('certificate', event.certificate_id);
            },
            openBeneficiary(event) {
                CertiSub.openBeneficiaryCard(event.beneficiary_id);
            },
            openPayer(event) {
                CertiSub.openPayerCard(event.payer_id);
            },
            openEntity(event) {
                CertiSub.openDrawer(event.entity_type === 'renewal_task' ? 'task' : 'invitation', event.entity_id);
            },
            entityLabel(type) {
                return t('eventlog.entity.' + type);
            },
        },
        template: `
            <section>
                <PageHeader :title="t('eventlog.title')" :description="t('eventlog.description')">
                    <ExportButtons dataset="events" :params="filters" />
                </PageHeader>

                <div class="card p-4 mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 mb-1" for="eventlog-q">{{ t('common.search') }}</label>
                        <input id="eventlog-q" v-model="filters.q" type="search" class="input" maxlength="100" :placeholder="t('eventlog.filter.search')">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1" for="eventlog-entity">{{ t('eventlog.filter.entity') }}</label>
                        <select id="eventlog-entity" v-model="filters.entity_type" class="input">
                            <option value="">{{ t('eventlog.filter.all_entities') }}</option>
                            <option v-for="type in entityTypes" :key="type" :value="type">{{ entityLabel(type) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1" for="eventlog-event">{{ t('eventlog.filter.event') }}</label>
                        <select id="eventlog-event" v-model="filters.event_type" class="input">
                            <option value="">{{ t('eventlog.filter.all_events') }}</option>
                            <option v-for="item in eventTypes" :key="item.value" :value="item.value">{{ item.label }} ({{ item.count }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1" for="eventlog-user">{{ t('eventlog.filter.user') }}</label>
                        <select id="eventlog-user" v-model="filters.user" class="input">
                            <option value="">{{ t('eventlog.filter.all_users') }}</option>
                            <option v-for="user in facets.users" :key="user.id" :value="String(user.id)">{{ user.name }}</option>
                            <option v-if="facets.has_system" value="system">{{ t('eventlog.system_user') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1" for="eventlog-from">{{ t('eventlog.filter.date_from') }}</label>
                        <input id="eventlog-from" v-model="filters.date_from" type="date" :class="['input', errors.date_from ? 'input-error' : '']">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1" for="eventlog-to">{{ t('eventlog.filter.date_to') }}</label>
                        <input id="eventlog-to" v-model="filters.date_to" type="date" :class="['input', errors.date_to ? 'input-error' : '']">
                    </div>
                    <div class="sm:col-span-2 xl:col-span-3 flex flex-wrap items-end justify-between gap-2 text-sm">
                        <span class="text-slate-500 pb-2" role="status">{{ journal ? t('eventlog.total', { count: journal.total }) : '' }}</span>
                        <button v-if="hasFilters" type="button" class="btn-secondary" @click="reset">{{ t('common.clear_filters') }}</button>
                    </div>
                </div>

                <p v-if="errors.date_to || errors.date_from" class="mb-4 text-sm text-red-600" role="alert">{{ errors.date_to || errors.date_from }}</p>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[960px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('eventlog.column.time') }}</th>
                                <th class="th">{{ t('eventlog.column.user') }}</th>
                                <th class="th">{{ t('eventlog.column.event') }}</th>
                                <th class="th">{{ t('eventlog.column.subject') }}</th>
                            </tr>
                        </thead>
                        <tbody :class="loading && journal ? 'opacity-60' : ''">
                            <tr v-for="event in events" :key="event.id" class="border-b border-slate-100 align-top">
                                <td class="td whitespace-nowrap text-slate-600">{{ format.dateTime(event.occurred_at) }}</td>
                                <td class="td whitespace-nowrap">{{ event.user_name || t('eventlog.system_user') }}</td>
                                <td class="td">
                                    <div class="font-medium text-slate-800"><span aria-hidden="true">{{ icon(event) }}</span> {{ label(event) }}</div>
                                    <div class="text-xs text-slate-400">{{ entityLabel(event.entity_type) }}</div>
                                    <ul v-if="details(event).length" class="mt-1 text-xs text-slate-600 space-y-0.5">
                                        <li v-for="(line, index) in details(event)" :key="index" class="break-words">{{ line }}</li>
                                    </ul>
                                </td>
                                <td class="td text-xs space-y-1">
                                    <div v-if="event.certificate_name">
                                        📜
                                        <button v-if="canOpenCertificate(event)" type="button" class="text-brand-700 hover:underline text-left" @click="openCertificate(event)">{{ event.certificate_name }}</button>
                                        <span v-else>{{ event.certificate_name }}</span>
                                    </div>
                                    <div v-if="event.beneficiary_name">
                                        👤 <button type="button" class="text-brand-700 hover:underline text-left" @click="openBeneficiary(event)">{{ event.beneficiary_name }}</button>
                                    </div>
                                    <div v-if="event.payer_name">
                                        🏢
                                        <button type="button" class="text-brand-700 hover:underline text-left" @click="openPayer(event)">{{ event.payer_name }}</button>
                                    </div>
                                    <div v-if="(event.entity_type === 'renewal_task' || event.entity_type === 'invitation') && event.entity_id">
                                        <button type="button" class="text-brand-700 hover:underline" @click="openEntity(event)">{{ t(event.entity_type === 'renewal_task' ? 'eventlog.task' : 'eventlog.invitation', { id: event.entity_id }) }}</button>
                                    </div>
                                    <div v-if="event.entity_label" class="text-slate-700">{{ event.entity_label }}</div>
                                    <div v-else-if="!event.certificate_name && !event.beneficiary_name && !event.payer_name && event.payload && event.payload.name" class="text-slate-700">{{ event.payload.name }}</div>
                                </td>
                            </tr>
                            <TableState :colspan="4" :loading="loading && !journal" :error="journal ? '' : error" :empty="Boolean(journal) && events.length === 0" :empty-text="t('eventlog.empty')" />
                        </tbody>
                    </table>
                </div>

                <p v-if="error && journal" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

                <nav v-if="journal && journal.pages > 1" class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm" :aria-label="t('eventlog.pagination')">
                    <button type="button" class="btn-secondary" :disabled="loading || journal.page <= 1" @click="go(journal.page - 1)">{{ t('eventlog.prev') }}</button>
                    <span class="text-slate-600">{{ t('eventlog.page', { page: journal.page, pages: journal.pages }) }}</span>
                    <label class="flex items-center gap-2 text-slate-600">
                        {{ t('eventlog.per_page') }}
                        <select v-model.number="perPage" class="input w-auto py-1">
                            <option v-for="value in [25, 50, 100]" :key="value" :value="value">{{ value }}</option>
                        </select>
                    </label>
                    <button type="button" class="btn-secondary" :disabled="loading || journal.page >= journal.pages" @click="go(journal.page + 1)">{{ t('eventlog.next') }}</button>
                </nav>
            </section>
        `,
    };
})(window.CertiSub);
