<?php

declare(strict_types=1);

/**
 * Potwierdzenie adresu e-mail linkiem z wiadomości: verify-email.php?token=<64 znaki hex>.
 *
 * Token jest jednorazowy i ważny 24 godziny. Po potwierdzeniu konto staje się aktywne,
 * a osoba loguje się już zwyczajnie — e-mailem i hasłem (żadnego automatycznego logowania,
 * bo sam link w skrzynce nie powinien wystarczać do wejścia do systemu).
 */

require __DIR__ . '/bootstrap.php';

use App\AuthManager;
use App\Csrf;
use App\Translator;

$translator = Translator::init();
$langQ = $translator->querySuffix();
$auth = new AuthManager();

$token = (string) ($_GET['token'] ?? '');
$result = $auth->verifyEmail($token);

if ($result['ok']) {
    $separator = $langQ === '' ? '?' : '&';
    header('Location: login.php' . $langQ . $separator . 'verified=1&email=' . urlencode($result['email'] ?? ''), true, 302);
    exit;
}

$errorKey = $result['error'] ?? 'auth.error.link_invalid';
$canResend = in_array($errorKey, ['auth.error.link_expired', 'auth.error.link_invalid'], true);
$error = __($errorKey);
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_verification') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $error = __('auth.error.csrf');
    } else {
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null;
        $resend = $auth->resendVerification((string) ($_POST['email'] ?? ''), $ip);

        if ($resend['ok']) {
            $error = '';
            $success = __('auth.verification_sent_if_needed');
            $canResend = false;
        } else {
            $error = __($resend['error'] ?? 'auth.error.generic');
        }
    }
}

$authIcon = $success !== '' ? '📧' : '⚠️';
$authTitle = __('auth.verify_title');
$authSubtitle = $success !== '' ? '' : __('auth.verify_failed_subtitle');
$authError = $error;
$authSuccess = $success;

$authContent = static function () use ($canResend, $langQ): void {
    if ($canResend) {
        ?>
        <form method="post" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="resend_verification">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.email_label')) ?></label>
                <input type="email" id="email" name="email" required autofocus autocomplete="email"
                       class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <button type="submit" class="w-full px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
                <?= htmlspecialchars(__('auth.resend_verification')) ?>
            </button>
        </form>
        <?php
    }
    ?>
    <p class="text-center <?= $canResend ? 'mt-5' : '' ?> text-sm">
        <a href="login.php<?= htmlspecialchars($langQ) ?>" class="text-slate-500 hover:text-brand-600 transition">
            ← <?= htmlspecialchars(__('auth.back_to_login')) ?>
        </a>
    </p>
    <?php
};

require __DIR__ . '/includes/auth_layout.php';
