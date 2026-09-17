/*
 * Aplikacja panelu firmowego: nawigacja (adres z #), widoki, panele szczegółów, okna i powiadomienia.
 * Ładowany jako ostatni — po core.js, components.js i plikach widoków.
 */
(function (CertiSub) {
    'use strict';

    const { t, can, api, endpoints, ui, store } = CertiSub;

    const VIEWS = {
        dashboard: { component: 'DashboardView', icon: '📊', label: 'nav.dashboard' },
        todo: { component: 'TasksView', icon: '✅', label: 'nav.todo', permission: 'tasks.view' },
        invitations: { component: 'InvitationsView', icon: '✉️', label: 'nav.invitations', permission: 'invitations.view' },
        certificates: { component: 'CertificatesView', icon: '📜', label: 'nav.certificates', props: { mode: 'all' }, permission: 'certificates.view' },
        beneficiaries: {
            component: 'BeneficiariesView', icon: '👤', label: 'nav.beneficiaries', permission: 'beneficiaries.view',
            card: { component: 'BeneficiaryCardView', permission: 'reports.view' },
        },
        payers: {
            component: 'PayersView', icon: '🏢', label: 'nav.payers', permission: 'payers.view',
            card: { component: 'PayerCardView', permission: 'reports.view' },
        },
        reports: { component: 'ReportsView', icon: '📈', label: 'nav.reports', permission: 'reports.view' },
        search: { component: 'SearchView', icon: '🔍', label: 'search.title', permission: 'search.use', hidden: true },
        archive: { component: 'ArchiveView', icon: '📦', label: 'nav.archive', permission: 'archive.view', group: 'manage' },
        events: { component: 'EventLogView', icon: '🧾', label: 'nav.events', permission: 'events.view_all', group: 'admin' },
        templates: { component: 'TemplatesView', icon: '🧩', label: 'nav.templates', permission: 'templates.manage', group: 'admin' },
        accounts: { component: 'AccountsView', icon: '🔐', label: 'nav.accounts', permission: 'accounts.manage', group: 'admin' },
        settings: { component: 'SettingsView', icon: '⚙️', label: 'nav.settings', permission: 'settings.manage', group: 'admin' },
    };

    const DRAWERS = {
        certificate: 'CertificateDrawer',
        beneficiary: 'BeneficiaryDrawer',
        payer: 'PayerDrawer',
        task: 'TaskDrawer',
        invitation: 'InvitationDrawer',
    };

    function allowed(viewId) {
        const view = VIEWS[viewId];
        return Boolean(view) && (!view.permission || can(view.permission));
    }

    /**
     * Adres widoku: #/payers — lista, #/payers/12 — karta rekordu (gdy widok ma kartę i rola ma do niej dostęp).
     */
    function routeFromHash() {
        const parts = String(window.location.hash || '').replace(/^#\/?/, '').split('/');
        const view = allowed(parts[0]) ? parts[0] : 'dashboard';
        const card = VIEWS[view].card;
        const id = /^\d+$/.test(parts[1] || '') ? Number(parts[1]) : null;
        return { view, recordId: id && card && can(card.permission) ? id : null };
    }

    function applyRoute() {
        const route = routeFromHash();
        ui.view = route.view;
        ui.recordId = route.recordId;
    }

    const Root = {
        data() {
            return {
                ui,
                store,
                user: CertiSub.boot.user,
                deleteAccountOpen: false,
                deleteAccountBusy: false,
                deleteAccountError: '',
            };
        },
        computed: {
            navItems() {
                return Object.keys(VIEWS).filter((id) => allowed(id) && !VIEWS[id].hidden).map((id) => {
                    const view = VIEWS[id];
                    let badgeCount = null;
                    if (id === 'todo' && store.taskStats) {
                        badgeCount = store.taskStats.open || null;
                    }
                    return { id, icon: view.icon, label: t(view.label), group: view.group || 'main', badge: badgeCount };
                });
            },
            mainItems() {
                return this.navItems.filter((item) => item.group === 'main');
            },
            manageItems() {
                return this.navItems.filter((item) => item.group !== 'main');
            },
            currentView() {
                const view = VIEWS[ui.view] || VIEWS.dashboard;
                if (ui.recordId && view.card) {
                    return { component: view.card.component, props: { id: ui.recordId } };
                }
                return view;
            },
            routeKey() {
                return ui.view + (ui.recordId ? '/' + ui.recordId : '');
            },
            showSearch() {
                return can('search.use') && ui.view !== 'search';
            },
            drawerComponent() {
                return ui.drawer ? DRAWERS[ui.drawer.type] : null;
            },
        },
        watch: {
            routeKey() {
                // Nowy widok albo karta zaczyna się od góry, a nie w miejscu przewinięcia poprzedniego.
                if (this.$refs.main) {
                    this.$refs.main.scrollTop = 0;
                }
            },
        },
        created() {
            applyRoute();
            window.addEventListener('hashchange', applyRoute);
            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && ui.modals.length === 0 && ui.drawer) {
                    CertiSub.closeDrawer();
                }
            });
            CertiSub.data.summary();
            if (can('tasks.view')) {
                CertiSub.data.taskStats();
            }
        },
        methods: {
            go(id) {
                if (window.location.hash !== '#/' + id) {
                    window.location.hash = '#/' + id;
                } else {
                    applyRoute();
                }
            },
            closeModal(key) {
                CertiSub.closeModal(key);
            },
            onSaved(modal, payload) {
                if (typeof modal.onSaved === 'function') {
                    modal.onSaved(payload);
                }
            },
            openDeleteAccount() {
                this.deleteAccountError = '';
                this.deleteAccountOpen = true;
            },
            async confirmDeleteAccount() {
                this.deleteAccountBusy = true;
                this.deleteAccountError = '';
                try {
                    const data = await api.post(endpoints.delete_account, { confirm: true });
                    window.location.href = data.redirect || CertiSub.boot.links.home;
                } catch (error) {
                    this.deleteAccountError = error.message;
                } finally {
                    this.deleteAccountBusy = false;
                }
            },
        },
        template: `
            <div class="flex flex-1 min-h-0">
                <aside class="hidden md:flex w-64 bg-white border-r border-slate-200 flex-col shrink-0 no-print">
                    <nav class="flex-1 overflow-y-auto py-4 px-2" :aria-label="t('dash.navigation')">
                        <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">{{ t('dash.navigation') }}</p>
                        <button v-for="item in mainItems" :key="item.id" type="button" @click="go(item.id)"
                                :aria-current="ui.view === item.id ? 'page' : null"
                                :class="['nav-item w-full text-left flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium transition mb-1', ui.view === item.id ? 'active' : 'text-slate-600 hover:bg-slate-100']">
                            <span aria-hidden="true">{{ item.icon }}</span> {{ item.label }}
                            <span v-if="item.badge" class="ml-auto text-xs bg-red-500 text-white px-1.5 py-0.5 rounded-full">{{ item.badge }}</span>
                        </button>
                        <template v-if="manageItems.length">
                            <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mt-6 mb-2">{{ t('nav.management') }}</p>
                            <button v-for="item in manageItems" :key="item.id" type="button" @click="go(item.id)"
                                    :aria-current="ui.view === item.id ? 'page' : null"
                                    :class="['nav-item w-full text-left flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium transition mb-1', ui.view === item.id ? 'active' : 'text-slate-600 hover:bg-slate-100']">
                                <span aria-hidden="true">{{ item.icon }}</span> {{ item.label }}
                            </button>
                        </template>
                    </nav>
                    <div class="border-t border-slate-100 p-4 text-sm">
                        <p class="font-semibold text-slate-800">{{ user.name }}</p>
                        <p class="text-xs text-slate-500 break-all">{{ user.email }}</p>
                        <span :class="['badge mt-2', badge.role(user.role)]">{{ labels.role(user.role) }}</span>
                        <button type="button" class="block mt-3 text-xs text-red-600 hover:underline" @click="openDeleteAccount">{{ t('auth.delete_account') }}</button>
                    </div>
                </aside>

                <main ref="main" class="flex-1 overflow-y-auto p-4 sm:p-6 min-w-0">
                    <div v-if="showSearch" class="mb-5"><GlobalSearch /></div>
                    <label class="md:hidden block mb-4 no-print">
                        <span class="sr-only">{{ t('dash.navigation') }}</span>
                        <select class="input" :value="ui.view" @change="go($event.target.value)">
                            <option v-for="item in navItems" :key="item.id" :value="item.id">{{ item.icon }} {{ item.label }}</option>
                        </select>
                    </label>
                    <component :is="currentView.component" v-bind="currentView.props || {}" :key="routeKey" />
                </main>

                <component v-if="drawerComponent" :is="drawerComponent" :id="ui.drawer.id" :key="ui.drawer.key" />

                <component v-for="modal in ui.modals" :key="modal.key" :is="modal.type" v-bind="modal.props"
                           @close="closeModal(modal.key)" @saved="onSaved(modal, $event)" />

                <ModalShell v-if="deleteAccountOpen" :title="t('auth.delete_account_title')" size="sm" :busy="deleteAccountBusy" @close="deleteAccountOpen = false">
                    <p class="text-sm text-slate-600 leading-relaxed">{{ t('auth.delete_account_desc') }}</p>
                    <div v-if="deleteAccountError" class="mt-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ deleteAccountError }}</div>
                    <template #footer>
                        <button type="button" class="btn-secondary" :disabled="deleteAccountBusy" @click="deleteAccountOpen = false">{{ t('auth.delete_account_cancel') }}</button>
                        <button type="button" class="btn-danger" :disabled="deleteAccountBusy" @click="confirmDeleteAccount">{{ deleteAccountBusy ? t('common.saving') : t('auth.delete_account_confirm') }}</button>
                    </template>
                </ModalShell>

                <ConfirmDialog />
                <ToastStack />
            </div>
        `,
    };

    const app = window.Vue.createApp(Root);
    Object.keys(CertiSub.components).forEach((name) => app.component(name, CertiSub.components[name]));
    Object.assign(app.config.globalProperties, {
        t,
        can,
        format: CertiSub.format,
        labels: CertiSub.labels,
        badge: CertiSub.badge,
        CertiSub,
    });
    app.mount('#app');
    CertiSub.app = app;
})(window.CertiSub);
