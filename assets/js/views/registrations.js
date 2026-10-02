/*
 * Wnioski o certyfikat przesłane e-mailem: kolejka wniosków i ekran sprawdzenia. Aplikacja zamienia wiadomość
 * na gotowy formularz (użytkownik, firma z Białej Listy po NIP-ie, certyfikat), a operator poprawia, co trzeba,
 * i zatwierdza jednym przyciskiem.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints, labels } = CertiSub;
    const { reactive } = window.Vue;

    const pending = reactive({ count: 0, imap: false, webhook: false, loaded: false });
    CertiSub.registrationPending = pending;

    async function refreshPending() {
        if (!CertiSub.can('registrations.view')) {
            return;
        }
        try {
            const data = await api.get(endpoints.registrations, { view: 'status' });
            pending.count = data.status.pending;
            pending.imap = data.status.imap_enabled;
            pending.webhook = data.status.webhook_enabled;
            pending.loaded = true;
        } catch (error) {
            // Znaczek w menu jest dodatkiem — błąd sieci nie przerywa pracy.
        }
    }
    CertiSub.refreshRegistrationCount = refreshPending;

    const LEVEL_STYLE = {
        error: 'bg-red-50 border-red-200 text-red-800',
        warning: 'bg-amber-50 border-amber-200 text-amber-800',
        info: 'bg-blue-50 border-blue-200 text-blue-800',
    };

    function warningText(warning) {
        return t('registration.warning.' + warning.code, warning.params || {});
    }

    const STATUS_BADGE = {
        pending: 'bg-amber-100 text-amber-800',
        approved: 'bg-emerald-100 text-emerald-800',
        rejected: 'bg-slate-200 text-slate-600',
    };

    CertiSub.components.RegistrationsView = {
        data() {
            return {
                tab: 'pending',
                items: [],
                loading: true,
                error: '',
                busy: false,
                pending,
            };
        },
        computed: {
            tabs() {
                return ['pending', 'approved', 'rejected', 'all'];
            },
        },
        watch: {
            tab() {
                this.load();
            },
        },
        mounted() {
            this.load();
            refreshPending();
        },
        methods: {
            statusBadge: (status) => STATUS_BADGE[status] || STATUS_BADGE.pending,
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const data = await api.get(endpoints.registrations, { status: this.tab });
                    this.items = data.registrations;
                    pending.count = data.pending;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            open(item) {
                CertiSub.navigate('registrations', item.id);
            },
            pickFile() {
                this.$refs.file.click();
            },
            async upload(event) {
                const file = event.target.files && event.target.files[0];
                event.target.value = '';
                if (!file) {
                    return;
                }
                this.busy = true;
                try {
                    const body = new FormData();
                    body.append('action', 'upload');
                    body.append('file', file);
                    const data = await api.post(endpoints.registrations, body);
                    const result = data.result;
                    if (result.status === 'duplicate') {
                        CertiSub.notify(t('registration.upload_duplicate'), 'error');
                    } else if (result.status === 'skipped') {
                        CertiSub.notify(t('registration.upload_skipped'), 'error');
                    } else {
                        CertiSub.notify(t('registration.uploaded'));
                        CertiSub.navigate('registrations', result.draft_id);
                        return;
                    }
                    await this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.busy = false;
                    refreshPending();
                }
            },
            async fetchMail() {
                this.busy = true;
                try {
                    const data = await api.post(endpoints.registrations, { action: 'fetch' });
                    CertiSub.notify(t('registration.fetch_done', data.fetch));
                    await this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.busy = false;
                    refreshPending();
                }
            },
        },
        template: `
            <section>
                <PageHeader :title="t('registration.title')" :description="t('registration.description')">
                    <button v-if="can('registrations.intake') && pending.imap" type="button" class="btn-secondary" :disabled="busy" @click="fetchMail">📥 {{ t('registration.fetch') }}</button>
                    <button v-if="can('registrations.intake')" type="button" class="btn-primary" :disabled="busy" :title="t('registration.upload_hint')" @click="pickFile">+ {{ t('registration.upload') }}</button>
                    <input ref="file" type="file" accept=".eml,message/rfc822" class="hidden" @change="upload">
                </PageHeader>

                <div class="card p-3 mb-4 flex flex-wrap items-center gap-2">
                    <button v-for="name in tabs" :key="name" type="button"
                            :class="['btn-ghost', tab === name ? 'font-semibold text-brand-700' : '']" @click="tab = name">
                        {{ t('registration.tab.' + name) }}
                        <span v-if="name === 'pending' && pending.count" class="ml-1 text-xs bg-red-500 text-white px-1.5 py-0.5 rounded-full">{{ pending.count }}</span>
                    </button>
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[900px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('registration.column.message') }}</th>
                                <th class="th">{{ t('registration.column.person') }}</th>
                                <th class="th">{{ t('registration.column.company') }}</th>
                                <th class="th">{{ t('registration.column.certificate') }}</th>
                                <th class="th">{{ t('registration.column.received') }}</th>
                                <th class="th">{{ t('registration.column.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="open(item)">
                                <td class="td">
                                    <div class="font-medium text-slate-800">{{ item.subject || '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ item.sender_name || item.sender_email }} · {{ t('registration.source.' + item.source) }}</div>
                                </td>
                                <td class="td">{{ item.person_name || '—' }}</td>
                                <td class="td">
                                    <div>{{ item.company_name || '—' }}</div>
                                    <div class="text-xs text-slate-500">
                                        <span v-if="item.tax_id" class="font-mono">{{ item.tax_id }}</span>
                                        <span :class="['badge ml-1', item.company_mode === 'existing' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700']">{{ item.company_mode === 'existing' ? t('registration.company_existing') : t('registration.company_new') }}</span>
                                    </div>
                                </td>
                                <td class="td">{{ item.certificate_type ? labels.type(item.certificate_type) : '—' }}</td>
                                <td class="td whitespace-nowrap">{{ format.dateTime(item.received_at || item.created_at) }}</td>
                                <td class="td">
                                    <span :class="['badge', statusBadge(item.status)]">{{ t('registration.status.' + item.status) }}</span>
                                    <div v-if="item.status === 'pending'" class="text-xs mt-1">
                                        <span v-if="item.blocking" class="text-red-600">{{ t('registration.blocking', { count: item.blocking }) }}</span>
                                        <span v-if="item.warnings" class="text-amber-700"> {{ t('registration.warnings', { count: item.warnings }) }}</span>
                                        <div v-if="item.assigned_name" class="text-slate-500">{{ t('registration.assigned_to', { name: item.assigned_name }) }}</div>
                                    </div>
                                </td>
                            </tr>
                            <TableState :colspan="6" :loading="loading && items.length === 0" :error="error"
                                        :empty="!loading && items.length === 0" :empty-text="t('registration.empty')" />
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-400 mt-3">{{ t('registration.channels') }}</p>
            </section>
        `,
    };

    CertiSub.components.RegistrationReviewView = {
        props: {
            id: { type: Number, required: true },
        },
        data() {
            return {
                reg: null,
                form: null,
                loading: true,
                error: '',
                saving: false,
                errors: {},
                message: '',
                rejectOpen: false,
                rejectReason: '',
            };
        },
        computed: {
            editable() {
                return Boolean(this.reg && this.reg.can_review);
            },
            matchedPayer() {
                return this.reg ? this.reg.matched_payer : null;
            },
            matchedPerson() {
                return this.reg ? this.reg.matched_beneficiary : null;
            },
            companyExisting() {
                return this.form && this.form.company.mode === 'existing';
            },
            personExisting() {
                return this.form && this.form.person.mode === 'existing';
            },
            lookup() {
                return this.reg ? this.reg.company_lookup : null;
            },
            lookupBanner() {
                const lookup = this.lookup;
                if (!lookup) {
                    return null;
                }
                if (lookup.status === 'found') {
                    return { level: 'info', text: t('registration.company.registry_found', { date: CertiSub.format.dateTime(lookup.checked_at), status: lookup.record.status_vat || '—' }) };
                }
                if (lookup.status === 'not_found') {
                    return { level: 'warning', text: t('registration.company.registry_not_found') };
                }
                return { level: 'warning', text: t('registration.company.registry_error', { message: lookup.message || '' }) };
            },
            discountPresets() {
                return ['0', '-3', '-5', '-10', '-15', '-20'];
            },
            warnings() {
                return this.reg ? this.reg.warnings : [];
            },
            blocking() {
                return this.warnings.filter((warning) => warning.level === 'error').length;
            },
        },
        mounted() {
            this.load();
        },
        methods: {
            levelStyle: (level) => LEVEL_STYLE[level] || LEVEL_STYLE.info,
            warningText,
            err(section, field) {
                return this.errors[section + '.' + field] || '';
            },
            async load() {
                this.loading = true;
                this.error = '';
                try {
                    const data = await api.get(endpoints.registrations, { id: this.id });
                    this.setRegistration(data.registration);
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            setRegistration(reg) {
                this.reg = reg;
                this.form = JSON.parse(JSON.stringify(reg.form));
            },
            back() {
                CertiSub.navigate('registrations');
            },
            async call(body) {
                const data = await api.post(endpoints.registrations, Object.assign({ id: this.id }, body));
                this.setRegistration(data.registration);
                return data.registration;
            },
            async save(notify) {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    await this.call({ action: 'save', data: this.form });
                    if (notify !== false) {
                        CertiSub.notify(t('registration.saved'));
                    }
                    return true;
                } catch (error) {
                    this.message = error.message;
                    return false;
                } finally {
                    this.saving = false;
                }
            },
            async claim() {
                try {
                    await this.call({ action: 'claim' });
                    CertiSub.notify(t('registration.claimed_ok'));
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            async refreshCompany() {
                this.errors = {};
                this.message = '';
                if (!(await this.save(false))) {
                    return;
                }
                this.saving = true;
                try {
                    await this.call({ action: 'refresh_company', tax_id: this.form.company.tax_id });
                    CertiSub.notify(t('registration.company.refreshed'));
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                } finally {
                    this.saving = false;
                }
            },
            async approve() {
                const company = this.companyExisting
                    ? t('registration.approve_company_existing', { name: this.matchedPayer && this.matchedPayer.company_name ? this.matchedPayer.company_name : this.form.company.company_name })
                    : t('registration.approve_company_new', { name: this.form.company.company_name });
                const ok = await CertiSub.confirm({
                    title: t('registration.approve_title'),
                    message: t('registration.approve_confirm', { company, certificate: this.form.certificate.name }),
                    confirmLabel: t('registration.approve'),
                });
                if (!ok) {
                    return;
                }
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    await this.call({ action: 'approve', data: this.form });
                    CertiSub.notify(t('registration.approved_ok'));
                    CertiSub.data.refresh('certificates', 'beneficiaries', 'payers', 'summary', 'options');
                    CertiSub.refreshRegistrationCount();
                    window.scrollTo(0, 0);
                } catch (error) {
                    this.errors = error.errors || {};
                    this.message = error.message;
                } finally {
                    this.saving = false;
                }
            },
            async reject() {
                this.saving = true;
                try {
                    await this.call({ action: 'reject', reason: this.rejectReason });
                    this.rejectOpen = false;
                    CertiSub.notify(t('registration.rejected_ok'));
                    CertiSub.refreshRegistrationCount();
                } catch (error) {
                    CertiSub.notifyError(error);
                } finally {
                    this.saving = false;
                }
            },
            askInfo() {
                const person = [this.form.person.first_name, this.form.person.last_name].filter(Boolean).join(' ') || this.reg.message.sender_email || '#' + this.id;
                CertiSub.openComposer({
                    type: 'request',
                    subject: t('registration.ask_info_subject', { name: person }),
                    related_type: 'registration',
                    related_id: this.id,
                    related_label: this.reg.message.subject || '#' + this.id,
                });
            },
            openCertificate() {
                CertiSub.openDrawer('certificate', this.reg.result.certificate_id);
            },
            openCompany() {
                CertiSub.openDrawer('payer', this.reg.result.payer_id);
            },
            openPerson() {
                CertiSub.openDrawer('beneficiary', this.reg.result.beneficiary_id);
            },
            setDiscount(value) {
                this.form.certificate.discount_percent = value;
            },
        },
        template: `
            <section>
                <button type="button" class="no-print text-sm text-brand-700 hover:underline mb-4" @click="back">← {{ t('registration.review.back') }}</button>

                <div v-if="loading" class="space-y-4" role="status" :aria-label="t('common.loading')">
                    <div class="skeleton h-10 w-1/2"></div><div class="skeleton h-64 w-full"></div>
                </div>
                <p v-else-if="error" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</p>

                <template v-else-if="reg && form">
                    <PageHeader :title="t('registration.review.title')" :description="reg.message.subject || ''">
                        <span :class="['badge', reg.status === 'approved' ? 'bg-emerald-100 text-emerald-800' : (reg.status === 'rejected' ? 'bg-slate-200 text-slate-600' : 'bg-amber-100 text-amber-800')]">{{ t('registration.status.' + reg.status) }}</span>
                        <button v-if="editable" type="button" class="btn-secondary" @click="askInfo">📨 {{ t('registration.ask_info') }}</button>
                        <button v-if="editable && reg.assigned_user_id !== CertiSub.boot.user.id" type="button" class="btn-secondary" @click="claim">✋ {{ t('registration.claim') }}</button>
                        <button v-if="editable" type="button" class="btn-secondary" :disabled="saving" @click="save()">💾 {{ t('registration.save') }}</button>
                        <button v-if="editable" type="button" class="btn-secondary text-red-700" :disabled="saving" @click="rejectOpen = !rejectOpen">{{ t('registration.reject') }}</button>
                        <button v-if="editable" type="button" class="btn-primary" :disabled="saving || blocking > 0" @click="approve">✓ {{ t('registration.approve') }}</button>
                    </PageHeader>

                    <div v-if="reg.assigned_name && editable" class="mb-3 text-xs text-slate-500">{{ t('registration.assigned_to', { name: reg.assigned_name }) }}</div>
                    <div v-if="message" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ message }}</div>

                    <div v-if="rejectOpen" class="card p-4 mb-4">
                        <h4 class="font-semibold text-slate-800 mb-2">{{ t('registration.reject_title') }}</h4>
                        <FormField :label="t('registration.reject_reason')">
                            <input v-model="rejectReason" type="text" maxlength="500" class="input">
                        </FormField>
                        <div class="flex justify-end gap-2 mt-3">
                            <button type="button" class="btn-secondary" @click="rejectOpen = false">{{ t('common.cancel') }}</button>
                            <button type="button" class="btn-danger" :disabled="saving" @click="reject">{{ t('registration.reject') }}</button>
                        </div>
                    </div>

                    <div v-if="reg.status !== 'pending'" class="mb-4 px-4 py-3 rounded-lg border text-sm" :class="reg.status === 'approved' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-slate-100 border-slate-200 text-slate-700'">
                        <p>{{ t('registration.reviewed_by', { name: reg.reviewed_by_name || '—', date: format.dateTime(reg.reviewed_at) }) }}</p>
                        <p v-if="reg.rejection_reason" class="mt-1">{{ t('registration.rejection_reason', { reason: reg.rejection_reason }) }}</p>
                        <p class="mt-1 text-xs opacity-80">{{ t('registration.read_only') }}</p>
                        <div v-if="reg.status === 'approved'" class="flex flex-wrap gap-2 mt-3">
                            <button v-if="reg.result.certificate_id" type="button" class="btn-secondary" @click="openCertificate">📜 {{ t('registration.open_certificate') }}</button>
                            <button v-if="reg.result.payer_id" type="button" class="btn-secondary" @click="openCompany">🏢 {{ t('registration.open_company') }}</button>
                            <button v-if="reg.result.beneficiary_id" type="button" class="btn-secondary" @click="openPerson">👤 {{ t('registration.open_person') }}</button>
                        </div>
                    </div>

                    <div class="grid xl:grid-cols-3 gap-4">
                        <div class="space-y-4 xl:col-span-1">
                            <div v-if="warnings.length && reg.status === 'pending'" class="card p-4">
                                <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">{{ t('registration.section.warnings') }}</h4>
                                <ul class="space-y-2">
                                    <li v-for="(warning, index) in warnings" :key="index" :class="['px-3 py-2 rounded-lg border text-sm', levelStyle(warning.level)]">{{ warningText(warning) }}</li>
                                </ul>
                            </div>

                            <div class="card p-4">
                                <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">✉️ {{ t('registration.section.message') }}</h4>
                                <dl class="text-sm space-y-1 mb-3">
                                    <div><dt class="inline text-slate-500">{{ t('registration.message.from') }}: </dt><dd class="inline">{{ reg.message.sender_name }} &lt;{{ reg.message.sender_email }}&gt;</dd></div>
                                    <div><dt class="inline text-slate-500">{{ t('registration.message.subject') }}: </dt><dd class="inline">{{ reg.message.subject }}</dd></div>
                                    <div><dt class="inline text-slate-500">{{ t('registration.message.received') }}: </dt><dd class="inline">{{ format.dateTime(reg.message.received_at) }} · {{ t('registration.source.' + reg.source) }}</dd></div>
                                </dl>
                                <pre class="text-xs bg-slate-50 border border-slate-200 rounded-lg p-3 whitespace-pre-wrap break-words max-h-96 overflow-auto">{{ reg.message.body_text }}</pre>
                                <p v-if="reg.message.stored" class="text-xs text-slate-400 mt-2">{{ t('registration.message.stored') }}</p>
                            </div>
                        </div>

                        <fieldset :disabled="!editable" class="space-y-4 xl:col-span-2 min-w-0">
                            <div class="card p-5">
                                <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">🏢 {{ t('registration.section.company') }}</h4>

                                <template v-if="matchedPayer && !(reg.status === 'approved' && form.company.mode !== 'existing')">
                                    <div class="px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-sm text-emerald-900">
                                        <strong v-if="matchedPayer.visible">{{ t('registration.company.use_existing', { name: matchedPayer.company_name }) }}</strong>
                                        <strong v-else>{{ t('registration.company.use_existing_hidden') }}</strong>
                                        <span v-if="matchedPayer.visible && matchedPayer.tax_id" class="font-mono ml-2">NIP {{ matchedPayer.tax_id }}</span>
                                        <p class="text-xs mt-1">{{ t('registration.company.existing_note') }}</p>
                                    </div>
                                    <div v-if="editable" class="mt-3">
                                        <FormField :label="t('field.tax_id')" :error="err('company', 'tax_id')">
                                            <div class="flex gap-2">
                                                <input v-model="form.company.tax_id" type="text" maxlength="20" class="input font-mono">
                                                <button type="button" class="btn-secondary shrink-0" :disabled="saving" @click="refreshCompany">🔎 {{ t('registration.company.refresh') }}</button>
                                            </div>
                                        </FormField>
                                    </div>
                                </template>

                                <template v-else>
                                    <div v-if="lookupBanner" :class="['mb-3 px-3 py-2 rounded-lg border text-sm', levelStyle(lookupBanner.level)]">{{ lookupBanner.text }}</div>
                                    <div class="grid md:grid-cols-2 gap-4">
                                        <FormField class="md:col-span-2" :label="t('payer.company_name')" :error="err('company', 'company_name')" required>
                                            <input v-model="form.company.company_name" type="text" maxlength="255" :class="['input', err('company', 'company_name') ? 'input-error' : '']">
                                        </FormField>
                                        <FormField :label="t('field.tax_id')" :error="err('company', 'tax_id')" :hint="t('payer.hint.tax_id')">
                                            <div class="flex gap-2">
                                                <input v-model="form.company.tax_id" type="text" maxlength="20" :class="['input font-mono', err('company', 'tax_id') ? 'input-error' : '']">
                                                <button v-if="editable" type="button" class="btn-secondary shrink-0" :disabled="saving" :title="t('registration.company.refresh')" @click="refreshCompany">🔎</button>
                                            </div>
                                        </FormField>
                                        <FormField :label="t('field.contact_person')" :error="err('company', 'contact_person')" required>
                                            <input v-model="form.company.contact_person" type="text" maxlength="200" :class="['input', err('company', 'contact_person') ? 'input-error' : '']">
                                        </FormField>
                                        <FormField :label="t('field.email')" :error="err('company', 'email')">
                                            <input v-model="form.company.email" type="email" maxlength="255" :class="['input', err('company', 'email') ? 'input-error' : '']">
                                        </FormField>
                                        <FormField :label="t('field.phone')" :error="err('company', 'phone')">
                                            <input v-model="form.company.phone" type="tel" maxlength="50" :class="['input', err('company', 'phone') ? 'input-error' : '']">
                                        </FormField>
                                        <FormField class="md:col-span-2" :label="t('field.address_line')" :error="err('company', 'address_line')">
                                            <input v-model="form.company.address_line" type="text" maxlength="255" class="input">
                                        </FormField>
                                        <FormField :label="t('field.postal_code')" :error="err('company', 'postal_code')">
                                            <input v-model="form.company.postal_code" type="text" maxlength="16" :class="['input', err('company', 'postal_code') ? 'input-error' : '']">
                                        </FormField>
                                        <FormField :label="t('field.city')" :error="err('company', 'city')">
                                            <input v-model="form.company.city" type="text" maxlength="120" class="input">
                                        </FormField>
                                    </div>
                                </template>
                                <p v-if="err('company', 'payer_id')" class="text-xs text-red-600 mt-2">{{ err('company', 'payer_id') }}</p>
                            </div>

                            <div class="card p-5">
                                <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">👤 {{ t('registration.section.person') }}</h4>
                                <div v-if="matchedPerson && matchedPerson.visible" class="mb-3 space-y-1 text-sm">
                                    <label class="flex items-center gap-2"><input v-model="form.person.mode" type="radio" value="existing" @change="form.person.beneficiary_id = matchedPerson.id"> {{ t('registration.person.use_existing', { name: matchedPerson.name }) }}</label>
                                    <label class="flex items-center gap-2"><input v-model="form.person.mode" type="radio" value="new"> {{ t('registration.person.create_new') }}</label>
                                </div>
                                <div v-if="!personExisting" class="grid md:grid-cols-2 gap-4">
                                    <FormField :label="t('field.first_name')" :error="err('person', 'first_name')" required>
                                        <input v-model="form.person.first_name" type="text" maxlength="100" :class="['input', err('person', 'first_name') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.last_name')" :error="err('person', 'last_name')" required>
                                        <input v-model="form.person.last_name" type="text" maxlength="100" :class="['input', err('person', 'last_name') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.email')" :error="err('person', 'email')">
                                        <input v-model="form.person.email" type="email" maxlength="255" :class="['input', err('person', 'email') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.phone')" :error="err('person', 'phone')">
                                        <input v-model="form.person.phone" type="tel" maxlength="50" :class="['input', err('person', 'phone') ? 'input-error' : '']">
                                    </FormField>
                                </div>
                                <p v-if="err('person', 'beneficiary_id')" class="text-xs text-red-600 mt-2">{{ err('person', 'beneficiary_id') }}</p>
                            </div>

                            <div class="card p-5">
                                <h4 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">📜 {{ t('registration.section.certificate') }}</h4>
                                <div class="grid md:grid-cols-2 gap-4">
                                    <FormField class="md:col-span-2" :label="t('field.name')" :error="err('certificate', 'name')" required>
                                        <input v-model="form.certificate.name" type="text" maxlength="255" :class="['input', err('certificate', 'name') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.certificate_type')" :error="err('certificate', 'certificate_type')" required>
                                        <select v-model="form.certificate.certificate_type" class="input">
                                            <option v-for="type in labels.types" :key="type" :value="type">{{ labels.type(type) }}</option>
                                        </select>
                                    </FormField>
                                    <FormField :label="t('field.status')" :error="err('certificate', 'status')">
                                        <select v-model="form.certificate.status" class="input">
                                            <option v-for="status in labels.statuses" :key="status" :value="status">{{ labels.status(status) }}</option>
                                        </select>
                                    </FormField>
                                    <FormField :label="t('field.serial_number')" :error="err('certificate', 'serial_number')" :hint="t('certificate.hint.serial')">
                                        <input v-model="form.certificate.serial_number" type="text" maxlength="128" :class="['input font-mono', err('certificate', 'serial_number') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.issuer')" :error="err('certificate', 'issuer')">
                                        <input v-model="form.certificate.issuer" type="text" maxlength="255" class="input" :placeholder="t('certificate.placeholder.issuer')">
                                    </FormField>
                                    <FormField :label="t('field.valid_from')" :error="err('certificate', 'valid_from')">
                                        <input v-model="form.certificate.valid_from" type="date" :class="['input', err('certificate', 'valid_from') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.expiry_date')" :error="err('certificate', 'expiry_date')" required>
                                        <input v-model="form.certificate.expiry_date" type="date" :class="['input', err('certificate', 'expiry_date') ? 'input-error' : '']">
                                    </FormField>
                                    <FormField :label="t('field.renewal_lead_days')" :error="err('certificate', 'renewal_lead_days')" :hint="t('certificate.hint.lead_days')">
                                        <input v-model="form.certificate.renewal_lead_days" type="number" min="0" max="3650" class="input">
                                    </FormField>
                                    <FormField :label="t('field.discount_percent')" :error="err('certificate', 'discount_percent')" :hint="t('registration.form.discount_hint')">
                                        <input v-model="form.certificate.discount_percent" type="text" inputmode="decimal" maxlength="8" :class="['input', err('certificate', 'discount_percent') ? 'input-error' : '']">
                                        <div class="flex flex-wrap gap-1 mt-2">
                                            <button v-for="preset in discountPresets" :key="'reg-' + preset" type="button" :disabled="!editable"
                                                    :class="['badge cursor-pointer', form.certificate.discount_percent === preset ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-slate-600 hover:bg-slate-200']"
                                                    @click="setDiscount(preset)">{{ preset === '0' ? '0%' : preset + '%' }}</button>
                                        </div>
                                    </FormField>
                                    <FormField :label="t('field.billing_cycle')" :error="err('certificate', 'billing_cycle')">
                                        <select v-model="form.certificate.billing_cycle" class="input">
                                            <option v-for="cycle in labels.billingCycles" :key="cycle" :value="cycle">{{ labels.billing(cycle) }}</option>
                                        </select>
                                    </FormField>
                                    <FormField :label="t('field.payment_status')" :error="err('certificate', 'payment_status')">
                                        <select v-model="form.certificate.payment_status" class="input">
                                            <option v-for="status in labels.paymentStatuses" :key="status" :value="status">{{ labels.payment(status) }}</option>
                                        </select>
                                    </FormField>
                                    <FormField class="md:col-span-2" :label="t('field.notes')" :error="err('certificate', 'notes')">
                                        <textarea v-model="form.certificate.notes" rows="3" maxlength="5000" class="input"></textarea>
                                    </FormField>
                                </div>
                                <p v-if="err('certificate', 'beneficiary_id') || err('certificate', 'payer_id')" class="text-xs text-red-600 mt-2">{{ err('certificate', 'beneficiary_id') || err('certificate', 'payer_id') }}</p>
                            </div>
                        </fieldset>
                    </div>

                    <div v-if="editable" class="flex flex-wrap justify-end gap-2 mt-4 no-print">
                        <button type="button" class="btn-secondary" :disabled="saving" @click="save()">💾 {{ t('registration.save') }}</button>
                        <button type="button" class="btn-primary" :disabled="saving || blocking > 0" @click="approve">✓ {{ t('registration.approve') }}</button>
                    </div>
                </template>
            </section>
        `,
    };
})(window.CertiSub);
