<?php

declare(strict_types=1);

/**
 * Rejestracja: imię, nazwisko, e-mail i hasło (Etap 9).
 *
 * Konto powstaje od razu, ale jest NIEAKTYWNE — logowanie działa dopiero po kliknięciu
 * linku z wiadomości wysłanej na podany adres (verify-email.php). Wiadomość wychodzi
 * przez SMTP z autoryzacją OAuth2 (config/mail.local.php, driver „oauth2”).
 *
 * Gdy rejestracja publiczna jest wyłączona w config/auth.php (decyzja D3), strona
 * przekierowuje na logowanie z odpowiednim komunikatem — tak jak przed Etapem 9.
 */

require __DIR__ . '/bootstrap.php';

use App\AuthManager;
use App\Csrf;
use App\Translator;

$translator = Translator::init();
$langQ = $translator->querySuffix();
$auth = new AuthManager();

if (!$auth->selfRegistrationEnabled()) {
    $separator = $langQ === '' ? '?' : '&';
    header('Location: login.php' . $langQ . $separator . 'registration_closed=1', true, 302);
    exit;
}

if ($auth->isAuthenticated()) {
    header('Location: dashboard.php' . $langQ);
    exit;
}

$form = ['first_name' => '', 'last_name' => '', 'email' => ''];
$error = '';
$errorField = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $error = __('auth.error.csrf');
    } else {
        foreach (array_keys($form) as $field) {
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
        }

        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null;
        $result = $auth->register($form + [
            'password'         => (string) ($_POST['password'] ?? ''),
            'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
        ], $ip);

        if ($result['ok']) {
            $success = __('auth.verification_sent', ['email' => $form['email']]);
            $form = ['first_name' => '', 'last_name' => '', 'email' => ''];
        } else {
            $error = __($result['error'] ?? 'auth.error.generic');
            $errorField = $result['field'] ?? '';
        }
    }
}

$authIcon = '📝';
$authTitle = __('auth.register_title');
$authSubtitle = __('auth.register_subtitle');
$authError = $error;
$authSuccess = $success;

$authContent = static function () use ($form, $errorField, $success, $langQ): void {
    if ($success !== '') {
        ?>
        <p class="text-sm text-slate-600"><?= htmlspecialchars(__('auth.verification_next_step')) ?></p>
        <p class="mt-6">
            <a href="login.php<?= htmlspecialchars($langQ) ?>"
               class="block w-full text-center px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
                <?= htmlspecialchars(__('auth.back_to_login')) ?>
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
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="first_name" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.first_name')) ?></label>
                <input type="text" id="first_name" name="first_name" required autofocus maxlength="100"
                       value="<?= htmlspecialchars($form['first_name']) ?>" autocomplete="given-name"
                       class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('first_name') ?>">
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.last_name')) ?></label>
                <input type="text" id="last_name" name="last_name" required maxlength="100"
                       value="<?= htmlspecialchars($form['last_name']) ?>" autocomplete="family-name"
                       class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('last_name') ?>">
            </div>
        </div>
        <div>
            <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.email_label')) ?></label>
            <input type="email" id="email" name="email" required maxlength="255" autocomplete="email"
                   value="<?= htmlspecialchars($form['email']) ?>"
                   placeholder="<?= htmlspecialchars(__('landing.how.email_placeholder')) ?>"
                   class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('email') ?>">
        </div>
        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.password_label')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="new-password"
                   minlength="<?= App\Auth\PasswordPolicy::MIN_LENGTH ?>"
                   class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('password') ?>">
            <p class="text-xs text-slate-500 mt-1.5"><?= htmlspecialchars(__('auth.password_hint', ['min' => (string) App\Auth\PasswordPolicy::MIN_LENGTH])) ?></p>
        </div>
        <div>
            <label for="password_confirm" class="block text-sm font-medium text-slate-700 mb-1.5"><?= htmlspecialchars(__('auth.password_confirm_label')) ?></label>
            <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"
                   class="w-full px-4 py-3 border rounded-lg text-sm focus:outline-none focus:ring-2 <?= $ring('password_confirm') ?>">
        </div>
        <button type="submit" class="w-full px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
            <?= htmlspecialchars(__('auth.register_submit')) ?>
        </button>
    </form>
    <p class="text-center mt-5 text-sm text-slate-500">
        <?= htmlspecialchars(__('auth.have_account')) ?>
        <a href="login.php<?= htmlspecialchars($langQ) ?>" class="text-brand-600 font-medium hover:text-brand-700 transition">
            <?= htmlspecialchars(__('auth.login_link')) ?>
        </a>
    </p>
    <?php
};

require __DIR__ . '/includes/auth_layout.php';
