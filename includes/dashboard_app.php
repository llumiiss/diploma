<?php

declare(strict_types=1);

/**
 * Panel firmowy — ewidencja certyfikatów, użytkowników certyfikatów i płatników (dziedzina pracy).
 *
 * PHP sprawdza sesję i przekazuje dane startowe (tłumaczenia, token CSRF, konto, uprawnienia).
 * Widoki to komponenty Vue w assets/js, a dane przychodzą z API (api/*.php), które samo
 * pilnuje ról — ukrycie przycisku w przeglądarce nie jest kontrolą dostępu.
 *
 * Panel prywatny (moduł zamrożony, D1) ma osobny widok: includes/personal_dashboard_app.php.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\AuthManager;
use App\CertificateHelper;
use App\Csrf;
use App\Rbac;
use App\Translator;

$translator = Translator::init();
$langQ = $translator->querySuffix();

$auth = new AuthManager();
$auth->requireAuth('dashboard.php' . $langQ);
$user = $auth->currentUser();
if ($user === false) {
    header('Location: login.php' . $langQ);
    exit;
}

$role = Rbac::normalize((string) $user['role']);
$fullName = trim($user['first_name'] . ' ' . $user['last_name']);

$boot = [
    'locale'      => $translator->locale(),
    'i18n'        => $translator->all(),
    'csrf'        => Csrf::token(),
    'user'        => [
        'id'         => (int) $user['id'],
        'first_name' => (string) $user['first_name'],
        'last_name'  => (string) $user['last_name'],
        'name'       => $fullName,
        'email'      => (string) $user['email'],
        'role'       => $role,
    ],
    'permissions' => Rbac::permissionsFor($role),
    'thresholds'  => CertificateHelper::getThresholds('corporate'),
    'endpoints'   => [
        'certificates'   => 'api/certificates.php',
        'beneficiaries'  => 'api/beneficiaries.php',
        'payers'         => 'api/payers.php',
        'accounts'       => 'api/accounts.php',
        'dashboard'      => 'api/dashboard.php',
        'tasks'          => 'api/tasks.php',
        'invitations'    => 'api/invitations.php',
        'templates'      => 'api/templates.php',
        'attachments'    => 'api/attachments.php',
        'settings'       => 'api/settings.php',
        'reports'        => 'api/reports.php',
        'search'         => 'api/search.php',
        'events'         => 'api/events.php',
        'delete_account' => 'api/delete_account.php',
    ],
    'links'       => [
        'login'    => 'login.php' . $langQ,
        'logout'   => 'logout.php' . $langQ,
        'home'     => 'index.php' . $langQ,
        'personal' => 'dashboard-personal.php' . $langQ,
    ],
];

// JSON_HEX_* uniemożliwia zamknięcie znacznika <script> danymi z bazy (§5 pkt 15).
$bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

$scripts = [
    'assets/js/core.js',
    'assets/js/components.js',
    'assets/js/views/dashboard.js',
    'assets/js/views/certificates.js',
    'assets/js/views/beneficiaries.js',
    'assets/js/views/payers.js',
    'assets/js/views/archive.js',
    'assets/js/views/accounts.js',
    'assets/js/views/tasks.js',
    'assets/js/views/invitations.js',
    'assets/js/views/templates.js',
    'assets/js/views/search.js',
    'assets/js/views/reports.js',
    'assets/js/views/events.js',
    'assets/js/app.js',
];

// Wersja pliku w adresie wymusza pobranie nowej wersji skryptu po zmianie.
$assetUrl = static function (string $path): string {
    $file = dirname(__DIR__) . '/' . $path;

    return $path . '?v=' . (is_file($file) ? (string) filemtime($file) : '0');
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($translator->locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('corporate.title')) ?> — CertiSub Assistant</title>
    <?php require dirname(__DIR__) . '/includes/head.php'; ?>
    <style type="text/tailwindcss">
        @layer components {
            .btn-primary { @apply inline-flex items-center justify-center gap-2 px-4 py-2 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition disabled:opacity-60 disabled:cursor-not-allowed; }
            .btn-secondary { @apply inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50 transition disabled:opacity-60 disabled:cursor-not-allowed; }
            .btn-danger { @apply inline-flex items-center justify-center gap-2 px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition disabled:opacity-60 disabled:cursor-not-allowed; }
            .btn-ghost { @apply inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md text-slate-600 hover:bg-slate-100 transition disabled:opacity-50; }
            .input { @apply w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500; }
            .input-error { @apply border-red-400 focus:ring-red-400; }
            .card { @apply bg-white rounded-xl border border-slate-200 shadow-sm; }
            .th { @apply text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide whitespace-nowrap; }
            .td { @apply px-4 py-3 align-top; }
            .badge { @apply inline-block px-2 py-0.5 rounded text-xs font-semibold whitespace-nowrap; }
        }
    </style>
    <style>
        /* Wydruk kart raportowych i harmonogramu: bez nawigacji i przycisków, treść na całą stronę. */
        @media print {
            body > header, #app aside, .no-print { display: none !important; }
            html, body { height: auto !important; overflow: visible !important; background: #fff !important; }
            #app, #app > div, main { display: block !important; overflow: visible !important; height: auto !important; min-height: 0 !important; }
            main { padding: 0 !important; }
            .card { box-shadow: none !important; break-inside: avoid; }
            table { break-inside: auto; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden">

<header class="bg-brand-900 text-white shadow-md shrink-0">
    <div class="px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3 flex-wrap">
            <a href="<?= htmlspecialchars($boot['links']['home']) ?>" class="flex items-center gap-3 hover:opacity-90 transition">
                <div class="w-9 h-9 rounded-lg bg-brand-500 flex items-center justify-center font-bold text-sm">CS</div>
                <div>
                    <p class="text-lg font-semibold leading-tight"><?= htmlspecialchars(__('corporate.assistant')) ?></p>
                    <p class="text-xs opacity-75"><?= htmlspecialchars(__('corporate.subtitle')) ?></p>
                </div>
            </a>
            <a href="<?= htmlspecialchars($boot['links']['personal']) ?>"
               class="text-xs px-3 py-1 rounded-full bg-white/15 hover:bg-white/25 transition border border-white/20">
                ↔ <?= htmlspecialchars(__('corporate.switch')) ?>
            </a>
        </div>
        <div class="flex items-center gap-3">
            <?php require dirname(__DIR__) . '/includes/lang_switcher.php'; ?>
            <div class="text-sm opacity-90 hidden sm:block text-right leading-tight">
                <div class="font-semibold"><?= htmlspecialchars($fullName) ?></div>
                <div class="text-xs opacity-80"><?= htmlspecialchars(__('role.' . strtolower($role))) ?></div>
            </div>
            <a href="<?= htmlspecialchars($boot['links']['logout']) ?>"
               class="text-xs px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 transition border border-white/20 whitespace-nowrap">
                <?= htmlspecialchars(__('auth.logout')) ?>
            </a>
        </div>
    </div>
</header>

<div id="app" v-cloak class="flex-1 flex flex-col min-h-0"></div>

<noscript>
    <p class="m-6 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm"><?= htmlspecialchars(__('common.noscript')) ?></p>
</noscript>

<script src="https://unpkg.com/vue@3.5.13/dist/vue.global.prod.js"></script>
<script>window.CERTISUB_BOOT = <?= $bootJson ?>;</script>
<?php foreach ($scripts as $script): ?>
<script src="<?= htmlspecialchars($assetUrl($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
