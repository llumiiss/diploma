<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\AuthManager;
use App\Csrf;
use App\Translator;

$translator = Translator::init();
$langQ = $translator->querySuffix();
$auth = new AuthManager();

$redirect = $auth->sanitizeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard.php');

if ($auth->isAuthenticated()) {
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['cancel'])) {
    $auth->clearPendingOtp();
}

$step = 'register';
$firstName = '';
$lastName = '';
$email = '';
$error = '';
$success = '';

$loginUrl = 'login.php?cancel=1&redirect=' . urlencode($redirect) . ($langQ !== '' ? '&' . substr($langQ, 1) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $error = __('auth.error.csrf');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'register') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $redirect = $auth->sanitizeRedirect((string) ($_POST['redirect'] ?? $redirect));
            $result = $auth->register($firstName, $lastName, $email, $redirect);

            if ($result['ok']) {
                $step = 'verify';
                $success = __('auth.code_sent');
            } else {
                $error = __($result['error'] ?? 'auth.error.generic');
            }
        } elseif ($action === 'verify_otp') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $code = trim((string) ($_POST['code'] ?? ''));
            $redirect = $auth->sanitizeRedirect((string) ($_POST['redirect'] ?? $redirect));
            $result = $auth->verifyOtp($email, $code);

            if ($result['ok']) {
                header('Location: ' . ($result['redirect'] ?? $redirect));
                exit;
            }

            $step = 'verify';
            $error = __($result['error'] ?? 'auth.error.generic');
        } elseif ($action === 'back') {
            $auth->clearPendingOtp();
            $redirect = $auth->sanitizeRedirect((string) ($_POST['redirect'] ?? $redirect));
            $step = 'register';
            $email = '';
            $success = '';
            $error = '';
        }
    }
}

if ($step === 'register' && $email === '' && !isset($_GET['cancel'])) {
    $pending = $auth->getPendingEmail();
    if ($pending !== null) {
        $step = 'verify';
        $email = $pending;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($translator->locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('auth.register_title')) ?> — CertiSub Assistant</title>
    <?php require __DIR__ . '/includes/head.php'; ?>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex flex-col">

<header class="bg-white border-b border-slate-200">
    <div class="max-w-lg mx-auto px-6 py-4 flex items-center justify-between">
        <a href="index.php<?= htmlspecialchars($langQ) ?>" class="flex items-center gap-3">
            <div class="w-10 h-10 bg-brand-600 rounded-xl flex items-center justify-center text-white font-bold text-sm shadow-sm">CS</div>
            <span class="font-semibold text-brand-900"><?= htmlspecialchars(__('landing.brand')) ?></span>
        </a>
        <?php $variant = 'light'; require __DIR__ . '/includes/lang_switcher.php'; ?>
    </div>
</header>

<main class="flex-1 flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4">👤</div>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars(__('auth.register_title')) ?></h1>
                <p class="text-slate-500 text-sm mt-2"><?= htmlspecialchars($step === 'verify' ? __('auth.subtitle_verify') : __('auth.register_subtitle')) ?></p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 'register'): ?>
                <form method="post" class="space-y-4">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="first_name" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.first_name')) ?></label>
                            <input type="text" id="first_name" name="first_name" required autofocus
                                   value="<?= htmlspecialchars($firstName) ?>"
                                   class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                        <div>
                            <label for="last_name" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.last_name')) ?></label>
                            <input type="text" id="last_name" name="last_name" required
                                   value="<?= htmlspecialchars($lastName) ?>"
                                   class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.email_label')) ?></label>
                        <input type="email" id="email" name="email" required
                               value="<?= htmlspecialchars($email) ?>"
                               placeholder="<?= htmlspecialchars(__('landing.how.email_placeholder')) ?>"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                    <button type="submit"
                            class="w-full px-4 py-3 bg-emerald-600 text-white font-semibold rounded-lg hover:bg-emerald-700 transition">
                        <?= htmlspecialchars(__('auth.register_submit')) ?>
                    </button>
                </form>
                <p class="text-center mt-6 text-sm text-slate-500">
                    <?= htmlspecialchars(__('auth.have_account')) ?>
                    <a href="<?= htmlspecialchars($loginUrl) ?>"
                       class="text-brand-600 font-medium hover:underline"><?= htmlspecialchars(__('auth.login_link')) ?></a>
                </p>
            <?php else: ?>
                <form method="post" class="space-y-4">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.email_label')) ?></label>
                        <p class="px-4 py-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-700"><?= htmlspecialchars($email) ?></p>
                    </div>
                    <div>
                        <label for="code" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.code_label')) ?></label>
                        <input type="text" id="code" name="code" required autofocus
                               inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                               placeholder="000000"
                               class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm text-center text-lg tracking-widest font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                    <button type="submit"
                            class="w-full px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
                        <?= htmlspecialchars(__('auth.verify_register')) ?>
                    </button>
                </form>

                <div class="mt-4 space-y-3 text-center text-sm">
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="back">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                        <button type="submit" class="text-slate-500 hover:text-brand-600 transition">
                            ← <?= htmlspecialchars(__('auth.back_to_register')) ?>
                        </button>
                    </form>
                    <p class="text-slate-500">
                        <?= htmlspecialchars(__('auth.have_account')) ?>
                        <a href="<?= htmlspecialchars($loginUrl) ?>" class="text-brand-600 font-medium hover:underline">
                            <?= htmlspecialchars(__('auth.login_link')) ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-center mt-6">
            <a href="index.php<?= htmlspecialchars($langQ) ?>" class="text-sm text-slate-500 hover:text-brand-600 transition">
                ← <?= htmlspecialchars(__('auth.back_home')) ?>
            </a>
        </p>
    </div>
</main>

</body>
</html>
