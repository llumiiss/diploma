<?php

declare(strict_types=1);

/**
 * Logowanie: e-mail + hasło (Etap 9). Adres musi być wcześniej potwierdzony linkiem
 * z wiadomości — patrz register.php i verify-email.php.
 *
 * Formularz obsługuje też dwie sytuacje poboczne:
 *   - konto z niepotwierdzonym adresem → przycisk „wyślij link ponownie”,
 *   - „nie pamiętam hasła” → wiadomość z linkiem do ustawienia nowego hasła.
 */

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

$ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null;

$email = '';
$error = '';
$success = '';
$info = '';
$showResendVerification = false;
$mode = ($_GET['mode'] ?? '') === 'forgot' ? 'forgot' : 'login';

if (isset($_GET['registration_closed'])) {
    $info = __('auth.registration_closed');
} elseif (isset($_GET['verified'])) {
    $success = __('auth.verified_now_sign_in');
} elseif (isset($_GET['password_set'])) {
    $success = __('auth.password_set_now_sign_in');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $error = __('auth.error.csrf');
    } else {
        $action = $_POST['action'] ?? '';
        $email = trim((string) ($_POST['email'] ?? ''));
        $redirect = $auth->sanitizeRedirect((string) ($_POST['redirect'] ?? $redirect));

        if ($action === 'login') {
            $result = $auth->attemptLogin($email, (string) ($_POST['password'] ?? ''), $redirect, $ip);

            if ($result['ok']) {
                header('Location: ' . ($result['redirect'] ?? $redirect));
                exit;
            }

            $error = __($result['error'] ?? 'auth.error.generic');
            $showResendVerification = ($result['error'] ?? '') === 'auth.error.email_not_verified';
        } elseif ($action === 'resend_verification') {
            $result = $auth->resendVerification($email, $ip);
            if ($result['ok']) {
                $success = __('auth.verification_sent_if_needed');
            } else {
                $error = __($result['error'] ?? 'auth.error.generic');
                $showResendVerification = true;
            }
        } elseif ($action === 'forgot_password') {
            $mode = 'forgot';
            $result = $auth->requestPasswordSetLink($email, $ip);
            if ($result['ok']) {
                $mode = 'login';
                $success = __('auth.password_link_sent_if_exists');
            } else {
                $error = __($result['error'] ?? 'auth.error.generic');
            }
        }
    }
}

if ($email === '' && isset($_GET['email'])) {
    $email = trim((string) $_GET['email']);
}

$authIcon = $mode === 'forgot' ? '🔑' : '🔐';
$authTitle = $mode === 'forgot' ? __('auth.forgot_title') : __('auth.title');
$authSubtitle = $mode === 'forgot' ? __('auth.forgot_subtitle') : __('auth.subtitle_password');
$authError = $error;
$authSuccess = $success;
$authInfo = $info;

$authContent = static function () use ($mode, $email, $redirect, $langQ, $showResendVerification, $auth): void {
    if ($mode === 'forgot') {
        ?>
        <form method="post" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="forgot_password">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.email_label')) ?></label>
                <input type="email" id="email" name="email" required autofocus autocomplete="username"
                       value="<?= htmlspecialchars($email) ?>"
                       class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <button type="submit" class="w-full px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
                <?= htmlspecialchars(__('auth.send_password_link')) ?>
            </button>
        </form>
        <p class="text-center mt-4 text-sm">
            <a href="login.php<?= htmlspecialchars($langQ) ?>" class="text-slate-500 hover:text-brand-600 transition">
                ← <?= htmlspecialchars(__('auth.back_to_login')) ?>
            </a>
        </p>
        <?php

        return;
    }
    ?>
    <form method="post" class="space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
        <div>
            <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.email_label')) ?></label>
            <input type="email" id="email" name="email" required autofocus autocomplete="username"
                   value="<?= htmlspecialchars($email) ?>"
                   placeholder="<?= htmlspecialchars(__('landing.how.email_placeholder')) ?>"
                   class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>
        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.password_label')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>
        <button type="submit" class="w-full px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
            <?= htmlspecialchars(__('auth.sign_in')) ?>
        </button>
    </form>

    <?php if ($showResendVerification): ?>
        <form method="post" class="mt-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="resend_verification">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button type="submit" class="w-full px-4 py-2.5 border border-brand-300 text-brand-700 font-medium rounded-lg hover:bg-brand-50 transition text-sm">
                <?= htmlspecialchars(__('auth.resend_verification')) ?>
            </button>
        </form>
    <?php endif; ?>

    <div class="mt-5 pt-5 border-t border-slate-100 space-y-2 text-center text-sm">
        <p>
            <a href="login.php<?= htmlspecialchars($langQ === '' ? '?' : $langQ . '&') ?>mode=forgot"
               class="text-slate-500 hover:text-brand-600 transition">
                <?= htmlspecialchars(__('auth.forgot_link')) ?>
            </a>
        </p>
        <?php if ($auth->selfRegistrationEnabled()): ?>
            <p class="text-slate-500">
                <?= htmlspecialchars(__('auth.no_account')) ?>
                <a href="register.php<?= htmlspecialchars($langQ) ?>" class="text-brand-600 font-medium hover:text-brand-700 transition">
                    <?= htmlspecialchars(__('auth.register_link')) ?>
                </a>
            </p>
        <?php else: ?>
            <p class="text-slate-500"><?= htmlspecialchars(__('auth.no_account_admin')) ?></p>
        <?php endif; ?>
    </div>
    <?php
};

require __DIR__ . '/includes/auth_layout.php';
