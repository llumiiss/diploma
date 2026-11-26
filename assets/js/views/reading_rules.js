/*
 * Szablony odczytu wniosków (Etap 11): własne etykiety, po których czytnik wiadomości rozpoznaje pola
 * formularza. Zakładka widoku „Szablony i załączniki” (ADMIN); import i eksport w CSV/XML.
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    CertiSub.components.ReadingRulesPanel = {
        emits: ['count'],
        data() {
            return { rules: [], fields: [], loading: true, error: '', search: '' };
        },
        computed: {
            filtered() {
                const matches = CertiSub.textFilter.matcher(this.search);
                return this.rules.filter((rule) => matches([rule.name, rule.label, this.fieldLabel(rule.field), rule.sender_match, rule.subject_match]));
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
                    const data = await api.get(endpoints.reading_rules);
                    this.rules = data.rules;
                    this.fields = data.fields;
                    this.$emit('count', data.rules.length);
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            fieldLabel(field) {
                const found = this.fields.find((item) => item.field === field);
                return found ? found.label : field;
            },
            add() {
                CertiSub.openModal('ReadingRuleForm', { fields: this.fields }, () => this.load());
            },
            edit(rule) {
                CertiSub.openModal('ReadingRuleForm', { fields: this.fields, rule }, () => this.load());
            },
            importFile() {
                CertiSub.openImport('reading_rules');
            },
            async remove(rule) {
                const ok = await CertiSub.confirm({
                    title: t('reading.delete_title'),
                    message: t('reading.delete_confirm', { label: rule.label, name: rule.name }),
                    confirmLabel: t('reading.delete'),
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                try {
                    await api.post(endpoints.reading_rules, { action: 'delete', id: rule.id });
                    CertiSub.notify(t('reading.deleted'));
                    await this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
        },
        template: `
            <div>
                <p class="text-sm text-slate-500 mb-3">{{ t('reading.description') }}</p>
                <div class="flex flex-wrap items-center gap-3 mb-3">
                    <input v-model="search" type="search" class="input max-w-sm" :placeholder="t('reading.search')" :aria-label="t('common.search')" :title="t('filter.syntax')">
                    <span class="ml-auto flex flex-wrap items-center gap-2">
                        <ExportButtons dataset="reading_rules" />
                        <button v-if="can('import.run')" type="button" class="btn-secondary" @click="importFile">⬆ {{ t('exchange.import.from_file') }}</button>
                        <button type="button" class="btn-primary" @click="add">+ {{ t('reading.add') }}</button>
                    </span>
                </div>
                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[760px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('field.name') }}</th>
                                <th class="th">{{ t('reading.column.label') }}</th>
                                <th class="th">{{ t('reading.column.field') }}</th>
                                <th class="th">{{ t('reading.conditions') }}</th>
                                <th class="th">{{ t('field.status') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rule in filtered" :key="rule.id" class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="edit(rule)">
                                <td class="td font-medium text-slate-800">{{ rule.name }}</td>
                                <td class="td font-mono text-xs">{{ rule.label }}</td>
                                <td class="td">{{ fieldLabel(rule.field) }}</td>
                                <td class="td text-xs text-slate-500">
                                    <div v-if="rule.sender_match">{{ t('reading.column.sender_match') }}: {{ rule.sender_match }}</div>
                                    <div v-if="rule.subject_match">{{ t('reading.column.subject_match') }}: {{ rule.subject_match }}</div>
                                    <span v-if="!rule.sender_match && !rule.subject_match">{{ t('reading.every_message') }}</span>
                                </td>
                                <td class="td"><span :class="['badge', rule.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600']">{{ rule.is_active ? t('template.active') : t('template.inactive') }}</span></td>
                                <td class="td text-right whitespace-nowrap" @click.stop>
                                    <button type="button" class="btn-ghost" @click="edit(rule)">{{ t('common.edit') }}</button>
                                    <button type="button" class="btn-ghost text-red-600" @click="remove(rule)">{{ t('reading.delete') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="6" :loading="loading" :error="error" :empty="!loading && filtered.length === 0" :empty-text="t('reading.empty')" />
                        </tbody>
                    </table>
                </div>
            </div>
        `,
    };

    CertiSub.components.ReadingRuleForm = {
        props: {
            fields: { type: Array, required: true },
            rule: { type: Object, default: null },
        },
        emits: ['close', 'saved'],
        data() {
            const rule = this.rule || {};
            return {
                form: {
                    name: rule.name || '',
                    field: rule.field || '',
                    label: rule.label || '',
                    sender_match: rule.sender_match || '',
                    subject_match: rule.subject_match || '',
                    is_active: this.rule ? Boolean(rule.is_active) : true,
                },
                saving: false,
                errors: {},
                message: '',
            };
        },
        computed: {
            fieldOptions() {
                return this.fields.map((item) => ({ value: item.field, label: item.label, search: item.field }));
            },
        },
        methods: {
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.reading_rules, {
                        action: this.rule ? 'update' : 'create',
                        id: this.rule ? this.rule.id : null,
                        data: this.form,
                    });
                    CertiSub.notify(t('reading.saved'));
                    this.$emit('saved', result.rule);
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
            <ModalShell :title="rule ? t('reading.edit_title') : t('reading.add_title')" :subtitle="t('reading.form_description')" :busy="saving" @close="$emit('close')">
                <form id="reading-rule-form" class="grid sm:grid-cols-2 gap-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="sm:col-span-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <FormField class="sm:col-span-2" :label="t('field.name')" :error="errors.name" :hint="t('reading.hint.name')" required>
                        <input v-model="form.name" type="text" maxlength="120" :class="['input', errors.name ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('reading.column.label')" :error="errors.label" :hint="t('reading.hint.label')" required>
                        <input v-model="form.label" type="text" maxlength="120" :class="['input', errors.label ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('reading.column.field')" :error="errors.field" required>
                        <SearchSelect v-model="form.field" :options="fieldOptions" :empty-label="t('common.choose')" :invalid="Boolean(errors.field)" :aria-label="t('reading.column.field')" />
                    </FormField>
                    <FormField :label="t('reading.column.sender_match')" :error="errors.sender_match" :hint="t('reading.hint.sender')">
                        <input v-model="form.sender_match" type="text" maxlength="255" class="input">
                    </FormField>
                    <FormField :label="t('reading.column.subject_match')" :error="errors.subject_match" :hint="t('reading.hint.subject')">
                        <input v-model="form.subject_match" type="text" maxlength="255" class="input">
                    </FormField>
                    <label class="sm:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300">
                        {{ t('field.is_active') }}
                    </label>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="reading-rule-form" class="btn-primary" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
