/*
 * Wymiana danych (F9, F10): eksport list do CSV i XML (MANAGER, ADMIN), import płatników,
 * użytkowników certyfikatów i certyfikatów z CSV/XML z podglądem przed zapisem oraz import
 * wiadomości e-mail (EML) z rozpoznaniem nadawcy, certyfikatów i załączników (ADMIN).
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints, format } = CertiSub;

    const IMPORT_DATASETS = ['payers', 'beneficiaries', 'certificates'];

    const STATUS_BADGES = {
        create: 'bg-emerald-100 text-emerald-800',
        update: 'bg-blue-100 text-blue-800',
        unchanged: 'bg-slate-100 text-slate-600',
        skip: 'bg-slate-100 text-slate-600',
        error: 'bg-red-100 text-red-800',
    };

    const LIST_VIEWS = { payers: 'payers', beneficiaries: 'beneficiaries', certificates: 'certificates' };

    function fileSize(bytes) {
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function emptyEmlActions() {
        return {
            create: false,
            person: { first_name: '', last_name: '', email: '', phone: '', payer_id: '' },
            responded: [],
            certificates: [],
            beneficiaries: [],
            payers: [],
        };
    }

    CertiSub.components.ExchangeView = {
        data() {
            return {
                importDatasets: IMPORT_DATASETS,
                exportArchived: { certificates: false, beneficiaries: false, payers: false },
                dataset: 'payers',
                mode: 'skip',
                file: null,
                attachment: null,
                columns: [],
                preview: null,
                result: null,
                busy: '',
                error: '',
                rowFilter: 'all',
                emlFile: null,
                eml: null,
                emlBusy: false,
                emlError: '',
                emlActions: emptyEmlActions(),
                emlResult: null,
                payerOptions: [],
            };
        },
        computed: {
            exportDatasets() {
                return [
                    { id: 'certificates', icon: '📜', label: t('certificate.plural'), archive: true },
                    { id: 'beneficiaries', icon: '👤', label: t('beneficiary.plural'), archive: true },
                    { id: 'payers', icon: '🏢', label: t('payer.plural'), archive: true },
                    { id: 'tasks', icon: '✅', label: t('nav.todo') },
                    { id: 'invitations', icon: '✉️', label: t('nav.invitations') },
                    { id: 'events', icon: '🧾', label: t('nav.events'), permission: 'events.view_all' },
                ].filter((item) => !item.permission || CertiSub.can(item.permission));
            },
            visibleRows() {
                if (!this.preview) {
                    return [];
                }
                return this.preview.rows.filter((row) => {
                    if (this.rowFilter === 'error') {
                        return row.status === 'error';
                    }
                    if (this.rowFilter === 'import') {
                        return row.status === 'create' || row.status === 'update';
                    }
                    return true;
                });
            },
            importable() {
                return this.preview ? this.preview.totals.create + this.preview.totals.update : 0;
            },
            fileName() {
                if (!this.file) {
                    return '';
                }
                return this.attachment !== null ? t('exchange.import.from_eml', { file: this.file.name, index: this.attachment + 1 }) : this.file.name;
            },
            emlHasActions() {
                const a = this.emlActions;
                return a.create || a.responded.length > 0 || a.certificates.length > 0 || a.beneficiaries.length > 0 || a.payers.length > 0;
            },
            emlHeader() {
                if (!this.eml) {
                    return [];
                }
                const message = this.eml.message;
                const address = (item) => (item.name ? item.name + ' <' + (item.email || '?') + '>' : (item.email || '—'));
                return [
                    { label: t('exchange.eml.from'), value: address(message.from) },
                    { label: t('exchange.eml.to'), value: message.to.map(address).join(', ') },
                    { label: t('exchange.eml.date'), value: message.date ? format.dateTime(message.date) : '—' },
                    { label: t('exchange.eml.subject'), value: message.subject, wide: true },
                ];
            },
            emlSenderPayers() {
                if (!this.eml) {
                    return [];
                }
                const seen = {};
                return this.eml.sender.payers.concat(this.eml.payers).filter((payer) => {
                    if (seen[payer.id]) {
                        return false;
                    }
                    seen[payer.id] = true;
                    return true;
                });
            },
        },
        watch: {
            dataset() {
                this.loadColumns();
                this.resetPreview();
            },
            mode() {
                this.resetPreview();
            },
        },
        mounted() {
            if (CertiSub.can('import.run')) {
                this.loadColumns();
            }
        },
        methods: {
            badgeClass: (status) => STATUS_BADGES[status] || STATUS_BADGES.skip,
            fileSize,
            async loadColumns() {
                try {
                    const data = await api.get(endpoints.import, { view: 'columns', dataset: this.dataset });
                    this.columns = data.columns;
                } catch (error) {
                    this.columns = [];
                }
            },
            async downloadTemplate(fileFormat) {
                try {
                    await CertiSub.download(endpoints.export, { dataset: this.dataset, format: fileFormat, template: 1 });
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
            onFile(event) {
                this.file = event.target.files[0] || null;
                this.attachment = null;
                this.resetPreview();
            },
            resetPreview() {
                this.preview = null;
                this.result = null;
                this.error = '';
                this.rowFilter = 'all';
            },
            formData(action) {
                const data = new FormData();
                data.append('action', action);
                data.append('dataset', this.dataset);
                data.append('mode', this.mode);
                if (this.attachment !== null) {
                    data.append('attachment', String(this.attachment));
                }
                data.append('file', this.file);
                return data;
            },
            async runPreview() {
                if (!this.file) {
                    return;
                }
                this.busy = 'preview';
                this.error = '';
                this.result = null;
                try {
                    const data = await api.post(endpoints.import, this.formData('preview'));
                    this.preview = data.import;
                    this.rowFilter = 'all';
                    this.$nextTick(() => this.$refs.preview && this.$refs.preview.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                } catch (error) {
                    this.error = (error.errors && error.errors.file) || error.message;
                } finally {
                    this.busy = '';
                }
            },
            async runCommit() {
                if (this.preview.totals.error > 0) {
                    const ok = await CertiSub.confirm({
                        title: t('exchange.import.confirm_title'),
                        message: t('exchange.import.confirm_errors', { count: this.importable, errors: this.preview.totals.error }),
                        confirmLabel: t('exchange.import.commit', { count: this.importable }),
                    });
                    if (!ok) {
                        return;
                    }
                }
                this.busy = 'commit';
                this.error = '';
                try {
                    const data = await api.post(endpoints.import, this.formData('commit'));
                    this.result = data.import;
                    this.preview = null;
                    CertiSub.notify(t('exchange.result.done', { created: this.result.totals.create, updated: this.result.totals.update }));
                    CertiSub.data.refresh('certificates', 'beneficiaries', 'payers', 'summary', 'options', 'taskStats');
                } catch (error) {
                    this.error = (error.errors && error.errors.file) || error.message;
                } finally {
                    this.busy = '';
                }
            },
            openList(dataset) {
                CertiSub.navigate(LIST_VIEWS[dataset] || 'dashboard');
            },
            onEmlFile(event) {
                this.emlFile = event.target.files[0] || null;
                this.eml = null;
                this.emlResult = null;
                this.emlError = '';
            },
            async analyzeEml() {
                if (!this.emlFile) {
                    return;
                }
                this.emlBusy = true;
                this.emlError = '';
                this.emlResult = null;
                try {
                    const data = new FormData();
                    data.append('action', 'eml_analyze');
                    data.append('file', this.emlFile);
                    const response = await api.post(endpoints.import, data);
                    this.eml = response.eml;
                    const actions = emptyEmlActions();
                    const suggestion = this.eml.sender.suggestion;
                    if (suggestion) {
                        actions.person = {
                            first_name: suggestion.first_name || '',
                            last_name: suggestion.last_name || '',
                            email: suggestion.email || '',
                            phone: suggestion.phone || '',
                            payer_id: this.emlSenderPayers.length ? String(this.emlSenderPayers[0].id) : '',
                        };
                        if (this.payerOptions.length === 0) {
                            const options = await api.get(endpoints.payers, { view: 'options' });
                            this.payerOptions = options.options;
                        }
                    }
                    actions.certificates = this.eml.certificates.map((item) => item.id);
                    actions.beneficiaries = this.eml.sender.beneficiaries.map((item) => item.id);
                    this.emlActions = actions;
                } catch (error) {
                    this.eml = null;
                    this.emlError = (error.errors && error.errors.file) || error.message;
                } finally {
                    this.emlBusy = false;
                }
            },
            async applyEml() {
                this.emlBusy = true;
                this.emlError = '';
                try {
                    const a = this.emlActions;
                    const actions = {
                        create_beneficiary: a.create ? Object.assign({}, a.person, { payer_id: a.person.payer_id || null }) : null,
                        responded: a.responded,
                        note_certificates: a.certificates,
                        note_beneficiaries: a.beneficiaries,
                        note_payers: a.payers,
                    };
                    const data = new FormData();
                    data.append('action', 'eml_apply');
                    data.append('file', this.emlFile);
                    data.append('actions', JSON.stringify(actions));
                    const response = await api.post(endpoints.import, data);
                    this.emlResult = response.result;
                    CertiSub.notify(t('exchange.eml.applied'));
                    CertiSub.data.refresh('beneficiaries', 'payers', 'invitations', 'options');
                } catch (error) {
                    const details = error.errors ? Object.values(error.errors).join(' ') : '';
                    this.emlError = details ? error.message + ' ' + details : error.message;
                } finally {
                    this.emlBusy = false;
                }
            },
            importAttachment(attachment) {
                this.file = this.emlFile;
                this.attachment = attachment.index;
                if (attachment.dataset && attachment.dataset !== this.dataset) {
                    this.dataset = attachment.dataset;
                }
                this.$nextTick(() => {
                    if (this.$refs.importSection) {
                        this.$refs.importSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    this.runPreview();
                });
            },
            openBeneficiary: (id) => CertiSub.openBeneficiaryCard(id),
            openPayer: (id) => CertiSub.openPayerCard(id),
            openCertificate: (id) => CertiSub.openDrawer('certificate', id),
            openInvitation: (id) => CertiSub.openDrawer('invitation', id),
        },
        template: `
            <section>
                <PageHeader :title="t('exchange.title')" :description="t('exchange.description')" />

                <ReportSection :title="t('exchange.export.title')" :description="t('exchange.export.description')">
                    <div class="p-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div v-for="item in exportDatasets" :key="item.id" class="border border-slate-200 rounded-lg p-4 flex flex-col gap-3">
                            <div class="font-medium text-slate-800">{{ item.icon }} {{ item.label }}</div>
                            <label v-if="item.archive" class="flex items-center gap-2 text-xs text-slate-600">
                                <input v-model="exportArchived[item.id]" type="checkbox"> {{ t('exchange.export.archived_only') }}
                            </label>
                            <div class="mt-auto">
                                <ExportButtons :dataset="item.id" :params="item.archive && exportArchived[item.id] ? { archived: 1 } : {}" />
                            </div>
                        </div>
                    </div>
                    <p class="px-5 pb-5 text-xs text-slate-500">{{ t('exchange.export.hint') }}</p>
                </ReportSection>

                <div v-if="can('import.run')" ref="importSection">
                    <ReportSection :title="t('exchange.import.title')" :description="t('exchange.import.description')">
                        <div class="p-5 grid gap-6 lg:grid-cols-5">
                            <div class="lg:col-span-2 space-y-4">
                                <FormField :label="t('exchange.import.dataset')" :hint="t('exchange.import.order_hint')">
                                    <select v-model="dataset" class="input">
                                        <option v-for="(id, index) in importDatasets" :key="id" :value="id">{{ index + 1 }}. {{ t('exchange.dataset.' + id) }}</option>
                                    </select>
                                </FormField>
                                <fieldset>
                                    <legend class="block text-sm font-medium text-slate-700 mb-2">{{ t('exchange.import.mode') }}</legend>
                                    <label v-for="value in ['skip', 'update']" :key="value" class="flex items-start gap-2 text-sm mb-2">
                                        <input v-model="mode" type="radio" name="import-mode" :value="value" class="mt-1">
                                        <span>{{ t('exchange.import.mode_' + value) }}<span class="block text-xs text-slate-500">{{ t('exchange.import.mode_' + value + '_hint') }}</span></span>
                                    </label>
                                </fieldset>
                                <FormField :label="t('exchange.import.file')" :hint="t('exchange.import.file_hint')">
                                    <input type="file" accept=".csv,.xml,.txt" class="block w-full text-sm text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700" @change="onFile">
                                </FormField>
                                <p v-if="fileName" class="text-xs text-slate-600 break-words">📄 {{ fileName }}</p>
                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="button" class="btn-primary" :disabled="!file || busy !== ''" @click="runPreview">{{ busy === 'preview' ? t('exchange.import.checking') : t('exchange.import.check') }}</button>
                                    <span class="text-xs text-slate-500">
                                        {{ t('exchange.import.template') }}:
                                        <button type="button" class="text-brand-700 hover:underline" @click="downloadTemplate('csv')">CSV</button> ·
                                        <button type="button" class="text-brand-700 hover:underline" @click="downloadTemplate('xml')">XML</button>
                                    </span>
                                </div>
                            </div>
                            <div class="lg:col-span-3">
                                <h4 class="text-sm font-semibold text-slate-700 mb-2">{{ t('exchange.import.columns', { dataset: t('exchange.dataset.' + dataset) }) }}</h4>
                                <div class="overflow-x-auto border border-slate-200 rounded-lg">
                                    <table class="w-full text-xs">
                                        <thead class="bg-slate-50 border-b border-slate-200">
                                            <tr>
                                                <th class="th">{{ t('exchange.import.column') }}</th>
                                                <th class="th">{{ t('exchange.import.xml_name') }}</th>
                                                <th class="th">{{ t('exchange.import.required') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="column in columns" :key="column.field" class="border-b border-slate-100">
                                                <td class="px-4 py-1.5 text-slate-800">{{ column.label }}</td>
                                                <td class="px-4 py-1.5 font-mono text-slate-500">{{ column.field }}</td>
                                                <td class="px-4 py-1.5">{{ column.required ? '✔' : '' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="text-xs text-slate-500 mt-2">{{ t('exchange.import.columns_hint') }}</p>
                            </div>
                        </div>

                        <div v-if="error" class="mx-5 mb-5 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ error }}</div>

                        <div v-if="preview" ref="preview" class="border-t border-slate-100 p-5">
                            <h4 class="font-semibold text-slate-900">{{ t('exchange.preview.title') }}</h4>
                            <p class="text-xs text-slate-500 mt-0.5 mb-3 break-words">
                                {{ preview.file_name }} · {{ preview.format.toUpperCase() }}
                                <template v-if="preview.format === 'csv'"> · {{ preview.encoding }} · {{ t('exchange.preview.delimiter', { delimiter: preview.delimiter }) }}</template>
                                · {{ t('exchange.import.mode_' + preview.mode) }}
                            </p>
                            <div class="flex flex-wrap gap-1.5 mb-4">
                                <span v-for="column in preview.columns.mapped" :key="column.header" class="badge bg-emerald-50 text-emerald-800 border border-emerald-200">✓ {{ column.header === column.label ? column.label : column.header + ' → ' + column.label }}</span>
                                <span v-for="header in preview.columns.ignored" :key="'i' + header" class="badge bg-slate-100 text-slate-500 border border-slate-200" :title="t('exchange.preview.ignored_hint')">✗ {{ header }}</span>
                            </div>

                            <div v-if="preview.fatal" class="px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ preview.fatal }}</div>
                            <template v-else>
                                <div class="flex flex-wrap items-center gap-2 mb-3" role="status">
                                    <span v-for="status in ['create', 'update', 'unchanged', 'skip', 'error']" :key="status" :class="['badge', badgeClass(status)]">{{ t('exchange.status.' + status) }}: {{ preview.totals[status] }}</span>
                                    <label class="ml-auto flex items-center gap-2 text-xs text-slate-600">
                                        {{ t('exchange.preview.show') }}
                                        <select v-model="rowFilter" class="input w-auto py-1 text-xs">
                                            <option value="all">{{ t('exchange.preview.filter_all', { count: preview.totals.rows }) }}</option>
                                            <option value="import">{{ t('exchange.preview.filter_import', { count: importable }) }}</option>
                                            <option value="error">{{ t('exchange.preview.filter_error', { count: preview.totals.error }) }}</option>
                                        </select>
                                    </label>
                                </div>
                                <div class="overflow-auto border border-slate-200 rounded-lg max-h-[28rem]">
                                    <table class="w-full text-sm min-w-[640px]">
                                        <thead class="bg-slate-50 border-b border-slate-200 sticky top-0">
                                            <tr>
                                                <th class="th w-16">{{ t('exchange.preview.line') }}</th>
                                                <th class="th">{{ t('exchange.preview.status') }}</th>
                                                <th class="th">{{ t('exchange.preview.record') }}</th>
                                                <th class="th">{{ t('exchange.preview.details') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in visibleRows" :key="row.line" class="border-b border-slate-100 align-top">
                                                <td class="td text-slate-500">{{ row.line }}</td>
                                                <td class="td"><span :class="['badge', badgeClass(row.status)]">{{ t('exchange.status.' + row.status) }}</span></td>
                                                <td class="td font-medium text-slate-800 break-words">{{ row.label || '—' }}</td>
                                                <td class="td text-xs">
                                                    <ul v-if="row.errors.length" class="text-red-700 space-y-0.5">
                                                        <li v-for="(item, index) in row.errors" :key="index">{{ item.label ? item.label + ': ' : '' }}{{ item.message }}</li>
                                                    </ul>
                                                    <span v-else-if="row.changes.length" class="text-blue-700">{{ t('exchange.preview.changes', { fields: row.changes.join(', ') }) }}</span>
                                                    <span v-else-if="row.message" class="text-slate-500">{{ row.message }}</span>
                                                    <span v-else-if="row.status === 'create'" class="text-emerald-700">{{ t('exchange.preview.new_record') }}</span>
                                                </td>
                                            </tr>
                                            <tr v-if="visibleRows.length === 0">
                                                <td colspan="4" class="px-4 py-6 text-center text-slate-400">{{ t('exchange.preview.empty') }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 mt-4">
                                    <button type="button" class="btn-primary" :disabled="importable === 0 || busy !== ''" @click="runCommit">
                                        {{ busy === 'commit' ? t('exchange.import.importing') : t('exchange.import.commit', { count: importable }) }}
                                    </button>
                                    <button type="button" class="btn-secondary" :disabled="busy !== ''" @click="resetPreview">{{ t('common.cancel') }}</button>
                                    <span v-if="preview.totals.error" class="text-xs text-red-700">{{ t('exchange.import.errors_skipped', { count: preview.totals.error }) }}</span>
                                    <span v-else-if="importable === 0" class="text-xs text-slate-500">{{ t('exchange.import.nothing') }}</span>
                                </div>
                            </template>
                        </div>

                        <div v-if="result" class="border-t border-slate-100 p-5">
                            <div class="px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm" role="status">
                                {{ t('exchange.result.summary', {
                                    file: result.file_name,
                                    created: result.totals.create,
                                    updated: result.totals.update,
                                    skipped: result.totals.skip + result.totals.unchanged,
                                    errors: result.totals.error,
                                }) }}
                            </div>
                            <ul v-if="result.totals.error" class="mt-3 text-xs text-red-700 space-y-0.5">
                                <li v-for="row in result.rows.filter((item) => item.status === 'error')" :key="row.line">
                                    {{ t('exchange.preview.line') }} {{ row.line }}: {{ row.errors.map((item) => (item.label ? item.label + ': ' : '') + item.message).join('; ') || row.message }}
                                </li>
                            </ul>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="btn-secondary" @click="openList(result.dataset)">{{ t('exchange.result.open_list') }}</button>
                                <button type="button" class="btn-ghost" @click="result = null">{{ t('exchange.result.again') }}</button>
                            </div>
                        </div>
                    </ReportSection>

                    <ReportSection :title="t('exchange.eml.title')" :description="t('exchange.eml.description')">
                        <div class="p-5 flex flex-wrap items-end gap-3">
                            <FormField :label="t('exchange.eml.file')">
                                <input type="file" accept=".eml,message/rfc822" class="block text-sm text-slate-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700" @change="onEmlFile">
                            </FormField>
                            <button type="button" class="btn-primary" :disabled="!emlFile || emlBusy" @click="analyzeEml">{{ emlBusy && !eml ? t('exchange.eml.analyzing') : t('exchange.eml.analyze') }}</button>
                        </div>
                        <div v-if="emlError" class="mx-5 mb-5 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" role="alert">{{ emlError }}</div>

                        <div v-if="eml" class="border-t border-slate-100 p-5 grid gap-6 lg:grid-cols-2">
                            <div class="min-w-0">
                                <DetailGrid :rows="emlHeader" />
                                <pre class="mt-4 max-h-64 overflow-y-auto whitespace-pre-wrap break-words text-xs bg-slate-50 border border-slate-200 rounded-lg p-3 font-sans">{{ eml.message.text || t('exchange.eml.no_text') }}</pre>
                                <p v-if="eml.message.truncated" class="text-xs text-slate-400 mt-1">{{ t('exchange.eml.truncated') }}</p>

                                <h5 class="text-sm font-semibold text-slate-700 mt-5 mb-2">{{ t('exchange.eml.attachments') }}</h5>
                                <p v-if="eml.message.attachments.length === 0" class="text-sm text-slate-400">{{ t('exchange.eml.no_attachments') }}</p>
                                <ul class="space-y-2">
                                    <li v-for="item in eml.message.attachments" :key="item.index" class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="font-medium text-slate-800 break-all">📎 {{ item.filename }}</span>
                                        <span class="text-xs text-slate-500">{{ fileSize(item.size) }}</span>
                                        <template v-if="item.importable">
                                            <span class="badge bg-emerald-50 text-emerald-800 border border-emerald-200">{{ item.dataset ? t('exchange.dataset.' + item.dataset) : t('exchange.eml.dataset_unknown') }} · {{ t('exchange.eml.rows', { count: item.rows }) }}</span>
                                            <button type="button" class="btn-ghost text-brand-700" @click="importAttachment(item)">{{ t('exchange.eml.import_attachment') }} →</button>
                                        </template>
                                        <span v-else class="text-xs text-slate-400">{{ t('exchange.eml.not_importable') }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div class="space-y-5 min-w-0">
                                <div>
                                    <h5 class="text-sm font-semibold text-slate-700 mb-2">{{ t('exchange.eml.sender') }}</h5>
                                    <p v-if="eml.sender.is_account" class="text-xs text-slate-500 mb-2">{{ t('exchange.eml.sender_account') }}</p>
                                    <label v-for="person in eml.sender.beneficiaries" :key="'b' + person.id" class="flex items-start gap-2 text-sm mb-1">
                                        <input v-model="emlActions.beneficiaries" type="checkbox" :value="person.id" class="mt-1">
                                        <span>{{ t('exchange.eml.note_person') }}
                                            <button type="button" class="text-brand-700 hover:underline" @click="openBeneficiary(person.id)">{{ person.name }}</button>
                                            <span v-if="person.payer_name" class="text-xs text-slate-500"> · {{ person.payer_name }}</span>
                                        </span>
                                    </label>
                                    <div v-if="eml.sender.suggestion" class="border border-slate-200 rounded-lg p-3">
                                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                                            <input v-model="emlActions.create" type="checkbox"> {{ t('exchange.eml.create_person') }}
                                        </label>
                                        <div v-if="emlActions.create" class="grid sm:grid-cols-2 gap-3 mt-3">
                                            <FormField :label="t('field.first_name')" required><input v-model="emlActions.person.first_name" class="input" maxlength="100"></FormField>
                                            <FormField :label="t('field.last_name')" required><input v-model="emlActions.person.last_name" class="input" maxlength="100"></FormField>
                                            <FormField :label="t('field.email')"><input v-model="emlActions.person.email" type="email" class="input" maxlength="255"></FormField>
                                            <FormField :label="t('field.phone')"><input v-model="emlActions.person.phone" class="input" maxlength="50"></FormField>
                                            <FormField class="sm:col-span-2" :label="t('field.payer_id')">
                                                <select v-model="emlActions.person.payer_id" class="input">
                                                    <option value="">{{ t('beneficiary.no_payer') }}</option>
                                                    <option v-for="payer in payerOptions" :key="payer.id" :value="String(payer.id)">{{ payer.company_name }}</option>
                                                </select>
                                            </FormField>
                                        </div>
                                    </div>
                                    <p v-else-if="eml.sender.beneficiaries.length === 0" class="text-sm text-slate-400">{{ t('exchange.eml.no_sender') }}</p>
                                </div>

                                <div v-if="eml.certificates.length">
                                    <h5 class="text-sm font-semibold text-slate-700 mb-2">{{ t('exchange.eml.certificates') }}</h5>
                                    <label v-for="item in eml.certificates" :key="'c' + item.id" class="flex items-start gap-2 text-sm mb-1">
                                        <input v-model="emlActions.certificates" type="checkbox" :value="item.id" class="mt-1">
                                        <span>
                                            <button type="button" class="text-brand-700 hover:underline" @click="openCertificate(item.id)">{{ item.name }}</button>
                                            <span class="block text-xs text-slate-500">{{ item.serial_number }} · {{ format.date(item.expiry_date) }}<span v-if="item.beneficiary_name"> · {{ item.beneficiary_name }}</span> · {{ item.payer_name }}</span>
                                        </span>
                                    </label>
                                    <p class="text-xs text-slate-500">{{ t('exchange.eml.note_hint') }}</p>
                                </div>

                                <div v-if="emlSenderPayers.length">
                                    <h5 class="text-sm font-semibold text-slate-700 mb-2">{{ t('exchange.eml.payers') }}</h5>
                                    <label v-for="payer in emlSenderPayers" :key="'p' + payer.id" class="flex items-start gap-2 text-sm mb-1">
                                        <input v-model="emlActions.payers" type="checkbox" :value="payer.id" class="mt-1">
                                        <span>{{ t('exchange.eml.note_payer') }}
                                            <button type="button" class="text-brand-700 hover:underline" @click="openPayer(payer.id)">{{ payer.company_name }}</button>
                                            <span v-if="payer.tax_id" class="text-xs text-slate-500"> · NIP {{ payer.tax_id }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div v-if="eml.invitations.length">
                                    <h5 class="text-sm font-semibold text-slate-700 mb-2">{{ t('exchange.eml.invitations') }}</h5>
                                    <label v-for="item in eml.invitations" :key="'i' + item.id" class="flex items-start gap-2 text-sm mb-1">
                                        <input v-model="emlActions.responded" type="checkbox" :value="item.id" class="mt-1">
                                        <span>{{ t('exchange.eml.mark_responded') }}
                                            <button type="button" class="text-brand-700 hover:underline" @click="openInvitation(item.id)">{{ item.certificate_name }}</button>
                                            <span class="block text-xs text-slate-500">{{ t('invitation.status.' + item.status) }} · {{ item.sent_at ? format.dateTime(item.sent_at) : '—' }} · {{ t('exchange.eml.reminders', { count: item.reminder_count }) }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div v-if="emlResult" class="px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm" role="status">
                                    {{ t('exchange.eml.result', { notes: emlResult.notes, responded: emlResult.responded }) }}
                                    <button v-if="emlResult.beneficiary" type="button" class="block mt-1 text-brand-700 hover:underline" @click="openBeneficiary(emlResult.beneficiary.id)">{{ t('exchange.eml.created_person', { name: emlResult.beneficiary.name }) }} →</button>
                                </div>
                                <button v-else type="button" class="btn-primary" :disabled="!emlHasActions || emlBusy" @click="applyEml">{{ emlBusy ? t('common.saving') : t('exchange.eml.apply') }}</button>
                            </div>
                        </div>
                    </ReportSection>
                </div>
            </section>
        `,
    };
})(window.CertiSub);
