<?php

declare(strict_types=1);

/**
 * Panel aplikacji — ewidencja certyfikatów, użytkowników certyfikatów i płatników.
 *
 * PHP sprawdza sesję i przekazuje dane startowe (tłumaczenia, token CSRF, konto, uprawnienia).
 * Widoki to komponenty Vue w assets/js, a dane przychodzą z API (api/*.php), które samo
 * pilnuje ról — ukrycie przycisku w przeglądarce nie jest kontrolą dostępu.
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
    'roles'       => Rbac::roles(),
    'thresholds'  => CertificateHelper::getThresholds(),
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
        'export'         => 'api/export.php',
        'import'         => 'api/import.php',
        'delete_account' => 'api/delete_account.php',
    ],
    'links'       => [
        'login'  => 'login.php' . $langQ,
        'logout' => 'logout.php' . $langQ,
        'home'   => 'index.php' . $langQ,
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
    'assets/js/views/exchange.js',
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

<!-- Szkielet widoczny do momentu zamontowania Vue; komponent nadpisuje zawartość (§2.5). -->
<div id="app" class="flex-1 flex flex-col min-h-0">
    <div class="flex flex-1 min-h-0" role="status" aria-label="<?= htmlspecialchars(__('common.loading')) ?>">
        <aside class="hidden md:flex w-64 bg-white border-r border-slate-200 flex-col shrink-0 p-4 gap-2">
            <div class="skeleton h-3 w-24 mb-2"></div>
            <div class="skeleton h-9 w-full rounded-lg" style="opacity: .9"></div>
            <div class="skeleton h-9 w-full rounded-lg" style="opacity: .8"></div>
            <div class="skeleton h-9 w-full rounded-lg" style="opacity: .7"></div>
            <div class="skeleton h-9 w-full rounded-lg" style="opacity: .6"></div>
            <div class="skeleton h-9 w-full rounded-lg" style="opacity: .5"></div>
        </aside>
        <main class="flex-1 p-4 sm:p-6 min-w-0">
            <div class="skeleton h-10 w-full max-w-2xl mb-5 rounded-lg"></div>
            <div class="skeleton h-8 w-64 mb-6"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <div class="card p-5"><div class="skeleton h-3 w-2/3"></div><div class="skeleton h-8 w-1/3 mt-3"></div></div>
                <div class="card p-5"><div class="skeleton h-3 w-2/3"></div><div class="skeleton h-8 w-1/3 mt-3"></div></div>
                <div class="card p-5"><div class="skeleton h-3 w-2/3"></div><div class="skeleton h-8 w-1/3 mt-3"></div></div>
                <div class="card p-5"><div class="skeleton h-3 w-2/3"></div><div class="skeleton h-8 w-1/3 mt-3"></div></div>
            </div>
            <div class="card p-5"><div class="skeleton h-4 w-1/3"></div><div class="skeleton h-40 w-full mt-4"></div></div>
        </main>
    </div>
</div>

<noscript>
    <p class="m-6 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm"><?= htmlspecialchars(__('common.noscript')) ?></p>
</noscript>

<script src="<?= htmlspecialchars($assetUrl('assets/vendor/vue.global.prod.js')) ?>"></script>
<script>window.CERTISUB_BOOT = <?= $bootJson ?>;</script>
<?php foreach ($scripts as $script): ?>
<script src="<?= htmlspecialchars($assetUrl($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
