<?php

declare(strict_types=1);

/**
 * Wspólna oprawa stron uwierzytelniania (logowanie, rejestracja, potwierdzenie adresu,
 * ustawienie hasła). Strona ustawia zmienne i przekazuje treść jako domknięcie:
 *
 *   $authTitle    — nagłówek karty
 *   $authSubtitle — zdanie pod nagłówkiem
 *   $authIcon     — emoji w kółku
 *   $authError / $authSuccess / $authInfo — komunikaty (gotowy tekst, bez HTML)
 *   $authContent  — callable wypisujące zawartość karty
 *   $authFooter   — opcjonalny callable pod kartą
 */

use App\Translator;

/** @var Translator $translator */
$translator = $translator ?? Translator::init();
$langQ = $translator->querySuffix();

$authTitle ??= '';
$authSubtitle ??= '';
$authIcon ??= '🔐';
$authError ??= '';
$authSuccess ??= '';
$authInfo ??= '';

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($translator->locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($authTitle) ?> — CertiSub Assistant</title>
    <?php require __DIR__ . '/head.php'; ?>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex flex-col">

<header class="bg-white border-b border-slate-200">
    <div class="max-w-lg mx-auto px-6 py-4 flex items-center justify-between">
        <a href="index.php<?= htmlspecialchars($langQ) ?>" class="flex items-center gap-3">
            <div class="w-10 h-10 bg-brand-600 rounded-xl flex items-center justify-center text-white font-bold text-sm shadow-sm">CS</div>
            <span class="font-semibold text-brand-900"><?= htmlspecialchars(__('landing.brand')) ?></span>
        </a>
        <?php $variant = 'light'; require __DIR__ . '/lang_switcher.php'; ?>
    </div>
</header>

<main class="flex-1 flex items-center justify-center px-6 py-12">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="w-14 h-14 bg-brand-100 text-brand-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4"><?= $authIcon ?></div>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($authTitle) ?></h1>
                <?php if ($authSubtitle !== ''): ?>
                    <p class="text-slate-500 text-sm mt-2"><?= htmlspecialchars($authSubtitle) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($authError !== ''): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                    <?= htmlspecialchars($authError) ?>
                </div>
            <?php endif; ?>

            <?php if ($authSuccess !== ''): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
                    <?= htmlspecialchars($authSuccess) ?>
                </div>
            <?php endif; ?>

            <?php if ($authInfo !== '' && $authError === '' && $authSuccess === ''): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-brand-50 border border-brand-200 text-brand-800 text-sm">
                    <?= htmlspecialchars($authInfo) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($authContent) && is_callable($authContent)) {
                $authContent();
            } ?>
        </div>

        <?php if (isset($authFooter) && is_callable($authFooter)) {
            $authFooter();
        } ?>

        <p class="text-center mt-6">
            <a href="index.php<?= htmlspecialchars($langQ) ?>" class="text-sm text-slate-500 hover:text-brand-600 transition">
                ← <?= htmlspecialchars(__('auth.back_home')) ?>
            </a>
        </p>
    </div>
</main>

</body>
</html>
