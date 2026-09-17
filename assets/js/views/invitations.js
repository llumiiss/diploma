/*
 * Zaproszenia do odnowienia (F13, F14): okno wysyłki z podglądem, rejestr wysyłek i przypomnienia.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    /**
     * Akcje na zaproszeniu wspólne dla rejestru i panelu szczegółów.
     */
    async function invitationAction(invitation, action) {
        if (action === 'close') {
            const ok = await CertiSub.confirm({
                title: t('invitation.close_title'),
                message: t('invitation.close_confirm', { email: invitation.recipient_email }),
                confirmLabel: t('invitation.action.close'),
            });
            if (!ok) {
                return null;
            }
        }
        try {
            const result = await api.post(endpoints.invitations, { action, id: invitation.id });
            const updated = result.invitation;
            if (action === 'remind' || action === 'retry') {
                const failed = action === 'retry' ? updated.status === 'failed' : Boolean(updated.last_error);
                CertiSub.notify(t(failed ? 'invitation.delivery_failed' : 'invitation.action_done.' + action), failed ? 'error' : 'success');
            } else {
                CertiSub.notify(t('invitation.action_done.' + action));
            }
            CertiSub.data.refresh('invitations', 'tasks', 'taskStats', 'certificates', 'summary');
            return updated;
        } catch (error) {
            CertiSub.notifyError(error);
            return null;
        }
    }
    CertiSub.invitationAction = invitationAction;

    CertiSub.components.InvitationsView = {
        data() {
            return { store: CertiSub.store, status: 'active', due: false, search: '' };
        },
        computed: {
            state() {
                return this.store.invitations;
            },
            filtered() {
                const query = this.search.trim().toLowerCase();
                if (!query) {
                    return this.state.items;
                }
                return this.state.items.filter((item) => [item.certificate_name, item.recipient_email, item.recipient_name, item.subject]
                    .some((value) => value && String(value).toLowerCase().indexOf(query) !== -1));
            },
        },
        watch: {
            status() {
                this.reload();
            },
            due() {
                this.reload();
            },
        },
        mounted() {
            this.reload();
        },
        methods: {
            reload() {
                CertiSub.data.invitationFilters = { status: this.status === 'all' ? '' : this.status, due: this.due };
                CertiSub.data.invitations(true);
            },
            open(item) {
                CertiSub.openDrawer('invitation', item.id);
            },
            act(item, action) {
                invitationAction(item, action);
            },
        },
        template: `
            <section>
                <PageHeader :title="t('invitation.title')" :description="t('invitation.description')" />

                <div class="card p-4 mb-4 grid gap-3 md:grid-cols-3">
                    <input v-model="search" type="search" class="input" :placeholder="t('invitation.search_placeholder')" :aria-label="t('common.search')">
                    <select v-model="status" class="input" :aria-label="t('field.status')">
                        <option value="active">{{ t('invitation.filter.active') }}</option>
                        <option value="sent">{{ t('invitation.status.sent') }}</option>
                        <option value="failed">{{ t('invitation.status.failed') }}</option>
                        <option value="responded">{{ t('invitation.status.responded') }}</option>
                        <option value="closed">{{ t('invitation.status.closed') }}</option>
                        <option value="all">{{ t('invitation.filter.all') }}</option>
                    </select>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="due" type="checkbox" class="rounded border-slate-300">
                        {{ t('invitation.filter.due') }}
                    </label>
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[980px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('invitation.sent_at') }}</th>
                                <th class="th">{{ t('certificate.singular') }}</th>
                                <th class="th">{{ t('invitation.recipient') }}</th>
                                <th class="th">{{ t('field.status') }}</th>
                                <th class="th">{{ t('invitation.reminders') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in filtered" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                <td class="td whitespace-nowrap">{{ format.dateTime(item.sent_at || item.created_at) }}</td>
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ item.certificate_name }}</div>
                                    <div class="text-xs text-slate-500">{{ item.template_name || '—' }}</div>
                                </td>
                                <td class="td">
                                    <div>{{ item.recipient_name || item.recipient_email }}</div>
                                    <div class="text-xs text-slate-500">{{ t('invitation.recipient_type.' + item.recipient_type) }} · {{ item.recipient_email }}</div>
                                </td>
                                <td class="td">
                                    <span :class="['badge', CertiSub.invitationBadge(item.status)]">{{ t('invitation.status.' + item.status) }}</span>
                                    <div v-if="item.last_error" class="text-xs text-red-600 mt-1 max-w-xs truncate" :title="item.last_error">{{ item.last_error }}</div>
                                </td>
                                <td class="td whitespace-nowrap">
                                    <div>{{ item.reminder_count }}</div>
                                    <div v-if="item.next_reminder_at" :class="['text-xs', item.is_due ? 'text-amber-700 font-semibold' : 'text-slate-500']">{{ t('invitation.next_reminder', { date: format.dateTime(item.next_reminder_at) }) }}</div>
                                </td>
                                <td class="td text-right whitespace-nowrap" @click.stop>
                                    <template v-if="can('invitations.send')">
                                        <button v-if="item.status === 'sent'" type="button" class="btn-ghost" @click="act(item, 'remind')">🔔 {{ t('invitation.action.remind') }}</button>
                                        <button v-if="item.status === 'failed'" type="button" class="btn-ghost" @click="act(item, 'retry')">↻ {{ t('invitation.action.retry') }}</button>
                                        <button v-if="item.status === 'sent' || item.status === 'failed'" type="button" class="btn-ghost text-emerald-700" @click="act(item, 'responded')">✔ {{ t('invitation.action.responded') }}</button>
                                    </template>
                                </td>
                            </tr>
                            <TableState :colspan="6" :loading="state.loading && !state.loaded" :error="state.error"
                                        :empty="state.loaded && filtered.length === 0" :empty-text="t('invitation.empty')" />
                        </tbody>
                    </table>
                </div>
            </section>
        `,
    };

    CertiSub.components.InvitationDrawer = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return { invitation: null, loading: true, error: '' };
        },
        computed: {
            rows() {
                const i = this.invitation;
                return [
                    { label: t('field.status'), value: t('invitation.status.' + i.status) },
                    { label: t('invitation.recipient'), value: (i.recipient_name ? i.recipient_name + ' <' + i.recipient_email + '>' : i.recipient_email) + ' · ' + t('invitation.recipient_type.' + i.recipient_type) },
                    { label: t('invitation.sent_at'), value: i.sent_at ? CertiSub.format.dateTime(i.sent_at) : null },
                    { label: t('invitation.sent_by'), value: i.sent_by_name || t('invitation.system') },
                    { label: t('invitation.template'), value: i.template_name },
                    { label: t('invitation.reminders'), value: String(i.reminder_count) + (i.last_reminder_at ? ' · ' + CertiSub.format.dateTime(i.last_reminder_at) : '') },
                    { label: t('invitation.next_reminder_label'), value: i.next_reminder_at ? CertiSub.format.dateTime(i.next_reminder_at) : null },
                    { label: t('invitation.last_error'), value: i.last_error, wide: true },
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
                    const data = await api.get(endpoints.invitations, { id: this.id });
                    this.invitation = data.invitation;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            close() {
                CertiSub.closeDrawer();
            },
            async act(action) {
                const updated = await invitationAction(this.invitation, action);
                if (updated) {
                    this.load();
                }
            },
            downloadUrl(attachment) {
                return CertiSub.withQuery(endpoints.attachments, { id: attachment.id, download: 1 });
            },
            openCertificate() {
                CertiSub.openDrawer('certificate', this.invitation.certificate_id);
            },
            openTask() {
                CertiSub.openDrawer('task', this.invitation.renewal_task_id);
            },
        },
        template: `
            <DrawerShell :title="invitation ? invitation.subject : ''" :subtitle="invitation ? invitation.certificate_name : ''" :loading="loading" :error="error" @close="close">
                <template #actions>
                    <template v-if="invitation && can('invitations.send')">
                        <button v-if="invitation.status === 'sent'" type="button" class="btn-secondary" @click="act('remind')">🔔 {{ t('invitation.action.remind') }}</button>
                        <button v-if="invitation.status === 'failed'" type="button" class="btn-secondary" @click="act('retry')">↻ {{ t('invitation.action.retry') }}</button>
                        <button v-if="invitation.status === 'sent' || invitation.status === 'failed'" type="button" class="btn-secondary" @click="act('responded')">✔ {{ t('invitation.action.responded') }}</button>
                        <button v-if="invitation.status !== 'closed'" type="button" class="btn-secondary" @click="act('close')">✖ {{ t('invitation.action.close') }}</button>
                    </template>
                </template>

                <template v-if="invitation">
                    <DetailGrid :rows="rows" />
                    <div class="flex flex-wrap gap-3 mt-4 text-sm">
                        <button type="button" class="text-brand-700 hover:underline" @click="openCertificate">{{ t('invitation.open_certificate') }} →</button>
                        <button v-if="invitation.renewal_task_id" type="button" class="text-brand-700 hover:underline" @click="openTask">{{ t('invitation.open_task') }} →</button>
                    </div>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('invitation.content') }}</h4>
                    <div class="card p-4 text-sm text-slate-700 whitespace-pre-line break-words">{{ invitation.body_text }}</div>

                    <template v-if="invitation.attachments.length">
                        <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('attachment.plural') }}</h4>
                        <ul class="card divide-y divide-slate-100 text-sm">
                            <li v-for="attachment in invitation.attachments" :key="attachment.id" class="px-4 py-2 flex justify-between gap-3">
                                <a :href="downloadUrl(attachment)" class="text-brand-700 hover:underline break-all">📎 {{ attachment.original_name }}</a>
                                <span class="text-xs text-slate-500 whitespace-nowrap">{{ CertiSub.formatBytes(attachment.size_bytes) }}</span>
                            </li>
                        </ul>
                    </template>

                    <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mt-6 mb-3">{{ t('timeline.title') }}</h4>
                    <EventTimeline :events="invitation.events" />
                </template>
            </DrawerShell>
        `,
    };

    CertiSub.formatBytes = function (bytes) {
        const size = Number(bytes || 0);
        if (size < 1024) {
            return size + ' B';
        }
        if (size < 1024 * 1024) {
            return (size / 1024).toFixed(1) + ' KB';
        }
        return (size / 1024 / 1024).toFixed(1) + ' MB';
    };

    CertiSub.components.InvitationComposer = {
        props: {
            certificateId: { type: Number, required: true },
        },
        emits: ['close', 'saved'],
        data() {
            return {
                options: null,
                loading: true,
                message: '',
                errors: {},
                form: { recipient_type: 'beneficiary', template_id: '', attachment_ids: [] },
                preview: null,
                previewError: '',
                previewLoading: false,
                sending: false,
            };
        },
        computed: {
            selectedTemplate() {
                if (!this.options) {
                    return null;
                }
                return this.options.templates.find((template) => String(template.id) === String(this.form.template_id)) || null;
            },
            invitationTemplates() {
                return this.options ? this.options.templates : [];
            },
        },
        async mounted() {
            try {
                const data = await api.get(endpoints.invitations, { view: 'compose', certificate_id: this.certificateId });
                this.options = data.compose;
                const withEmail = this.options.recipients.find((recipient) => recipient.email);
                this.form.recipient_type = withEmail ? withEmail.type : 'beneficiary';
                this.form.template_id = this.options.default_template_id ? String(this.options.default_template_id) : '';
                this.applyTemplateAttachments();
                await this.refreshPreview();
            } catch (error) {
                this.message = error.message;
            } finally {
                this.loading = false;
            }
        },
        methods: {
            applyTemplateAttachments() {
                this.form.attachment_ids = this.selectedTemplate ? this.selectedTemplate.attachment_ids.map(String) : [];
            },
            async onTemplateChange() {
                this.applyTemplateAttachments();
                await this.refreshPreview();
            },
            async refreshPreview() {
                if (!this.form.template_id) {
                    this.preview = null;
                    return;
                }
                this.previewLoading = true;
                this.previewError = '';
                try {
                    const result = await api.post(endpoints.invitations, {
                        action: 'preview',
                        data: { certificate_id: this.certificateId, template_id: Number(this.form.template_id), recipient_type: this.form.recipient_type },
                    });
                    this.preview = result.preview;
                } catch (error) {
                    this.preview = null;
                    this.previewError = (error.errors && Object.values(error.errors)[0]) || error.message;
                } finally {
                    this.previewLoading = false;
                }
            },
            async send() {
                this.sending = true;
                this.message = '';
                this.errors = {};
                try {
                    const result = await api.post(endpoints.invitations, {
                        action: 'send',
                        data: {
                            certificate_id: this.certificateId,
                            template_id: Number(this.form.template_id),
                            recipient_type: this.form.recipient_type,
                            attachment_ids: this.form.attachment_ids.map(Number),
                        },
                    });
                    const invitation = result.invitation;
                    if (invitation.status === 'failed') {
                        CertiSub.notify(t('invitation.send_failed', { error: invitation.last_error || '' }), 'error');
                    } else {
                        CertiSub.notify(t('invitation.sent_to', { email: invitation.recipient_email }));
                    }
                    CertiSub.data.refresh('invitations', 'tasks', 'taskStats', 'certificates', 'summary');
                    this.$emit('saved', invitation);
                    this.$emit('close');
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                } finally {
                    this.sending = false;
                }
            },
        },
        template: `
            <ModalShell :title="t('invitation.compose_title')" :subtitle="options ? options.certificate.name + ' · ' + format.date(options.certificate.expiry_date) : ''"
                        size="xl" :busy="sending" @close="$emit('close')">
                <div v-if="loading" class="py-10 text-center text-slate-400">{{ t('common.loading') }}</div>
                <div v-else-if="options" class="grid lg:grid-cols-5 gap-6">
                    <div class="lg:col-span-2 space-y-4">
                        <div v-if="message" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                        <fieldset>
                            <legend class="block text-sm font-medium text-slate-700 mb-2">{{ t('invitation.recipient') }}</legend>
                            <label v-for="recipient in options.recipients" :key="recipient.type"
                                   :class="['flex items-start gap-2 p-3 rounded-lg border mb-2', recipient.email ? 'border-slate-200 cursor-pointer hover:bg-slate-50' : 'border-slate-100 opacity-60']">
                                <input v-model="form.recipient_type" type="radio" :value="recipient.type" :disabled="!recipient.email" class="mt-1" @change="refreshPreview">
                                <span class="text-sm">
                                    <span class="block font-medium text-slate-800">{{ t('invitation.recipient_type.' + recipient.type) }}: {{ recipient.name || '—' }}</span>
                                    <span class="block text-xs" :class="recipient.email ? 'text-slate-500' : 'text-red-600'">{{ recipient.email || t('invitation.no_email_hint') }}</span>
                                </span>
                            </label>
                            <p v-if="errors.recipient_type" class="text-xs text-red-600">{{ errors.recipient_type }}</p>
                        </fieldset>

                        <FormField :label="t('invitation.template')" :error="errors.template_id" required>
                            <select v-model="form.template_id" class="input" @change="onTemplateChange">
                                <option value="">{{ t('common.choose') }}</option>
                                <option v-for="template in invitationTemplates" :key="template.id" :value="String(template.id)">{{ template.name }} ({{ template.locale.toUpperCase() }})</option>
                            </select>
                        </FormField>

                        <fieldset>
                            <legend class="block text-sm font-medium text-slate-700 mb-2">{{ t('attachment.plural') }}</legend>
                            <p v-if="options.attachments.length === 0" class="text-xs text-slate-400">{{ t('attachment.empty') }}</p>
                            <label v-for="attachment in options.attachments" :key="attachment.id" class="flex items-center gap-2 text-sm py-1">
                                <input v-model="form.attachment_ids" type="checkbox" :value="String(attachment.id)" class="rounded border-slate-300">
                                <span class="break-all">{{ attachment.original_name }}</span>
                                <span class="text-xs text-slate-400 whitespace-nowrap">{{ CertiSub.formatBytes(attachment.size_bytes) }}</span>
                            </label>
                            <p v-if="errors.attachment_ids" class="text-xs text-red-600">{{ errors.attachment_ids }}</p>
                        </fieldset>

                        <p class="text-xs text-slate-500">{{ options.open_task_id ? t('invitation.task_hint_existing') : t('invitation.task_hint_new') }}</p>
                    </div>

                    <div class="lg:col-span-3">
                        <p class="block text-sm font-medium text-slate-700 mb-2">{{ t('invitation.preview') }}</p>
                        <div class="card overflow-hidden">
                            <div v-if="previewLoading" class="p-6 text-slate-400 text-sm">{{ t('common.loading') }}</div>
                            <div v-else-if="previewError" class="p-6 text-red-600 text-sm">{{ previewError }}</div>
                            <template v-else-if="preview">
                                <div class="px-4 py-3 border-b border-slate-100 text-sm">
                                    <div><span class="text-slate-400">{{ t('invitation.to') }}:</span> {{ preview.recipient.name }} &lt;{{ preview.recipient.email }}&gt;</div>
                                    <div><span class="text-slate-400">{{ t('invitation.subject') }}:</span> <strong>{{ preview.subject }}</strong></div>
                                </div>
                                <iframe :srcdoc="preview.body_html" sandbox="" class="w-full h-80 bg-white" :title="t('invitation.preview')"></iframe>
                            </template>
                            <div v-else class="p-6 text-slate-400 text-sm">{{ t('invitation.choose_template') }}</div>
                        </div>
                    </div>
                </div>

                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="sending" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="button" class="btn-primary" :disabled="sending || loading || !preview" @click="send">{{ sending ? t('invitation.sending') : '✉️ ' + t('invitation.send') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
