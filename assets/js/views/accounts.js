/*
 * Konta personelu (ADMIN): zakładanie kont, role, wyłączanie i przekazywanie certyfikatów (D3, F19).
 */
(function (CertiSub) {
    'use strict';

    const { t, api, endpoints } = CertiSub;

    CertiSub.components.AccountsView = {
        data() {
            return { accounts: [], loading: true, error: '', showInactive: true };
        },
        computed: {
            visibleAccounts() {
                return this.showInactive ? this.accounts : this.accounts.filter((account) => account.active);
            },
            activeCount() {
                return this.accounts.filter((account) => account.active).length;
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
                    const data = await api.get(endpoints.accounts);
                    this.accounts = data.accounts;
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loading = false;
                }
            },
            add() {
                CertiSub.openModal('AccountForm', {}, () => this.load());
            },
            edit(account) {
                CertiSub.openModal('AccountForm', { account }, () => this.load());
            },
            transfer(account) {
                CertiSub.openModal('TransferForm', { account, accounts: this.accounts }, () => this.load());
            },
            isSelf(account) {
                return account.id === CertiSub.boot.user.id;
            },
            async deactivate(account) {
                const ok = await CertiSub.confirm({
                    title: t('account.deactivate_title'),
                    message: t('account.deactivate_confirm', { name: account.name, certificates: account.certificate_count, tasks: account.open_task_count }),
                    confirmLabel: t('account.deactivate'),
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                await this.post({ action: 'deactivate', id: account.id }, 'account.deactivated');
            },
            async reactivate(account) {
                await this.post({ action: 'reactivate', id: account.id }, 'account.reactivated');
            },
            async sendPasswordLink(account) {
                const ok = await CertiSub.confirm({
                    title: t('account.send_password_link_title'),
                    message: t('account.send_password_link_confirm', { name: account.name, email: account.email }),
                    confirmLabel: t('account.send_password_link'),
                });
                if (!ok) {
                    return;
                }
                await this.post({ action: 'send_password_link', id: account.id }, 'account.password_link_sent');
            },
            async post(body, messageKey) {
                try {
                    await api.post(endpoints.accounts, body);
                    CertiSub.notify(t(messageKey));
                    CertiSub.data.refresh('options');
                    await this.load();
                } catch (error) {
                    CertiSub.notifyError(error);
                }
            },
        },
        template: `
            <section>
                <PageHeader :title="t('account.title')" :description="t('account.description')">
                    <button type="button" class="btn-primary" @click="add">+ {{ t('account.add') }}</button>
                </PageHeader>

                <div class="flex items-center justify-between mb-3 text-sm text-slate-500">
                    <span>{{ t('account.summary', { active: activeCount, total: accounts.length }) }}</span>
                    <label class="flex items-center gap-2">
                        <input v-model="showInactive" type="checkbox" class="rounded border-slate-300">
                        {{ t('account.show_inactive') }}
                    </label>
                </div>

                <div class="card overflow-x-auto">
                    <table class="w-full text-sm min-w-[900px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="th">{{ t('account.singular') }}</th>
                                <th class="th">{{ t('field.role') }}</th>
                                <th class="th">{{ t('field.status') }}</th>
                                <th class="th text-right">{{ t('certificate.plural') }}</th>
                                <th class="th text-right">{{ t('account.open_tasks') }}</th>
                                <th class="th">{{ t('account.last_login') }}</th>
                                <th class="th text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="account in visibleAccounts" :key="account.id" :class="['border-b border-slate-100', account.active ? '' : 'bg-slate-50 text-slate-400']">
                                <td class="td">
                                    <div class="font-medium" :class="account.active ? 'text-slate-800' : ''">
                                        {{ account.name }} <span v-if="isSelf(account)" class="text-xs text-brand-600">({{ t('account.you') }})</span>
                                    </div>
                                    <div class="text-xs text-slate-500">{{ account.email }}</div>
                                </td>
                                <td class="td"><span :class="['badge', badge.role(account.role)]">{{ labels.role(account.role) }}</span></td>
                                <td class="td">
                                    <span v-if="account.active" class="badge bg-emerald-100 text-emerald-800">{{ t('account.active') }}</span>
                                    <span v-else class="badge bg-slate-200 text-slate-600" :title="format.dateTime(account.deactivated_at)">{{ t('account.inactive') }}</span>
                                </td>
                                <td class="td text-right">{{ account.certificate_count }}</td>
                                <td class="td text-right">{{ account.open_task_count }}</td>
                                <td class="td whitespace-nowrap">{{ account.last_login_at ? format.dateTime(account.last_login_at) : t('account.never') }}</td>
                                <td class="td text-right whitespace-nowrap">
                                    <button type="button" class="btn-ghost" @click="edit(account)">{{ t('common.edit') }}</button>
                                    <button v-if="account.certificate_count > 0 || account.open_task_count > 0" type="button" class="btn-ghost" @click="transfer(account)">{{ t('account.transfer') }}</button>
                                    <button v-if="account.active" type="button" class="btn-ghost" @click="sendPasswordLink(account)">{{ t('account.send_password_link') }}</button>
                                    <button v-if="account.active && !isSelf(account)" type="button" class="btn-ghost text-red-600" @click="deactivate(account)">{{ t('account.deactivate') }}</button>
                                    <button v-if="!account.active" type="button" class="btn-ghost text-emerald-700" @click="reactivate(account)">{{ t('account.reactivate') }}</button>
                                </td>
                            </tr>
                            <TableState :colspan="7" :loading="loading" :error="error" :empty="!loading && visibleAccounts.length === 0" />
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-400 mt-3">{{ t('account.login_hint') }}</p>
            </section>
        `,
    };

    CertiSub.components.AccountForm = {
        props: {
            account: { type: Object, default: null },
        },
        emits: ['close', 'saved'],
        data() {
            const account = this.account || {};
            return {
                form: {
                    first_name: account.first_name || '',
                    last_name: account.last_name || '',
                    email: account.email || '',
                    role: account.role || 'OPERATOR',
                },
                saving: false,
                errors: {},
                message: '',
            };
        },
        computed: {
            isEdit() {
                return this.account !== null;
            },
        },
        methods: {
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.accounts, {
                        action: this.isEdit ? 'update' : 'create',
                        id: this.isEdit ? this.account.id : null,
                        data: this.form,
                    });
                    CertiSub.notify(t(this.isEdit ? 'account.updated' : 'account.created'));
                    this.$emit('saved', result.account);
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
            <ModalShell :title="isEdit ? t('account.edit_title') : t('account.add_title')" :subtitle="t('account.form_description')"
                        :busy="saving" @close="$emit('close')">
                <form id="account-form" class="grid sm:grid-cols-2 gap-4" novalidate @submit.prevent="submit">
                    <div v-if="message" class="sm:col-span-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <FormField :label="t('field.first_name')" :error="errors.first_name" required>
                        <input v-model="form.first_name" type="text" maxlength="100" :class="['input', errors.first_name ? 'input-error' : '']">
                    </FormField>
                    <FormField :label="t('field.last_name')" :error="errors.last_name" required>
                        <input v-model="form.last_name" type="text" maxlength="100" :class="['input', errors.last_name ? 'input-error' : '']">
                    </FormField>
                    <FormField class="sm:col-span-2" :label="t('field.email')" :error="errors.email" :hint="t('account.hint.email')" required>
                        <input v-model="form.email" type="email" maxlength="255" :class="['input', errors.email ? 'input-error' : '']">
                    </FormField>
                    <FormField class="sm:col-span-2" :label="t('field.role')" :error="errors.role" required>
                        <select v-model="form.role" :class="['input', errors.role ? 'input-error' : '']">
                            <option v-for="role in labels.roles" :key="role" :value="role">{{ labels.role(role) }} — {{ t('account.role_hint.' + role.toLowerCase()) }}</option>
                        </select>
                    </FormField>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="account-form" class="btn-primary" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
                </template>
            </ModalShell>
        `,
    };

    CertiSub.components.TransferForm = {
        props: {
            account: { type: Object, required: true },
            accounts: { type: Array, default: () => [] },
        },
        emits: ['close', 'saved'],
        data() {
            return { target: '', saving: false, errors: {}, message: '' };
        },
        computed: {
            candidates() {
                return this.accounts.filter((item) => item.active && item.id !== this.account.id);
            },
        },
        methods: {
            async submit() {
                this.saving = true;
                this.errors = {};
                this.message = '';
                try {
                    const result = await api.post(endpoints.accounts, {
                        action: 'transfer',
                        from_user_id: this.account.id,
                        to_user_id: this.target ? Number(this.target) : null,
                    });
                    CertiSub.notify(t('account.transferred', result.transferred));
                    CertiSub.data.refresh('certificates', 'summary', 'options');
                    this.$emit('saved', result.transferred);
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
            <ModalShell :title="t('account.transfer_title')"
                        :subtitle="t('account.transfer_description', { name: account.name, certificates: account.certificate_count, tasks: account.open_task_count })"
                        :busy="saving" @close="$emit('close')">
                <form id="transfer-form" novalidate @submit.prevent="submit">
                    <div v-if="message" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ message }}</div>
                    <FormField :label="t('account.transfer_to')" :error="errors.to_user_id" required>
                        <select v-model="target" :class="['input', errors.to_user_id ? 'input-error' : '']">
                            <option value="">{{ t('common.choose') }}</option>
                            <option v-for="item in candidates" :key="item.id" :value="String(item.id)">{{ item.name }} ({{ labels.role(item.role) }})</option>
                        </select>
                    </FormField>
                </form>
                <template #footer>
                    <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">{{ t('common.cancel') }}</button>
                    <button type="submit" form="transfer-form" class="btn-primary" :disabled="saving || !target">{{ saving ? t('common.saving') : t('account.transfer') }}</button>
                </template>
            </ModalShell>
        `,
    };
})(window.CertiSub);
