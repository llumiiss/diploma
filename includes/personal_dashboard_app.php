<?php

declare(strict_types=1);

/**
 * Panel prywatny — moduł dodatkowy zamrożony decyzją D1 (docs/MAPA_PROJEKTU.md §8).
 * To widok panelu sprzed Etapu 2: utrzymujemy go w działaniu, ale nie rozwijamy.
 * Panel firmowy (dziedzina pracy) jest w includes/dashboard_app.php.
 */
$scope = 'personal';

require dirname(__DIR__) . '/bootstrap.php';

use App\AuthManager;
use App\CertificateHelper;
use App\CertificateManager;
use App\Csrf;
use App\ManagerSubscriptionManager;
use App\PayerManager;
use App\Rbac;
use App\Translator;
use App\UserManager;

$translator = Translator::init();

$auth = new AuthManager();
$auth->requireAuth('dashboard-personal.php' . $translator->querySuffix());

$prefix = 'personal';
$thresholds = CertificateHelper::getThresholds($scope);

$typeOptionsPersonal = [
    ['value' => 'STREAMING', 'label' => __('type.streaming')],
    ['value' => 'MUSIC', 'label' => __('type.music')],
    ['value' => 'GAMING', 'label' => __('type.gaming')],
    ['value' => 'FITNESS', 'label' => __('type.fitness')],
    ['value' => 'CLOUD_STORAGE', 'label' => __('type.cloud_storage')],
    ['value' => 'SAAS', 'label' => __('type.apps')],
];

$config = [
    'title'            => __($prefix . '.title'),
    'assistant_name'   => __($prefix . '.assistant'),
    'subtitle'         => __($prefix . '.subtitle'),
    'header_bg'        => 'bg-violet-900',
    'logo_bg'          => 'bg-violet-500',
    'badge'            => __($prefix . '.badge'),
    'switch_label'     => __($prefix . '.switch'),
    'switch_url'       => 'dashboard.php' . $translator->querySuffix(),
    'commitment_label' => __($prefix . '.commitment'),
    'cost_column'      => __($prefix . '.cost_column'),
    'payers_heading'   => __($prefix . '.payers'),
    'payers_subtitle'  => __($prefix . '.payers_desc'),
    'expiring_label'   => __($prefix . '.expiring_label', ['days' => (string) $thresholds['warning']]),
    'type_options'     => $typeOptionsPersonal,
    'thresholds'       => $thresholds,
];

$certificateManager = new CertificateManager();
$userManager = new UserManager();
$payerManager = new PayerManager();
$managerSubscriptionManager = new ManagerSubscriptionManager();

$currentUser = $auth->currentUser() ?: [
    'first_name' => 'User',
    'last_name'  => '',
    'role'       => Rbac::OPERATOR,
    'email'      => '',
    'id'         => 0,
];

// Kontrola dostępu: MANAGER i ADMIN widzą dane całej organizacji,
// OPERATOR wyłącznie rekordy, których jest właścicielem.
$seesAllRecords = Rbac::seesAllRecords((string) ($currentUser['role'] ?? Rbac::OPERATOR));
$ownerId = $seesAllRecords ? null : (int) $currentUser['id'];

$subscriptions = CertificateHelper::enrich($certificateManager->getAllCertificates($scope, $ownerId));
$stats = $certificateManager->getStatusStats($scope, $ownerId);
$paymentSummary = $certificateManager->getPaymentSummary($scope, $ownerId);
$renewalSummary = $certificateManager->getRenewalSummary($scope, $ownerId);

// Katalog osób i płatników to dane całej organizacji (e-maile, NIP-y) — nie dla OPERATORA.
$users = $seesAllRecords ? $userManager->getAllUsers($scope) : [];
$payers = $seesAllRecords ? $payerManager->getAllPayers($scope) : [];

$managerSubscriptions = $currentUser['id'] > 0
    ? $managerSubscriptionManager->getByUserId((int) $currentUser['id'])
    : [];

$roleLabels = [
    'ADMIN'    => __('role.admin'),
    'MANAGER'  => __('role.manager'),
    'OPERATOR' => __('role.operator'),
];
$displayRole = $roleLabels[$currentUser['role']] ?? $currentUser['role'];

$commitmentValue = $renewalSummary['monthly_spend'];

$i18nJson = json_encode($translator->all(), JSON_UNESCAPED_UNICODE);
$subscriptionsJson = json_encode($subscriptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
$paymentJson = json_encode($paymentSummary, JSON_UNESCAPED_UNICODE);
$renewalJson = json_encode($renewalSummary, JSON_UNESCAPED_UNICODE);
$usersJson = json_encode($users, JSON_UNESCAPED_UNICODE);
$payersJson = json_encode($payers, JSON_UNESCAPED_UNICODE);
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
$scopeJson = json_encode($scope);
$commitmentValueJson = json_encode($commitmentValue);
$localeJson = json_encode($translator->locale());
$managerSubscriptionsJson = json_encode($managerSubscriptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$csrfTokenJson = json_encode(Csrf::token());
$seesAllRecordsJson = json_encode($seesAllRecords);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($translator->locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['title']) ?> — CertiSub Assistant</title>
    <?php require dirname(__DIR__) . '/includes/head.php'; ?>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app" v-cloak class="flex flex-col min-h-screen">

    <header :class="['text-white shadow-md', config.header_bg]">
        <div class="px-6 py-3 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <a href="index.php" class="flex items-center gap-3 hover:opacity-90 transition">
                    <div :class="['w-9 h-9 rounded-lg flex items-center justify-center font-bold text-sm', config.logo_bg]">CS</div>
                    <div>
                        <h1 class="text-lg font-semibold leading-tight">{{ config.assistant_name }}</h1>
                        <span class="text-xs opacity-75">{{ config.badge }}</span>
                    </div>
                </a>
                <a :href="config.switch_url"
                   class="text-xs px-3 py-1 rounded-full bg-white/15 hover:bg-white/25 transition border border-white/20">
                    ↔ {{ config.switch_label }}
                </a>
            </div>

            <div class="flex-1 max-w-xl mx-0 lg:mx-8">
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">🔍</span>
                    <input v-model="globalSearch" @focus="activeView = 'subscriptions'" type="search"
                           :placeholder="t('dash.search')"
                           class="w-full pl-9 pr-4 py-2 rounded-lg text-sm text-slate-800 bg-white/95 border-0 focus:ring-2 focus:ring-white/50 focus:outline-none">
                </div>
            </div>

            <div class="flex items-center gap-3">
                <?php require dirname(__DIR__) . '/includes/lang_switcher.php'; ?>
                <div class="text-sm opacity-90 hidden sm:block">
                    {{ t('dash.role') }}: <strong class="text-white">{{ displayRole }}</strong>
                    &nbsp;|&nbsp; <?= htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?>
                </div>
                <button type="button" @click="openDeleteAccountModal"
                        class="text-xs px-3 py-1.5 rounded-lg bg-red-500/20 hover:bg-red-500/30 transition border border-red-300/40 whitespace-nowrap">
                    <?= htmlspecialchars(__('auth.delete_account')) ?>
                </button>
                <a href="logout.php<?= htmlspecialchars($translator->querySuffix()) ?>"
                   class="text-xs px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 transition border border-white/20 whitespace-nowrap">
                    <?= htmlspecialchars(__('auth.logout')) ?>
                </a>
            </div>
        </div>
    </header>

    <div class="flex flex-1 overflow-hidden">

        <aside class="w-60 bg-white border-r border-slate-200 flex flex-col py-4 shrink-0 overflow-y-auto">
            <p class="px-4 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">{{ t('dash.navigation') }}</p>
            <nav class="flex flex-col gap-1 px-2">
                <button v-for="item in navItems" :key="item.id" @click="activeView = item.id"
                        :class="['nav-item w-full text-left flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium transition', activeView === item.id ? 'active' : 'text-slate-600 hover:bg-slate-100']">
                    <span>{{ item.icon }}</span> {{ item.label }}
                    <span v-if="item.badge" class="ml-auto text-xs bg-red-500 text-white px-1.5 py-0.5 rounded-full">{{ item.badge }}</span>
                </button>
            </nav>
            <div class="px-4 pt-6 mt-6 border-t border-slate-100">
                <p class="text-xs text-slate-400 mb-2">{{ t('dash.quick_summary') }}</p>
                <p class="text-sm font-semibold text-red-600">{{ t('dash.expiring_soon', { count: renewalSummary.expiring_warning }) }}</p>
                <p class="text-sm font-semibold text-amber-600">{{ t('dash.amount_due', { amount: formatMoney(paymentSummary.total_due_amount) }) }}</p>
            </div>
        </aside>

        <main class="flex-1 overflow-y-auto p-6">

            <section v-show="activeView === 'dashboard'">
                <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">{{ config.title }}</h2>
                        <p class="text-slate-500 text-sm mt-1">{{ config.subtitle }}</p>
                        <p v-if="scope === 'personal'" class="text-xs text-violet-600 mt-2 font-medium">
                            {{ t('reminder.payment_due', { days: renewalSummary.warning_days }) }} · {{ t('reminder.renewal_soon', { days: renewalSummary.critical_days }) }}
                        </p>
                        <p v-else class="text-xs text-brand-600 mt-2 font-medium">
                            {{ t('reminder.renewal_due', { days: renewalSummary.warning_days }) }} · {{ t('reminder.critical', { days: renewalSummary.critical_days }) }}
                        </p>
                    </div>
                    <button type="button" @click="openAddModal"
                            class="shrink-0 inline-flex items-center justify-center px-4 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition shadow-sm">
                        + {{ t('manager.add_button') }}
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
                    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
                        <p class="text-xs font-semibold text-slate-400 uppercase">{{ t('dash.total_subscriptions') }}</p>
                        <p class="text-3xl font-bold text-brand-700 mt-1">{{ subscriptions.length }}</p>
                        <p class="text-xs text-slate-400 mt-2">{{ t('dash.active_expired', { active: stats.active, expired: stats.expired }) }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-red-200 p-5 shadow-sm">
                        <p class="text-xs font-semibold text-red-400 uppercase">{{ config.expiring_label }}</p>
                        <p class="text-3xl font-bold text-red-600 mt-1">{{ renewalSummary.expiring_warning }}</p>
                        <p class="text-xs text-slate-400 mt-2">{{ expiringCriticalText }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-amber-200 p-5 shadow-sm">
                        <p class="text-xs font-semibold text-amber-500 uppercase">{{ t('dash.payments_action') }}</p>
                        <p class="text-3xl font-bold text-amber-600 mt-1">{{ paymentSummary.due_soon + paymentSummary.overdue }}</p>
                        <p class="text-xs text-slate-400 mt-2">{{ t('dash.overdue_count', { count: paymentSummary.overdue }) }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-emerald-200 p-5 shadow-sm">
                        <p class="text-xs font-semibold text-emerald-500 uppercase">{{ config.commitment_label }}</p>
                        <p class="text-2xl font-bold text-emerald-700 mt-1">{{ formatMoney(commitmentValue) }}</p>
                        <p class="text-xs text-slate-400 mt-2">{{ scope === 'personal' ? t('dash.monthly_total') : t('dash.annual_total') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
                    <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-amber-700">{{ stats.pending }}</p>
                        <p class="text-xs text-amber-600 font-medium">{{ t('dash.pending') }}</p>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-blue-700">{{ stats.renewal_in_progress }}</p>
                        <p class="text-xs text-blue-600 font-medium">{{ t('dash.renewal_progress') }}</p>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-emerald-700">{{ stats.active }}</p>
                        <p class="text-xs text-emerald-600 font-medium">{{ t('dash.active') }}</p>
                    </div>
                    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-red-700">{{ stats.expired }}</p>
                        <p class="text-xs text-red-600 font-medium">{{ t('dash.expired') }}</p>
                    </div>
                </div>

                <div class="mb-8">
                    <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">📌 {{ t('manager.my_subscriptions') }}</h3>
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('manager.col.service') }}</th>
                                    <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('manager.col.email') }}</th>
                                    <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('manager.col.username') }}</th>
                                    <th class="text-right px-4 py-3 font-semibold text-slate-600">{{ t('manager.col.cost') }}</th>
                                    <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('manager.col.next_payment') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in managerSubscriptions" :key="'mgr-' + item.id"
                                    class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3 font-medium">{{ item.nazwa_uslugi }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ item.mail_subskrypcji || '—' }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ item.username_konta || '—' }}</td>
                                    <td class="px-4 py-3 text-right font-medium">{{ formatMoney(item.koszt_pln) }}</td>
                                    <td class="px-4 py-3">{{ item.data_nastepnej_platnosci }}</td>
                                </tr>
                                <tr v-if="managerSubscriptions.length === 0">
                                    <td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ t('manager.empty') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid lg:grid-cols-2 gap-6 mb-8">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">💳 {{ t('dash.payments_requiring') }}</h3>
                        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 border-b">
                                    <tr>
                                        <th class="text-left px-4 py-2.5 font-semibold text-slate-600">{{ t('dash.subscription') }}</th>
                                        <th class="text-right px-4 py-2.5 font-semibold text-slate-600">{{ t('dash.amount') }}</th>
                                        <th class="text-left px-4 py-2.5 font-semibold text-slate-600">{{ t('dash.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in paymentsDueList" :key="'pay-' + item.id" :class="rowClass(item)"
                                        class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="selectSubscription(item)">
                                        <td class="px-4 py-3">
                                            <div class="font-medium">{{ item.name }}</div>
                                            <div v-if="item.reminder_message" class="text-xs text-amber-600 mt-0.5">{{ item.reminder_message }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold">{{ formatCost(item) }}</td>
                                        <td class="px-4 py-3">
                                            <span :class="paymentBadgeClass(item.display_payment_status)" class="px-2 py-0.5 rounded text-xs font-semibold">{{ item.payment_label }}</span>
                                        </td>
                                    </tr>
                                    <tr v-if="paymentsDueList.length === 0">
                                        <td colspan="3" class="px-4 py-6 text-center text-slate-400">{{ t('dash.no_payments') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="px-4 py-3 bg-amber-50 border-t border-amber-100 flex justify-between text-sm">
                                <span class="text-amber-800 font-medium">{{ t('dash.total_due') }}</span>
                                <span class="font-bold text-amber-900">{{ formatMoney(paymentSummary.total_due_amount) }}</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">🚨 {{ t('dash.priority_renewals') }}</h3>
                        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 border-b">
                                    <tr>
                                        <th class="text-left px-4 py-2.5 font-semibold text-slate-600 w-10">#</th>
                                        <th class="text-left px-4 py-2.5 font-semibold text-slate-600">{{ t('dash.subscription') }}</th>
                                        <th class="text-left px-4 py-2.5 font-semibold text-slate-600">{{ t('dash.renews') }}</th>
                                        <th class="text-left px-4 py-2.5 font-semibold text-slate-600">{{ t('dash.priority') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(item, index) in priorityList" :key="'prio-' + item.id" :class="rowClass(item)"
                                        class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="selectSubscription(item)">
                                        <td class="px-4 py-3 text-slate-400">{{ index + 1 }}</td>
                                        <td class="px-4 py-3">
                                            <div class="font-medium"><span class="mr-1">{{ urgencyIcon(item) }}</span>{{ item.name }}</div>
                                            <div v-if="item.reminder_message" class="text-xs text-slate-500 mt-0.5">{{ item.reminder_message }}</div>
                                        </td>
                                        <td class="px-4 py-3">{{ formatDays(item.days_left) }}</td>
                                        <td class="px-4 py-3">
                                            <span :class="priorityBadgeClass(item.priority)" class="px-2 py-0.5 rounded text-xs font-semibold">{{ item.priority_label }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">
                        📅 {{ t('dash.renewal_timeline') }} <span v-if="selectedItem" class="text-brand-600 normal-case font-normal">— {{ selectedItem.name }}</span>
                    </h3>
                    <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                        <div v-if="selectedItem" class="space-y-4">
                            <div class="flex items-center gap-2 text-sm flex-wrap">
                                <span class="bg-slate-100 px-3 py-1 rounded-full">{{ t('dash.subscribed') }}</span>
                                <span class="text-slate-300">────</span>
                                <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full font-medium">{{ selectedItem.last_payment_date || 'N/A' }}</span>
                                <span class="text-slate-300">────►</span>
                                <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-medium">{{ t('dash.next_payment') }}</span>
                                <span class="text-slate-300">────►</span>
                                <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full font-medium">{{ t('dash.renews_on', { date: selectedItem.expiry_date }) }}</span>
                            </div>
                            <p v-if="selectedItem.reminder_message" class="text-sm font-medium text-amber-700 bg-amber-50 rounded-lg px-3 py-2">{{ selectedItem.reminder_message }}</p>
                            <div class="grid sm:grid-cols-3 gap-4 text-sm pt-2 border-t border-slate-100">
                                <div><span class="text-slate-400">{{ t('dash.owner') }}:</span> <strong>{{ selectedItem.owner_name }}</strong></div>
                                <div><span class="text-slate-400">{{ t('dash.payer') }}:</span> <strong>{{ selectedItem.company_name }}</strong></div>
                                <div><span class="text-slate-400">{{ t('dash.price') }}:</span> <strong>{{ formatCost(selectedItem) }}</strong></div>
                            </div>
                            <p v-if="selectedItem.notes" class="text-sm text-slate-500 bg-slate-50 rounded-lg p-3">{{ selectedItem.notes }}</p>
                        </div>
                        <p v-else class="text-slate-400 text-sm">{{ t('dash.click_timeline') }}</p>
                    </div>
                </div>
            </section>

            <section v-show="activeView === 'subscriptions' || activeView === 'todo'">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-900">{{ activeView === 'todo' ? t('dash.todo_list') : t('dash.all_subscriptions') }}</h2>
                    <p class="text-slate-500 text-sm mt-1">{{ activeView === 'todo' ? todoDescription : t('dash.all_desc') }}</p>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 p-4 mb-4 flex flex-col lg:flex-row gap-3">
                    <input v-model="globalSearch" type="search" :placeholder="t('dash.search_filter')"
                           class="flex-1 px-4 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <select v-model="filterType" class="px-4 py-2 border border-slate-300 rounded-lg text-sm bg-white">
                        <option value="">{{ t('dash.filter.all_types') }}</option>
                        <option v-for="opt in config.type_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <select v-model="filterPayment" class="px-4 py-2 border border-slate-300 rounded-lg text-sm bg-white">
                        <option value="">{{ t('dash.filter.all_payments') }}</option>
                        <option value="due_soon">{{ t('dash.filter.due_soon') }}</option>
                        <option value="overdue">{{ t('dash.filter.overdue') }}</option>
                        <option value="paid">{{ t('dash.filter.paid') }}</option>
                    </select>
                    <select v-model="filterPriority" class="px-4 py-2 border border-slate-300 rounded-lg text-sm bg-white">
                        <option value="">{{ t('dash.filter.all_priorities') }}</option>
                        <option value="critical">{{ t('dash.filter.critical', { days: renewalSummary.critical_days }) }}</option>
                        <option value="warning">{{ t('dash.filter.warning', { days: renewalSummary.warning_days }) }}</option>
                        <option value="expired">{{ t('dash.filter.expired') }}</option>
                    </select>
                </div>
                <p class="text-xs text-slate-400 mb-3">{{ t('dash.showing', { shown: filteredSubscriptions.length, total: subscriptions.length }) }}</p>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-x-auto">
                    <table class="w-full text-sm min-w-[900px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.subscription') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.type') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.owner') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.payer') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.renewal_date') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.days_left') }}</th>
                                <th class="text-right px-4 py-3 font-semibold text-slate-600">{{ config.cost_column }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.payment') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in filteredSubscriptions" :key="item.id" :class="rowClass(item)"
                                class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer" @click="selectSubscription(item)">
                                <td class="px-4 py-3">
                                    <div class="font-medium"><span class="mr-1">{{ urgencyIcon(item) }}</span>{{ item.name }}</div>
                                    <div v-if="item.reminder_message" class="text-xs text-amber-600">{{ item.reminder_message }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ item.type_label }}</td>
                                <td class="px-4 py-3">{{ item.owner_name }}</td>
                                <td class="px-4 py-3">{{ item.company_name }}</td>
                                <td class="px-4 py-3">{{ item.expiry_date }}</td>
                                <td class="px-4 py-3" :class="daysLeftClass(item)">{{ formatDays(item.days_left) }}</td>
                                <td class="px-4 py-3 text-right font-medium">{{ formatCost(item) }}</td>
                                <td class="px-4 py-3">
                                    <span :class="paymentBadgeClass(item.display_payment_status)" class="px-2 py-0.5 rounded text-xs font-semibold whitespace-nowrap">{{ item.payment_label }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700">{{ item.status_label }}</span>
                                </td>
                            </tr>
                            <tr v-if="filteredSubscriptions.length === 0">
                                <td colspan="9" class="px-4 py-10 text-center text-slate-400">{{ t('dash.no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-show="activeView === 'users'">
                <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ t('dash.users') }}</h2>
                <p class="text-slate-500 text-sm mb-6">{{ t('dash.users_desc', { scope: config.badge }) }}</p>
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.name') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.email') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.status') }}</th>
                                <th class="text-right px-4 py-3 font-semibold text-slate-600">{{ t('dash.subscriptions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users" :key="user.id" class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium">{{ user.first_name }} {{ user.last_name }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ user.email }}</td>
                                <td class="px-4 py-3"><span class="px-2 py-0.5 bg-brand-100 text-brand-800 rounded text-xs font-semibold">{{ user.role }}</span></td>
                                <td class="px-4 py-3 text-right">{{ user.subscription_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-show="activeView === 'payers'">
                <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ config.payers_heading }}</h2>
                <p class="text-slate-500 text-sm mb-6">{{ config.payers_subtitle }}</p>
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.name') }}</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">{{ t('dash.contact') }}</th>
                                <th class="text-right px-4 py-3 font-semibold text-slate-600">{{ t('dash.subscriptions') }}</th>
                                <th class="text-right px-4 py-3 font-semibold text-slate-600">{{ t('dash.total_cost') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="payer in payers" :key="payer.id" class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium">{{ payer.company_name }}</td>
                                <td class="px-4 py-3">{{ payer.contact_person }}</td>
                                <td class="px-4 py-3 text-right">{{ payer.subscription_count }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ formatMoney(parseFloat(payer.total_annual_cost)) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>

    <!-- Modal: dodaj subskrypcję -->
    <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/50" @click="closeAddModal"></div>
        <div class="relative bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-lg p-6">
            <h3 class="text-xl font-bold text-slate-900 mb-1">{{ t('manager.modal_title') }}</h3>
            <p class="text-sm text-slate-500 mb-6">{{ t('manager.modal_desc') }}</p>

            <div v-if="addFormError" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                {{ addFormError }}
            </div>

            <form @submit.prevent="saveSubscription" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">{{ t('manager.field.service') }}</label>
                    <input v-model="addForm.nazwa_uslugi" type="text" required
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">{{ t('manager.field.email') }}</label>
                    <input v-model="addForm.mail_subskrypcji" type="email"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">{{ t('manager.field.username') }}</label>
                    <input v-model="addForm.username_konta" type="text"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">{{ t('manager.field.cost') }}</label>
                        <input v-model="addForm.koszt_pln" type="number" min="0" step="0.01" required
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">{{ t('manager.field.next_payment') }}</label>
                        <input v-model="addForm.data_nastepnej_platnosci" type="date" required
                               class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="button" @click="closeAddModal"
                            class="flex-1 px-4 py-2.5 border border-slate-300 text-slate-700 font-medium rounded-lg hover:bg-slate-50 transition">
                        {{ t('manager.cancel') }}
                    </button>
                    <button type="submit" :disabled="addFormSaving"
                            class="flex-1 px-4 py-2.5 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition disabled:opacity-60">
                        {{ addFormSaving ? t('manager.saving') : t('manager.save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: usuń konto -->
    <div v-if="showDeleteAccountModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/50" @click="closeDeleteAccountModal"></div>
        <div class="relative bg-white rounded-2xl border border-slate-200 shadow-xl w-full max-w-md p-6">
            <div class="w-12 h-12 bg-red-100 text-red-600 rounded-xl flex items-center justify-center text-2xl mb-4">⚠️</div>
            <h3 class="text-xl font-bold text-slate-900 mb-2">{{ t('auth.delete_account_title') }}</h3>
            <p class="text-sm text-slate-600 leading-relaxed mb-6">{{ t('auth.delete_account_desc') }}</p>

            <div v-if="deleteAccountError" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                {{ deleteAccountError }}
            </div>

            <div class="flex gap-3">
                <button type="button" @click="closeDeleteAccountModal" :disabled="deleteAccountSaving"
                        class="flex-1 px-4 py-2.5 border border-slate-300 text-slate-700 font-medium rounded-lg hover:bg-slate-50 transition disabled:opacity-60">
                    {{ t('auth.delete_account_cancel') }}
                </button>
                <button type="button" @click="confirmDeleteAccount" :disabled="deleteAccountSaving"
                        class="flex-1 px-4 py-2.5 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition disabled:opacity-60">
                    {{ deleteAccountSaving ? t('manager.saving') : t('auth.delete_account_confirm') }}
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/vue.global.prod.js"></script>
<script>
const { createApp } = Vue;

createApp({
    data() {
        return {
            locale: <?= $localeJson ?>,
            i18n: <?= $i18nJson ?>,
            scope: <?= $scopeJson ?>,
            config: <?= $configJson ?>,
            commitmentValue: <?= $commitmentValueJson ?>,
            activeView: 'dashboard',
            globalSearch: '',
            filterType: '',
            filterPayment: '',
            filterPriority: '',
            selectedItem: null,
            displayRole: <?= json_encode($displayRole) ?>,
            subscriptions: <?= $subscriptionsJson ?>,
            stats: <?= $statsJson ?>,
            paymentSummary: <?= $paymentJson ?>,
            renewalSummary: <?= $renewalJson ?>,
            users: <?= $usersJson ?>,
            payers: <?= $payersJson ?>,
            managerSubscriptions: <?= $managerSubscriptionsJson ?>,
            csrfToken: <?= $csrfTokenJson ?>,
            canSeeDirectory: <?= $seesAllRecordsJson ?>,
            showAddModal: false,
            showDeleteAccountModal: false,
            deleteAccountSaving: false,
            deleteAccountError: '',
            addFormSaving: false,
            addFormError: '',
            addForm: {
                nazwa_uslugi: '',
                mail_subskrypcji: '',
                username_konta: '',
                koszt_pln: '',
                data_nastepnej_platnosci: '',
            },
        };
    },
    computed: {
        expiringCriticalText() {
            const key = this.scope === 'personal' ? 'personal.expiring_critical' : 'corporate.expiring_critical';
            return this.t(key, {
                count: this.renewalSummary.expiring_critical,
                days: this.renewalSummary.critical_days,
            });
        },
        todoDescription() {
            const key = this.scope === 'personal' ? 'dash.todo_desc_personal' : 'dash.todo_desc_corporate';
            return this.t(key, { days: this.renewalSummary.warning_days });
        },
        navItems() {
            const due = this.paymentSummary.due_soon + this.paymentSummary.overdue;
            const items = [
                { id: 'dashboard', icon: '📊', label: this.t('dash.nav.dashboard'), badge: null },
                { id: 'todo', icon: '✅', label: this.t('dash.nav.todo'), badge: this.renewalSummary.expiring_warning || null },
                { id: 'subscriptions', icon: '📋', label: this.t('dash.nav.subscriptions'), badge: null },
            ];

            // Katalog osób i płatników widzą tylko role MANAGER i ADMIN.
            if (this.canSeeDirectory) {
                items.push({ id: 'users', icon: '👥', label: this.t('dash.nav.users'), badge: null });
                items.push({ id: 'payers', icon: '🏢', label: this.t('dash.nav.payers'), badge: due || null });
            }

            return items;
        },
        priorityList() {
            return [...this.subscriptions]
                .filter(s => s.priority === 'expired' || s.priority === 'critical' || s.priority === 'warning')
                .sort((a, b) => a.days_left - b.days_left)
                .slice(0, 6);
        },
        paymentsDueList() {
            return this.subscriptions.filter(s =>
                s.display_payment_status === 'due_soon' || s.display_payment_status === 'overdue'
            );
        },
        filteredSubscriptions() {
            let list = this.subscriptions;
            const warnDays = this.renewalSummary.warning_days;

            if (this.activeView === 'todo') {
                list = list.filter(s =>
                    s.status === 'pending' ||
                    s.days_left <= warnDays ||
                    s.display_payment_status === 'overdue' ||
                    s.priority === 'critical' ||
                    s.priority === 'warning'
                );
            }

            if (this.filterType) list = list.filter(s => s.certificate_type === this.filterType);
            if (this.filterPayment) list = list.filter(s => s.display_payment_status === this.filterPayment);
            if (this.filterPriority) list = list.filter(s => s.priority === this.filterPriority);

            const q = this.globalSearch.trim().toLowerCase();
            if (!q) return list;

            return list.filter(s =>
                s.name.toLowerCase().includes(q) ||
                s.owner_name.toLowerCase().includes(q) ||
                s.company_name.toLowerCase().includes(q) ||
                s.type_label.toLowerCase().includes(q) ||
                (s.reminder_message && s.reminder_message.toLowerCase().includes(q))
            );
        },
    },
    methods: {
        t(key, replace = {}) {
            let text = this.i18n[key] || key;
            Object.entries(replace).forEach(([k, v]) => {
                text = text.replace(':' + k, String(v));
            });
            return text;
        },
        selectSubscription(item) { this.selectedItem = item; },
        formatDays(days) {
            if (days < 0) return this.t('days.overdue', { count: Math.abs(days) });
            if (days === 0) return this.t('days.today');
            if (days === 1) return this.t('days.one');
            return this.t('days.many', { count: days });
        },
        formatMoney(amount, currency = 'PLN') {
            const loc = this.locale === 'pl' ? 'pl-PL' : 'en-US';
            return Number(amount).toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;
        },
        formatCost(item) {
            const suffix = item.billing_cycle === 'monthly' ? '/mo' : item.billing_cycle === 'annual' ? '/yr' : '';
            return this.formatMoney(item.annual_cost, item.currency) + suffix;
        },
        urgencyIcon(item) {
            if (item.priority === 'expired' || item.priority === 'critical') return '🔴';
            if (item.priority === 'warning') return '🟡';
            return '';
        },
        rowClass(item) {
            if (item.priority === 'expired' || item.priority === 'critical') return 'row-critical';
            if (item.priority === 'warning') return 'row-warning';
            return '';
        },
        daysLeftClass(item) {
            const c = item.thresholds?.critical ?? this.renewalSummary.critical_days;
            const w = item.thresholds?.warning ?? this.renewalSummary.warning_days;
            if (item.days_left < 0) return 'text-red-700 font-semibold';
            if (item.days_left <= c) return 'text-red-600 font-semibold';
            if (item.days_left <= w) return 'text-amber-600 font-medium';
            return '';
        },
        priorityBadgeClass(priority) {
            return { critical: 'bg-red-100 text-red-800', warning: 'bg-amber-100 text-amber-800', expired: 'bg-red-200 text-red-900', ok: 'bg-emerald-100 text-emerald-800' }[priority] || 'bg-emerald-100 text-emerald-800';
        },
        paymentBadgeClass(status) {
            return { paid: 'bg-emerald-100 text-emerald-800', due_soon: 'bg-amber-100 text-amber-800', overdue: 'bg-red-100 text-red-800', not_applicable: 'bg-slate-100 text-slate-600' }[status] || 'bg-slate-100 text-slate-600';
        },
        openAddModal() {
            this.resetAddForm();
            this.showAddModal = true;
        },
        closeAddModal() {
            if (this.addFormSaving) return;
            this.showAddModal = false;
            this.addFormError = '';
        },
        openDeleteAccountModal() {
            this.deleteAccountError = '';
            this.showDeleteAccountModal = true;
        },
        closeDeleteAccountModal() {
            if (this.deleteAccountSaving) return;
            this.showDeleteAccountModal = false;
            this.deleteAccountError = '';
        },
        async confirmDeleteAccount() {
            this.deleteAccountSaving = true;
            this.deleteAccountError = '';

            try {
                const response = await fetch('api/delete_account.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                    },
                    body: JSON.stringify({ confirm: true }),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    this.deleteAccountError = data.message || this.t('auth.delete_account_error');
                    return;
                }

                window.location.href = data.redirect || 'index.php';
            } catch (err) {
                this.deleteAccountError = this.t('auth.delete_account_error');
            } finally {
                this.deleteAccountSaving = false;
            }
        },
        resetAddForm() {
            this.addForm = {
                nazwa_uslugi: '',
                mail_subskrypcji: '',
                username_konta: '',
                koszt_pln: '',
                data_nastepnej_platnosci: '',
            };
            this.addFormError = '';
        },
        async saveSubscription() {
            this.addFormSaving = true;
            this.addFormError = '';

            try {
                const response = await fetch('api/add_subscription.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                    },
                    body: JSON.stringify({
                        nazwa_uslugi: this.addForm.nazwa_uslugi.trim(),
                        mail_subskrypcji: this.addForm.mail_subskrypcji.trim(),
                        username_konta: this.addForm.username_konta.trim(),
                        koszt_pln: this.addForm.koszt_pln,
                        data_nastepnej_platnosci: this.addForm.data_nastepnej_platnosci,
                    }),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    this.addFormError = (data.errors && Object.values(data.errors)[0]) || data.message || this.t('manager.error_generic');
                    return;
                }

                this.managerSubscriptions.push(data.subscription);
                this.managerSubscriptions.sort((a, b) =>
                    a.data_nastepnej_platnosci.localeCompare(b.data_nastepnej_platnosci)
                );
                this.showAddModal = false;
                this.resetAddForm();
            } catch (err) {
                this.addFormError = this.t('manager.error_generic');
            } finally {
                this.addFormSaving = false;
            }
        },
    },
    mounted() {
        const urgent = this.subscriptions.find(s => s.priority === 'critical' || s.priority === 'warning');
        this.selectedItem = urgent || this.subscriptions[0] || null;
    },
}).mount('#app');
</script>

</body>
</html>
