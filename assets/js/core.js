/*
 * CertiSub Assistant — rdzeń interfejsu panelu firmowego.
 *
 * Vue 3 bez kroku budowania: komponenty to obiekty z szablonem w postaci tekstu,
 * rejestrowane w CertiSub.components i montowane przez assets/js/app.js.
 * Dane startowe (tłumaczenia, token CSRF, konto, uprawnienia) wstawia PHP w window.CERTISUB_BOOT.
 * Uprawnienia w przeglądarce służą tylko do ukrywania przycisków — decyduje zawsze serwer.
 */
(function (global) {
    'use strict';

    const boot = global.CERTISUB_BOOT || {};
    const { reactive } = global.Vue;

    // ── Tłumaczenia i uprawnienia ─────────────────────────────────────────────

    function t(key, replace) {
        let text = (boot.i18n && Object.prototype.hasOwnProperty.call(boot.i18n, key)) ? boot.i18n[key] : key;
        if (replace) {
            Object.keys(replace)
                .sort((a, b) => b.length - a.length)
                .forEach((name) => {
                    text = text.split(':' + name).join(String(replace[name]));
                });
        }
        return text;
    }

    function can(permission) {
        return Array.isArray(boot.permissions) && boot.permissions.indexOf(permission) !== -1;
    }

    // ── Komunikacja z API ─────────────────────────────────────────────────────

    class ApiError extends Error {
        constructor(message, status, errors, data) {
            super(message);
            this.status = status;
            this.errors = errors || {};
            this.data = data || {};
        }
    }

    async function request(url, options) {
        const { method = 'GET', params = null, body = null } = options || {};
        let target = url;

        if (params) {
            const query = new URLSearchParams();
            Object.keys(params).forEach((name) => {
                const value = params[name];
                if (value !== null && value !== undefined && value !== '') {
                    query.append(name, value);
                }
            });
            const queryString = query.toString();
            if (queryString) {
                target += (target.indexOf('?') === -1 ? '?' : '&') + queryString;
            }
        }

        const headers = { Accept: 'application/json', 'X-CSRF-Token': boot.csrf };
        let payload;
        if (body instanceof FormData) {
            payload = body;
        } else if (body !== null) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }

        let response;
        try {
            response = await fetch(target, { method, headers, body: payload, credentials: 'same-origin' });
        } catch (error) {
            throw new ApiError(t('api.error.network'), 0);
        }

        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }

        if (response.status === 401) {
            global.location.href = boot.links.login;
            throw new ApiError(t('api.error.unauthorized'), 401);
        }

        if (!response.ok || !data || data.success === false) {
            throw new ApiError((data && data.message) || t('api.error.server'), response.status, data && data.errors, data);
        }

        return data;
    }

    const api = {
        request,
        get: (url, params) => request(url, { params }),
        post: (url, body) => request(url, { method: 'POST', body }),
    };

    // ── Formatowanie ──────────────────────────────────────────────────────────

    const intlLocale = { pl: 'pl-PL', en: 'en-GB', de: 'de-DE', es: 'es-ES', uk: 'uk-UA' }[boot.locale] || 'pl-PL';

    const format = {
        money(amount, currency) {
            const number = Number(amount || 0);
            return number.toLocaleString(intlLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (currency || 'PLN');
        },
        date(value) {
            if (!value) {
                return '—';
            }
            const parts = String(value).slice(0, 10).split('-');
            if (parts.length !== 3) {
                return String(value);
            }
            return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).toLocaleDateString(intlLocale);
        },
        dateTime(value) {
            if (!value) {
                return '—';
            }
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return String(value);
            }
            return date.toLocaleDateString(intlLocale) + ' ' + date.toLocaleTimeString(intlLocale, { hour: '2-digit', minute: '2-digit' });
        },
        days(days) {
            if (days === null || days === undefined) {
                return '—';
            }
            if (days < 0) {
                return t('days.overdue', { count: Math.abs(days) });
            }
            if (days === 0) {
                return t('days.today');
            }
            if (days === 1) {
                return t('days.one');
            }
            return t('days.many', { count: days });
        },
        person(first, last) {
            return [first, last].filter(Boolean).join(' ');
        },
        daysUntil(value) {
            if (!value) {
                return null;
            }
            const parts = String(value).slice(0, 10).split('-');
            const target = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return Math.round((target - today) / 86400000);
        },
    };

    // ── Słowniki i kolory etykiet ─────────────────────────────────────────────

    const TYPE_KEYS = {
        QUALIFIED_SIGNATURE: 'type.qualified_signature',
        QUALIFIED_SEAL: 'type.qualified_seal',
        SSL_CERTIFICATE: 'type.ssl',
        CODE_SIGNING: 'type.code_signing',
        DOMAIN: 'type.domain',
        SAAS: 'type.saas',
        CLOUD_SUPPORT: 'type.cloud_support',
        OTHER: 'type.other',
    };

    const labels = {
        types: Object.keys(TYPE_KEYS),
        statuses: ['pending', 'active', 'renewal_in_progress', 'expired'],
        billingCycles: ['monthly', 'annual', 'multi_year'],
        paymentStatuses: ['paid', 'due_soon', 'overdue', 'not_applicable'],
        roles: ['OPERATOR', 'MANAGER', 'ADMIN'],
        type: (value) => t(TYPE_KEYS[value] || 'type.other'),
        status: (value) => t('status.' + value),
        payment: (value) => t(value === 'not_applicable' ? 'payment.na' : 'payment.' + value),
        billing: (value) => t('billing.' + value),
        role: (value) => t('role.' + String(value || '').toLowerCase()),
        priority: (value) => t('priority.label.' + value),
    };

    const badge = {
        priority(value) {
            return {
                expired: 'bg-red-200 text-red-900',
                critical: 'bg-red-100 text-red-800',
                warning: 'bg-amber-100 text-amber-800',
                ok: 'bg-emerald-100 text-emerald-800',
            }[value] || 'bg-slate-100 text-slate-700';
        },
        payment(value) {
            return {
                paid: 'bg-emerald-100 text-emerald-800',
                due_soon: 'bg-amber-100 text-amber-800',
                overdue: 'bg-red-100 text-red-800',
                not_applicable: 'bg-slate-100 text-slate-600',
            }[value] || 'bg-slate-100 text-slate-600';
        },
        status(value) {
            return {
                active: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                pending: 'bg-amber-50 text-amber-700 border border-amber-200',
                renewal_in_progress: 'bg-blue-50 text-blue-700 border border-blue-200',
                expired: 'bg-red-50 text-red-700 border border-red-200',
            }[value] || 'bg-slate-100 text-slate-700';
        },
        role(value) {
            return {
                ADMIN: 'bg-purple-100 text-purple-800',
                MANAGER: 'bg-brand-100 text-brand-800',
                OPERATOR: 'bg-slate-100 text-slate-700',
            }[value] || 'bg-slate-100 text-slate-700';
        },
        row(priority) {
            if (priority === 'expired' || priority === 'critical') {
                return 'row-critical';
            }
            return priority === 'warning' ? 'row-warning' : '';
        },
    };

    // ── Powiadomienia i okno potwierdzenia ────────────────────────────────────

    const toasts = reactive([]);
    let toastSequence = 0;

    function dismiss(id) {
        const index = toasts.findIndex((toast) => toast.id === id);
        if (index !== -1) {
            toasts.splice(index, 1);
        }
    }

    function notify(message, type, timeout) {
        const id = ++toastSequence;
        toasts.push({ id, message, type: type || 'success' });
        global.setTimeout(() => dismiss(id), timeout || (type === 'error' ? 8000 : 4500));
    }

    function notifyError(error) {
        notify(error && error.message ? error.message : t('api.error.server'), 'error');
    }

    const confirmState = reactive({ open: false, title: '', message: '', confirmLabel: '', danger: false, resolve: null });

    function confirm(options) {
        return new Promise((resolve) => {
            Object.assign(confirmState, {
                open: true,
                title: options.title || t('common.confirm_title'),
                message: options.message || '',
                confirmLabel: options.confirmLabel || t('common.confirm'),
                danger: Boolean(options.danger),
                resolve,
            });
        });
    }

    function settleConfirm(result) {
        const resolve = confirmState.resolve;
        confirmState.open = false;
        confirmState.resolve = null;
        if (resolve) {
            resolve(result);
        }
    }

    // ── Stan widoków: szuflada szczegółów i stos okien ────────────────────────

    const ui = reactive({ view: 'dashboard', drawer: null, modals: [] });
    let modalSequence = 0;

    function openDrawer(type, id) {
        ui.drawer = { type, id: Number(id), key: type + ':' + id + ':' + Date.now() };
    }

    function closeDrawer() {
        ui.drawer = null;
    }

    /**
     * Otwiera okno formularza. Okna tworzą stos, więc z formularza certyfikatu można
     * dodać nowego płatnika bez utraty wpisanych danych.
     */
    function openModal(type, props, onSaved) {
        const key = ++modalSequence;
        ui.modals.push({ key, type, props: props || {}, onSaved: onSaved || null });
        return key;
    }

    function closeModal(key) {
        const index = ui.modals.findIndex((modal) => modal.key === key);
        if (index !== -1) {
            ui.modals.splice(index, 1);
        }
    }

    // ── Wspólne dane list (ładowane leniwie, odświeżane po zapisie) ───────────

    const store = reactive({
        summary: null,
        certificates: { items: [], loaded: false, loading: false, error: '' },
        beneficiaries: { items: [], loaded: false, loading: false, error: '' },
        payers: { items: [], loaded: false, loading: false, error: '' },
        tasks: { items: [], loaded: false, loading: false, error: '' },
        invitations: { items: [], loaded: false, loading: false, error: '' },
        taskStats: null,
        certificateOptions: null,
    });

    function withQuery(url, params) {
        const query = new URLSearchParams();
        Object.keys(params).forEach((name) => {
            const value = params[name];
            if (value !== null && value !== undefined && value !== '' && value !== false) {
                query.append(name, value === true ? '1' : value);
            }
        });
        const queryString = query.toString();
        return queryString ? url + (url.indexOf('?') === -1 ? '?' : '&') + queryString : url;
    }

    const endpoints = boot.endpoints || {};

    async function loadList(name, url, key, force) {
        const state = store[name];
        if (state.loading || (state.loaded && !force)) {
            return state.items;
        }
        state.loading = true;
        state.error = '';
        try {
            const data = await api.get(url);
            state.items = data[key] || [];
            state.loaded = true;
        } catch (error) {
            state.error = error.message;
        } finally {
            state.loading = false;
        }
        return state.items;
    }

    const data = {
        taskFilters: { status: 'open', assignee: '' },
        invitationFilters: { status: 'active', due: false },
        certificates: (force) => loadList('certificates', endpoints.certificates, 'certificates', force),
        beneficiaries: (force) => loadList('beneficiaries', endpoints.beneficiaries, 'beneficiaries', force),
        payers: (force) => loadList('payers', endpoints.payers, 'payers', force),
        tasks: (force) => loadList('tasks', withQuery(endpoints.tasks, data.taskFilters), 'tasks', force),
        invitations: (force) => loadList('invitations', withQuery(endpoints.invitations, data.invitationFilters), 'invitations', force),
        async taskStats() {
            try {
                const result = await api.get(endpoints.tasks, { view: 'stats' });
                store.taskStats = result.stats;
            } catch (error) {
                notifyError(error);
            }
            return store.taskStats;
        },
        async summary() {
            try {
                const result = await api.get(endpoints.dashboard);
                store.summary = result.summary;
            } catch (error) {
                notifyError(error);
            }
            return store.summary;
        },
        async certificateOptions(force) {
            if (store.certificateOptions && !force) {
                return store.certificateOptions;
            }
            const result = await api.get(endpoints.certificates, { view: 'options' });
            store.certificateOptions = result.options;
            return store.certificateOptions;
        },
        /**
         * Po zapisie odświeża listy, które są już w pamięci — reszta załaduje się przy otwarciu widoku.
         */
        refresh(...names) {
            const tasks = [];
            names.forEach((name) => {
                if (name === 'summary') {
                    tasks.push(data.summary());
                } else if (name === 'taskStats') {
                    if (store.taskStats) {
                        tasks.push(data.taskStats());
                    }
                } else if (name === 'options') {
                    store.certificateOptions = null;
                } else if (store[name] && store[name].loaded) {
                    tasks.push(data[name](true));
                }
            });
            return Promise.all(tasks);
        },
    };

    global.CertiSub = {
        boot,
        t,
        can,
        api,
        ApiError,
        format,
        labels,
        badge,
        toasts,
        notify,
        notifyError,
        dismiss,
        confirm,
        confirmState,
        settleConfirm,
        ui,
        openDrawer,
        closeDrawer,
        openModal,
        closeModal,
        store,
        data,
        endpoints,
        withQuery,
        components: {},
    };
})(window);
