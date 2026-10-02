/*
 * Powiadomienia wewnętrzne: odebrane i wysłane wiadomości, wątki z odpowiedziami, prośby do załatwienia
 * oraz okno nowej wiadomości (także z kontekstu rekordu — „zgłoś / poproś o uzupełnienie”).
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;
    const { reactive } = window.Vue;

    const counters = reactive({ unread: 0, open_requests: 0, loaded: false });
    CertiSub.notificationCounters = counters;

    async function refreshCounters() {
        if (!CertiSub.can('notifications.use')) {
            return;
        }
        try {
            const data = await api.get(endpoints.notifications, { view: 'counters' });
            Object.assign(counters, data.counters, { loaded: true });
        } catch (error) {
            // Licznik jest dodatkiem do menu — błąd sieci nie przerywa pracy.
        }
    }
    CertiSub.refreshNotificationCounters = refreshCounters;

    /**
     * Okno nowej wiadomości. preset: type, subject, body, related_type, related_id, related_label, to (lista kont).
     */
    CertiSub.openComposer = function (preset) {
        CertiSub.openModal('NotificationComposer', { preset: preset || {} });
    };

    const TYPE_BADGE = {
        message: 'bg-slate-100 text-slate-700',
        request: 'bg-amber-100 text-amber-800',
        error: 'bg-red-100 text-red-800',
        system: 'bg-blue-100 text-blue-800',
    };
    CertiSub.notificationBadge = (type) => TYPE_BADGE[type] || TYPE_BADGE.message;

    CertiSub.components.NotificationComposer = {
        props: {
            preset: { type: Object, default: () => ({}) },
        },
        emits: ['close', 'saved'],
        data() {
            const preset = this.preset || {};
            return {
                form: {
                    type: preset.type || 'message',
                    subject: preset.subject || '',
                    body: preset.body || '',
                    related_type: preset.related_type || '',
                    related_id: preset.related_id || '',
                },
                to: (preset.to || []).map(Number),
                groups: preset.groups || [],
                recipients: { users: [], groups: [] },
                loading: true,
                saving: false,
                errors: {},
                message: '',
            };
        },
        computed: {
            types() {
                return ['message', 'request', 'error'];
            },
            relatedLabel() {
                return this.preset.related_label || '';
            },
        },
        async mounted() {
            try {
                const data = await api.get(endpoints.notifications, { view: 'recipients' });
                this.recipients = data.recipients;
                // Prośba trafia domyślnie do administratorów — z nimi operator ustala uzupełnienia danych.
                if (this.to.length === 0 && this.groups.length === 0 && this.recipients.groups.indexOf('admins') !== -1) {
                    this.groups = ['admins'];
                }
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
                try {
                    const result = await api.post(endpoints.notifications, {
                        action: 'send',
                        data: Object.assign({}, this.form, { to: this.to, groups: this.groups }),
                    });
                    CertiSub.notify(t('notification.sent_ok', { count: result.result.recipients }));
                    refreshCounters();
                    this.$emit('saved', result.result);
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
            <ModalShell :title="t('notification.compose')" :subtitle="t('common.required_hint')" size="lg" :busy="saving" @close="$emit('close')">
                <FormSkeleton v-if="loading" />
                <form v-else id="notification-form" class="space-y-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>

                    <FormField :label="t('notification.recipients')" :error="errors.to" required>
                        <div class="border border-slate-200 rounded-lg p-3 max-h-48 overflow-y-auto space-y-1">
                            <label v-for="group in recipients.groups" :key="group" class="flex items-center gap-2 text-sm font-medium">
                                <input v-model="groups" type="checkbox" :value="group" class="rounded border-slate-300">
                                {{ t('notification.group.' + group) }}
                            </label>
                            <label v-for="user in recipients.users" :key="user.id" class="flex items-center gap-2 text-sm">
                                <input v-model="to" type="checkbox" :value="user.id" class="rounded border-slate-300">
                                {{ user.name }} <span :class="['badge', badge.role(user.role)]">{{ labels.role(user.role) }}</span>
                            </label>
                            <p v-if="recipients.users.length === 0 && recipients.groups.length === 0" class="text-sm text-slate-400">{{ t('notification.no_recipients_available') }}</p>
                        </div>
                    </FormField>

                    <div class="grid sm:grid-cols-3 gap-4">
                        <FormField :label="t('notification.field.type')" :error="errors.type">
                            <select v-model="form.type" class="input">
                                <option v-for="type in types" :key="type" :value="type">{{ t('notification.type.' + type) }}</option>
                            </select>
                        </FormField>
                        <FormField class="sm:col-span-2" :label="t('notification.field.subject')" :error="errors.subject" required>
                            <input v-model="form.subject" type="text" maxlength="255" :class="['input', errors.subject ? 'input-error' : '']">
                        </FormField>
                    </div>

                    <FormField :label="t('notification.field.body')" :error="errors.body" required>
                        <textarea v-model="form.body" rows="6" maxlength="5000" :class="['input', errors.body ? 'input-error' : '']"></textarea>
                    </FormField>

                    <p v-if="relatedLabel" class="text-sm text-slate-600">
                        🔗 {{ t('notification.related') }}: <strong>{{ relatedLabel }}</strong>
                        <span v-if="errors.related_id" class="block text-xs text-red-600 mt-1">{{ errors.related_id }}</span>
                    </p>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="notification-form" class="btn-primary" :disabled="saving || loading">{{ saving ? t('common.saving') : t('notification.send') }}</button>
                </template>
            </ModalShell>
        `,
    };

    CertiSub.components.NotificationsView = {
        data() {
            return {
                tab: 'inbox',
                filter: 'all',
                search: '',
                items: [],
                loading: true,
                error: '',
                selectedId: null,
                thread: null,
                threadLoading: false,
                replyText: '',
                replying: false,
                counters,
            };
        },
        computed: {
            list() {
                return this.items;
            },
        },
        mounted() {
            this.load();
        },
        watch: {
            tab() {
                this.selectedId = null;
                this.thread = null;
                this.load();
            },
            filter() {
                this.load();
            },
        },
        methods: {
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const params = this.tab === 'sent' ? { view: 'sent' } : { status: this.filter, q: this.search };
                    const data = await api.get(endpoints.notifications, params);
                    this.items = data.notifications;
                    if (data.counters) {
                        Object.assign(counters, data.counters, { loaded: true });
                    }
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            async openThread(item) {
                this.selectedId = item.id;
                this.threadLoading = true;
                this.replyText = '';
                try {
                    const data = await api.get(endpoints.notifications, { view: 'thread', id: item.id });
                    this.thread = data.thread;
                    if (this.tab === 'inbox') {
                        item.unread = false;
                        item.read_at = item.read_at || 'now';
                    }
                    refreshCounters();
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.threadLoading = false;
                }
            },
            compose() {
                CertiSub.openComposer({});
            },
            async post(body, messageKey) {
                try {
                    await api.post(endpoints.notifications, body);
                    if (messageKey) {
                        CertiSub.notify(t(messageKey));
                    }
                    refreshCounters();
                    await this.load();
                    return true;
                } catch (error) {
                    CertiSub.notifyError(error);
                    return false;
                }
            },
            async markAllRead() {
                await this.post({ action: 'mark_read' }, 'notification.all_read_ok');
            },
            async sendReply() {
                if (!this.thread || this.replyText.trim() === '') {
                    return;
                }
                const last = this.thread.messages[this.thread.messages.length - 1];
                this.replying = true;
                try {
                    await api.post(endpoints.notifications, { action: 'reply', id: last.id, body: this.replyText });
                    CertiSub.notify(t('notification.reply_sent'));
                    this.replyText = '';
                    const data = await api.get(endpoints.notifications, { view: 'thread', id: last.id });
                    this.thread = data.thread;
                    this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.replying = false;
                }
            },
            async resolve(message) {
                if (await this.post({ action: 'resolve', id: message.id }, 'notification.resolved_ok')) {
                    const data = await api.get(endpoints.notifications, { view: 'thread', id: message.id });
                    this.thread = data.thread;
                }
            },
            async archive(message) {
                if (await this.post({ action: 'archive', id: message.id }, 'notification.archived_ok')) {
                    this.thread = null;
                    this.selectedId = null;
                }
            },
            async markUnread(message) {
                if (await this.post({ action: 'mark_unread', id: message.id }, null)) {
                    this.thread = null;
                    this.selectedId = null;
                }
            },
            openRelated(message) {
                if (message.related_type === 'certificate') {
                    CertiSub.openDrawer('certificate', message.related_id);
                } else if (message.related_type === 'beneficiary') {
                    CertiSub.openDrawer('beneficiary', message.related_id);
                } else if (message.related_type === 'payer') {
                    CertiSub.openDrawer('payer', message.related_id);
                } else if (message.related_type === 'registration') {
                    CertiSub.navigate('registrations', message.related_id);
                }
            },
            canReply(message) {
                return message.sender_user_id !== null || message.mine;
            },
            senderLabel(message) {
                return message.sender_name || t('notification.system_sender');
            },
        },
        template: `
            <section>
                <PageHeader :title="t('notification.title')" :description="t('notification.description')">
                    <button v-if="tab === 'inbox' && counters.unread > 0" type="button" class="btn-secondary" @click="markAllRead">✓ {{ t('notification.mark_all_read') }}</button>
                    <button type="button" class="btn-primary" @click="compose">+ {{ t('notification.compose') }}</button>
                </PageHeader>

                <div class="grid lg:grid-cols-5 gap-4">
                    <div class="lg:col-span-2">
                        <div class="card p-3 mb-3 flex flex-wrap items-center gap-2">
                            <button type="button" :class="['btn-ghost', tab === 'inbox' ? 'font-semibold text-brand-700' : '']" @click="tab = 'inbox'">📥 {{ t('notification.inbox') }}</button>
                            <button type="button" :class="['btn-ghost', tab === 'sent' ? 'font-semibold text-brand-700' : '']" @click="tab = 'sent'">📤 {{ t('notification.sent') }}</button>
                            <select v-if="tab === 'inbox'" v-model="filter" class="input w-auto ml-auto" :aria-label="t('notification.filter.label')">
                                <option value="all">{{ t('notification.filter.all') }}</option>
                                <option value="unread">{{ t('notification.filter.unread') }}</option>
                                <option value="open">{{ t('notification.filter.open') }}</option>
                            </select>
                        </div>

                        <div class="card divide-y divide-slate-100 overflow-hidden">
                            <div v-if="loading" class="p-4 space-y-3" role="status" :aria-label="t('common.loading')">
                                <div v-for="n in 4" :key="n" class="skeleton h-12 w-full"></div>
                            </div>
                            <p v-else-if="error" class="px-4 py-6 text-sm text-red-700">{{ error }}</p>
                            <p v-else-if="list.length === 0" class="px-4 py-8 text-sm text-slate-400 text-center">{{ t('notification.empty') }}</p>
                            <button v-for="item in list" :key="item.id + ':' + tab" type="button"
                                    :class="['w-full text-left px-4 py-3 hover:bg-slate-50', selectedId === item.id ? 'bg-brand-50' : '', tab === 'inbox' && item.unread ? 'font-semibold' : '']"
                                    @click="openThread(item)">
                                <span class="flex items-center gap-2">
                                    <span v-if="tab === 'inbox' && item.unread" class="w-2 h-2 rounded-full bg-brand-600 shrink-0" aria-hidden="true"></span>
                                    <span class="truncate text-sm text-slate-800">{{ item.subject }}</span>
                                    <span :class="['badge ml-auto shrink-0', CertiSub.notificationBadge(item.type)]">{{ t('notification.type.' + item.type) }}</span>
                                </span>
                                <span class="block text-xs text-slate-500 mt-1 font-normal">
                                    <template v-if="tab === 'inbox'">{{ t('notification.from') }}: {{ item.sender_name || t('notification.system_sender') }}</template>
                                    <template v-else>{{ t('notification.to') }}: {{ item.recipients }}<span v-if="item.recipient_count > 1"> · {{ t('notification.read_by', { read: item.read_count, count: item.recipient_count }) }}</span></template>
                                    · {{ format.dateTime(item.created_at) }}
                                    <span v-if="tab === 'inbox' && item.open" class="text-amber-700"> · {{ t('notification.awaiting') }}</span>
                                    <span v-else-if="tab === 'inbox' && item.resolved_at" class="text-emerald-700"> · {{ t('notification.resolved') }}</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="lg:col-span-3">
                        <div v-if="!thread && !threadLoading" class="card p-8 text-center text-slate-400 text-sm">{{ t('notification.select_thread') }}</div>
                        <div v-else-if="threadLoading" class="card p-6 space-y-3" role="status" :aria-label="t('common.loading')">
                            <div class="skeleton h-5 w-2/3"></div><div class="skeleton h-24 w-full"></div>
                        </div>
                        <div v-else class="card p-5">
                            <h3 class="text-lg font-semibold text-slate-900 mb-4">{{ thread.subject }}</h3>
                            <div class="space-y-4">
                                <article v-for="message in thread.messages" :key="message.id"
                                         :class="['rounded-lg border px-4 py-3', message.mine ? 'bg-brand-50 border-brand-100 ml-6' : 'bg-white border-slate-200 mr-6']">
                                    <header class="flex flex-wrap items-center gap-2 text-xs text-slate-500 mb-2">
                                        <strong class="text-slate-700">{{ message.mine ? t('notification.you') : senderLabel(message) }}</strong>
                                        <span aria-hidden="true">→</span>
                                        <span>{{ message.recipient_user_id === CertiSub.boot.user.id ? t('notification.you') : message.recipient_name }}</span>
                                        <span class="ml-auto">{{ format.dateTime(message.created_at) }}</span>
                                        <span :class="['badge', CertiSub.notificationBadge(message.type)]">{{ t('notification.type.' + message.type) }}</span>
                                    </header>
                                    <p class="text-sm text-slate-800 whitespace-pre-line break-words">{{ message.body }}</p>
                                    <footer class="flex flex-wrap items-center gap-2 mt-3">
                                        <button v-if="message.related_type" type="button" class="btn-ghost" @click="openRelated(message)">🔗 {{ t('notification.open_record') }} ({{ t('notification.related.' + message.related_type) }})</button>
                                        <template v-if="message.recipient_user_id === CertiSub.boot.user.id">
                                            <button v-if="message.open" type="button" class="btn-secondary" @click="resolve(message)">✓ {{ t('notification.resolve') }}</button>
                                            <span v-else-if="message.resolved_at" class="badge bg-emerald-100 text-emerald-800">✓ {{ t('notification.resolved') }} · {{ format.dateTime(message.resolved_at) }}</span>
                                            <button type="button" class="btn-ghost" @click="markUnread(message)">{{ t('notification.mark_unread') }}</button>
                                            <button type="button" class="btn-ghost text-slate-500" @click="archive(message)">📦 {{ t('notification.archive') }}</button>
                                        </template>
                                    </footer>
                                </article>
                            </div>

                            <form v-if="canReply(thread.messages[thread.messages.length - 1])" class="mt-5" @submit.prevent="sendReply">
                                <label class="block text-sm font-medium text-slate-700 mb-1" for="notification-reply">{{ t('notification.reply') }}</label>
                                <textarea id="notification-reply" v-model="replyText" rows="3" maxlength="5000" class="input" :placeholder="t('notification.reply_placeholder')"></textarea>
                                <div class="flex justify-end mt-2">
                                    <button type="submit" class="btn-primary" :disabled="replying || replyText.trim() === ''">{{ replying ? t('common.saving') : t('notification.send') }}</button>
                                </div>
                            </form>
                            <p v-else class="mt-4 text-xs text-slate-400">{{ t('notification.error.no_reply') }}</p>
                        </div>
                    </div>
                </div>
            </section>
        `,
    };
})(window.CertiSub);
