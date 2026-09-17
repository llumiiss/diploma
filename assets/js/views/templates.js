/*
 * Szablony wiadomości i biblioteka załączników (ADMIN) oraz ustawienia procesu odnowień.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    CertiSub.components.TemplatesView = {
        data() {
            return {
                tab: 'templates',
                templates: [],
                placeholders: [],
                attachments: [],
                loading: true,
                error: '',
                uploading: false,
                uploadError: '',
            };
        },
        mounted() {
            this.load();
        },
        methods: {
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const [templates, attachments] = await Promise.all([api.get(endpoints.templates), api.get(endpoints.attachments)]);
                    this.templates = templates.templates;
                    this.placeholders = templates.placeholders;
                    this.attachments = attachments.attachments;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            add() {
                CertiSub.openModal('TemplateForm', { placeholders: this.placeholders, attachments: this.attachments }, () => this.load());
            },
            edit(template) {
                CertiSub.openModal('TemplateForm', { templateId: template.id, placeholders: this.placeholders, attachments: this.attachments }, () => this.load());
            },
            async upload(event) {
                const input = event.target;
                const file = input.files && input.files[0];
                if (!file) {
                    return;
                }
                const body = new FormData();
                body.append('action', 'upload');
                body.append('file', file);
                this.uploading = true;
                this.uploadError = '';
                try {
                    await api.request(endpoints.attachments, { method: 'POST', body });
                    CertiSub.notify(t('attachment.uploaded', { name: file.name }));
                    await this.load();
                } catch (error) {
                    this.uploadError = (error.errors && error.errors.file) || error.message;
                } finally {
                    this.uploading = false;
                    input.value = '';
                }
            },
            async remove(attachment) {
                const ok = await CertiSub.confirm({
                    title: t('attachment.delete_title'),
                    message: t('attachment.delete_confirm', { name: attachment.original_name }),
                    confirmLabel: t('attachment.delete'),
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                try {
                    await api.post(endpoints.attachments, { action: 'delete', id: attachment.id });
                    CertiSub.notify(t('attachment.deleted'));
                    await this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            downloadUrl(attachment) {
                return CertiSub.withQuery(endpoints.attachments, { id: attachment.id, download: 1 });
            },
        },
        template: `
            <section>
                <PageHeader :title="t('template.title')" :description="t('template.description')">
                    <button v-if="tab === 'templates'" type="button" class="btn-primary" @click="add">+ {{ t('template.add') }}</button>
                    <label v-else :class="['btn-primary cursor-pointer', uploading ? 'opacity-60 pointer-events-none' : '']">
                        ⬆ {{ uploading ? t('attachment.uploading') : t('attachment.upload') }}
                        <input type="file" class="sr-only" :disabled="uploading" @change="upload">
                    </label>
                </PageHeader>

                <div class="flex flex-wrap gap-2 mb-4" role="tablist">
                    <button type="button" role="tab" :aria-selected="tab === 'templates'" @click="tab = 'templates'"
                            :class="['px-4 py-2 rounded-lg text-sm font-medium border transition', tab === 'templates' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']">
                        {{ t('template.plural') }} <span class="opacity-75">({{ templates.length }})</span>
                    </button>
                    <button type="button" role="tab" :aria-selected="tab === 'attachments'" @click="tab = 'attachments'"
                            :class="['px-4 py-2 rounded-lg text-sm font-medium border transition', tab === 'attachments' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']">
                        {{ t('attachment.plural') }} <span class="opacity-75">({{ attachments.length }})</span>
                    </button>
                </div>

                <div v-if="uploadError && tab === 'attachments'" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ uploadError }}</div>

                <div class="card overflow-x-auto">
                    <table v-if="tab === 'templates'" class="w-full text-sm min-w-[760px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('template.name') }}</th>
                                <th class="th">{{ t('template.code') }}</th>
                                <th class="th">{{ t('template.locale') }}</th>
                                <th class="th text-right">{{ t('attachment.plural') }}</th>
                                <th class="th text-right">{{ t('template.used') }}</th>
                                <th class="th">{{ t('field.status') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="template in templates" :key="template.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="edit(template)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ template.name }}</div>
                                    <div class="text-xs text-slate-500">{{ template.subject }}</div>
                                </td>
                                <td class="td font-mono text-xs">{{ template.code }}</td>
                                <td class="td uppercase">{{ template.locale }}</td>
                                <td class="td text-right">{{ template.attachment_count }}</td>
                                <td class="td text-right">{{ template.invitation_count }}</td>
                                <td class="td">
                                    <span :class="['badge', template.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600']">{{ template.is_active ? t('template.active') : t('template.inactive') }}</span>
                                </td>
                                <td class="td text-right" @click.stop><button type="button" class="btn-ghost" @click="edit(template)">{{ t('common.edit') }}</button></td>
                            </tr>
                            <TableState :colspan="7" :loading="loading" :error="error" :empty="!loading && templates.length === 0" />
                        </tbody>
                    </table>

                    <table v-else class="w-full text-sm min-w-[760px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('attachment.name') }}</th>
                                <th class="th">{{ t('attachment.type') }}</th>
                                <th class="th text-right">{{ t('attachment.size') }}</th>
                                <th class="th text-right">{{ t('template.plural') }}</th>
                                <th class="th text-right">{{ t('invitation.plural') }}</th>
                                <th class="th">{{ t('common.created_at') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="attachment in attachments" :key="attachment.id" class="border-b border-slate-100">
                                <td class="td"><a :href="downloadUrl(attachment)" class="text-brand-700 hover:underline break-all">📎 {{ attachment.original_name }}</a></td>
                                <td class="td text-xs text-slate-500">{{ attachment.mime_type }}</td>
                                <td class="td text-right whitespace-nowrap">{{ CertiSub.formatBytes(attachment.size_bytes) }}</td>
                                <td class="td text-right">{{ attachment.template_count }}</td>
                                <td class="td text-right">{{ attachment.invitation_count }}</td>
                                <td class="td whitespace-nowrap">{{ format.dateTime(attachment.created_at) }}<div v-if="attachment.uploaded_by" class="text-xs text-slate-500">{{ attachment.uploaded_by }}</div></td>
                                <td class="td text-right">
                                    <button v-if="attachment.template_count === 0 && attachment.invitation_count === 0" type="button" class="btn-ghost text-red-600" @click="remove(attachment)">{{ t('attachment.delete') }}</button>
                                    <span v-else class="text-xs text-slate-400" :title="t('attachment.in_use_hint')">{{ t('attachment.in_use') }}</span>
                                </td>
                            </tr>
                            <TableState :colspan="7" :loading="loading" :error="error" :empty="!loading && attachments.length === 0" :empty-text="t('attachment.empty')" />
                        </tbody>
                    </table>
                </div>
                <p v-if="tab === 'attachments'" class="text-xs text-slate-400 mt-3">{{ t('attachment.limits') }}</p>
            </section>
        `,
    };

    CertiSub.components.TemplateForm = {
        props: {
            templateId: { type: Number, default: null },
            placeholders: { type: Array, default: () => [] },
            attachments: { type: Array, default: () => [] },
        },
        emits: ['close', 'saved'],
        data() {
            return {
                form: { code: 'renewal_invitation', locale: 'pl', name: '', subject: '', body_html: '', body_text: '', is_active: true, attachment_ids: [] },
                loading: this.templateId !== null,
                saving: false,
                errors: {},
                message: '',
                preview: null,
                previewError: '',
            };
        },
        computed: {
            isEdit() {
                return this.templateId !== null;
            },
        },
        async mounted() {
            if (!this.isEdit) {
                return;
            }
            try {
                const data = await api.get(endpoints.templates, { id: this.templateId });
                const template = data.template;
                this.form = {
                    code: template.code,
                    locale: template.locale,
                    name: template.name,
                    subject: template.subject,
                    body_html: template.body_html,
                    body_text: template.body_text || '',
                    is_active: Boolean(template.is_active),
                    attachment_ids: template.attachment_ids.map(String),
                };
            } catch (error) {
                this.message = error.message;
            } finally {
                this.loading = false;
            }
        },
        methods: {
            insert(placeholder, field) {
                this.form[field] = (this.form[field] || '') + '{' + placeholder + '}';
            },
            async renderPreview() {
                this.previewError = '';
                try {
                    const result = await api.post(endpoints.templates, {
                        action: 'preview',
                        data: { subject: this.form.subject, body_html: this.form.body_html, body_text: this.form.body_text, locale: this.form.locale },
                    });
                    this.preview = result.preview;
                } catch (error) {
                    this.preview = null;
                    this.previewError = (error.errors && Object.values(error.errors)[0]) || error.message;
                }
            },
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.templates, {
                        action: this.isEdit ? 'update' : 'create',
                        id: this.templateId,
                        data: Object.assign({}, this.form, { attachment_ids: this.form.attachment_ids.map(Number) }),
                    });
                    CertiSub.notify(t(this.isEdit ? 'template.updated' : 'template.created'));
                    this.$emit('saved', result.template);
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
            <ModalShell :title="isEdit ? t('template.edit_title') : t('template.add_title')" :subtitle="t('template.form_description')"
                        size="xl" :busy="saving" @close="$emit('close')">
                <div v-if="loading" class="py-10 text-center text-slate-400">{{ t('common.loading') }}</div>
                <form v-else id="template-form" class="grid lg:grid-cols-3 gap-6" novalidate @submit.prevent="submit">
                    <div class="lg:col-span-2 space-y-4">
                        <div v-if="message" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                        <div class="grid sm:grid-cols-3 gap-4">
                            <FormField class="sm:col-span-2" :label="t('template.name')" :error="errors.name" required>
                                <input v-model="form.name" type="text" maxlength="255" :class="['input', errors.name ? 'input-error' : '']">
                            </FormField>
                            <FormField :label="t('template.locale')" :error="errors.locale" required>
                                <select v-model="form.locale" class="input">
                                    <option value="pl">PL</option>
                                    <option value="en">EN</option>
                                </select>
                            </FormField>
                            <FormField class="sm:col-span-2" :label="t('template.code')" :error="errors.code" :hint="t('template.code_hint')" required>
                                <input v-model="form.code" type="text" maxlength="64" :class="['input font-mono', errors.code ? 'input-error' : '']">
                            </FormField>
                            <label class="flex items-center gap-2 text-sm text-slate-700 mt-6">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300">
                                {{ t('template.active') }}
                            </label>
                        </div>
                        <FormField :label="t('invitation.subject')" :error="errors.subject" required>
                            <input v-model="form.subject" type="text" maxlength="255" :class="['input', errors.subject ? 'input-error' : '']">
                        </FormField>
                        <FormField :label="t('template.body_html')" :error="errors.body_html" required>
                            <textarea v-model="form.body_html" rows="9" :class="['input font-mono text-xs', errors.body_html ? 'input-error' : '']"></textarea>
                        </FormField>
                        <FormField :label="t('template.body_text')" :error="errors.body_text" :hint="t('template.body_text_hint')">
                            <textarea v-model="form.body_text" rows="5" class="input font-mono text-xs"></textarea>
                        </FormField>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <p class="text-sm font-medium text-slate-700 mb-2">{{ t('template.placeholders') }}</p>
                            <div class="flex flex-wrap gap-1">
                                <button v-for="placeholder in placeholders" :key="placeholder" type="button" class="px-2 py-1 rounded bg-slate-100 hover:bg-brand-100 text-xs font-mono" :title="t('template.insert_hint')" @click="insert(placeholder, 'body_html')">{{ '{' + placeholder + '}' }}</button>
                            </div>
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium text-slate-700 mb-2">{{ t('attachment.plural') }}</legend>
                            <p v-if="attachments.length === 0" class="text-xs text-slate-400">{{ t('attachment.empty') }}</p>
                            <label v-for="attachment in attachments" :key="attachment.id" class="flex items-center gap-2 text-sm py-1">
                                <input v-model="form.attachment_ids" type="checkbox" :value="String(attachment.id)" class="rounded border-slate-300">
                                <span class="break-all">{{ attachment.original_name }}</span>
                            </label>
                            <p v-if="errors.attachment_ids" class="text-xs text-red-600">{{ errors.attachment_ids }}</p>
                        </fieldset>
                        <div>
                            <button type="button" class="btn-secondary w-full" @click="renderPreview">👁 {{ t('template.preview') }}</button>
                            <p v-if="previewError" class="text-xs text-red-600 mt-2">{{ previewError }}</p>
                            <div v-if="preview" class="card mt-3 overflow-hidden">
                                <div class="px-3 py-2 border-b border-slate-100 text-xs">
                                    <strong>{{ preview.subject }}</strong>
                                    <div v-if="preview.sample_certificate" class="text-slate-500">{{ t('template.preview_sample', { name: preview.sample_certificate }) }}</div>
                                </div>
                                <iframe :srcdoc="preview.body_html" sandbox="" class="w-full h-56 bg-white" :title="t('template.preview')"></iframe>
                            </div>
                        </div>
                    </div>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="template-form" class="btn-primary" :disabled="saving || loading">{{ saving ? t('common.saving') : t('common.save') }}</button>
                </template>
            </ModalShell>
        `,
    };

    CertiSub.components.SettingsView = {
        data() {
            return { values: {}, definitions: {}, loading: true, saving: false, errors: {}, message: '' };
        },
        computed: {
            keys() {
                return Object.keys(this.definitions);
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            async load() {
                this.loading = true;
                try {
                    const data = await api.get(endpoints.settings);
                    this.values = Object.assign({}, data.settings.values);
                    this.definitions = data.settings.definitions;
                } catch (error) {
                    this.message = error.message;
                } finally {
                    this.loading = false;
                }
            },
            async save() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.settings, { action: 'update', data: this.values });
                    this.values = Object.assign({}, result.settings.values);
                    CertiSub.boot.thresholds.warning = this.values['renewal.warning_days'];
                    CertiSub.boot.thresholds.critical = this.values['renewal.critical_days'];
                    CertiSub.notify(t('settings.saved'));
                    CertiSub.data.refresh('summary', 'certificates');
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                } finally {
                    this.saving = false;
                }
            },
            label(key) {
                return t('settings.' + key.replace('.', '_'));
            },
            hint(key) {
                return t('settings.' + key.replace('.', '_') + '_hint', this.definitions[key]);
            },
        },
        template: `
            <section class="max-w-3xl">
                <PageHeader :title="t('settings.title')" :description="t('settings.description')" />
                <div v-if="loading" class="card p-6 text-slate-400">{{ t('common.loading') }}</div>
                <form v-else class="card p-6 space-y-5" novalidate @submit.prevent="save">
                    <div v-if="message" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <FormField v-for="key in keys" :key="key" :label="label(key)" :error="errors[key]" :hint="hint(key)">
                        <input v-model.number="values[key]" type="number" :min="definitions[key].min" :max="definitions[key].max" :class="['input max-w-xs', errors[key] ? 'input-error' : '']">
                    </FormField>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
                    </div>
                </form>
            </section>
        `,
    };
})(window.CertiSub);
