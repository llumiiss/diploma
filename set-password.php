<?php

declare(strict_types=1);

/**
 * Ustawienie hasła linkiem z wiadomości: set-password.php?token=<64 znaki hex>.
 *
 * Dwa zastosowania (ten sam token rodzaju PASSWORD_SET):
 *   - konto założone przez administratora, które nie ma jeszcze hasła,
 *   - „nie pamiętam hasła” z ekranu logowania.
 *
 * Link jest jednorazowy i ważny 2 godziny. Zapisanie hasła potwierdza zarazem adres e-mail
 * (dostęp do skrzynki jest dowodem posiadania adresu) i od razu loguje.
 */

require __DIR__ . '/bootstrap.php';

use App\Auth\PasswordPolicy;
use App\AuthManager;
use App\Csrf;
use App\Translator;

$translator = Translator::init();
$langQ = $translator->querySuffix();
$auth = new AuthManager();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$errorField = '';
$tokenValid = true;

$check = $auth->checkPasswordToken($token);
if (!$check['ok']) {
    $tokenValid = false;
    $error = __($check['error'] ?? 'auth.error.link_invalid');
}

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $error = __('auth.error.csrf');
    } else {
        $result = $auth->setPasswordWithToken(
            $token,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? '')
        );

        if ($result['ok']) {
            header('Location: ' . ($result['redirect'] ?? 'dashboard.php') . $langQ, true, 302);
            exit;
        }

        $error = __($result['error'] ?? 'auth.error.generic');
        $errorField = $result['field'] ?? '';
        $tokenValid = !in_array($result['error'] ?? '', [
            'auth.error.link_invalid',
            'auth.error.link_used',
            'auth.error.link_expired',
        ], true);
    }
}

$authIcon = $tokenValid ? '🔑' : '⚠️';
$authTitle = __('auth.set_password_title');
$authSubtitle = $tokenValid ? __('auth.set_password_subtitle') : '';
$authError = $error;

$authContent = static function () use ($tokenValid, $token, $errorField, $langQ): void {
    if (!$tokenValid) {
        ?>
        <p class="text-sm text-slate-600"><?= htmlspecialchars(__('auth.set_password_link_dead')) ?></p>
        <p class="mt-6">
            <a href="login.php<?= htmlspecialchars($langQ === '' ? '?' : $langQ . '&') ?>mode=forgot"
               class="block w-full text-center px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
                <?= htmlspecialchars(__('auth.send_password_link')) ?>
            </a>
        </p>
        <?php

        return;
    }

    $ring = static fn (string $field): string => $field === $errorField
        ? 'border-red-300 focus:ring-red-500 focus:border-red-500'
        : 'border-slate-300 focus:ring-brand-500 focus:border-brand-500';
    ?>
    <form method="post" class="space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.password_new_label')) ?></label>
            <input type="password" id="password" name="password" required autofocus autocomplete="new-password"
                   minlength="<?= PasswordPolicy::MIN_LENGTH ?>"
                   class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('password') ?>">
            <p class="text-xs text-slate-500 mt-1.5"><?= htmlspecialchars(__('auth.password_hint', ['min' => (string) PasswordPolicy::MIN_LENGTH])) ?></p>
        </div>
        <div>
            <label for="password_confirm" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.password_confirm_label')) ?></label>
            <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"
                   class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('password_confirm') ?>">
        </div>
        <button type="submit" class="w-full px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
            <?= htmlspecialchars(__('auth.set_password_submit')) ?>
        </button>
    </form>
    <?php
};

require __DIR__ . '/includes/auth_layout.php';
