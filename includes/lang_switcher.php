<?php

declare(strict_types=1);

use App\Translator;

$variant = $variant ?? 'dark';
$translator = Translator::init();
$current = $translator->locale();
$locales = Translator::availableLocales();

$btnClass = $variant === 'light'
    ? 'text-slate-600 hover:bg-slate-100 border-slate-200'
    : 'text-white/90 hover:bg-white/15 border-white/20';

$menuClass = $variant === 'light'
    ? 'bg-white border-slate-200 shadow-lg'
    : 'bg-slate-800 border-slate-700 shadow-xl';

$currentFlag = $locales[$current]['flag'] ?? '🌐';
$isCodeFlag = strlen($currentFlag) <= 3 && !str_contains($currentFlag, '�');
?>
<div class="relative" data-lang-switcher>
    <button type="button"
            data-lang-toggle
            class="flex items-center gap-1.5 px-2.5 py-2 rounded-lg border text-sm font-medium transition <?= $btnClass ?>"
            aria-label="Language"
            aria-expanded="false"
            aria-haspopup="listbox">
        <?php if ($isCodeFlag): ?>
            <span class="text-xs font-bold tracking-wide leading-none"><?= htmlspecialchars($currentFlag) ?></span>
        <?php else: ?>
            <span class="text-lg leading-none"><?= $currentFlag ?></span>
        <?php endif; ?>
        <span class="text-xs opacity-70">▾</span>
    </button>
    <div data-lang-menu
         class="hidden absolute right-0 mt-2 min-w-[11rem] rounded-xl border py-1 z-50 <?= $menuClass ?>"
         role="listbox">
        <?php foreach ($locales as $code => $meta): ?>
            <?php $itemFlag = $meta['flag']; $itemIsCode = strlen($itemFlag) <= 3 && !str_contains($itemFlag, '�'); ?>
            <a href="<?= htmlspecialchars($translator->switchUrl($code)) ?>"
               role="option"
               class="flex items-center gap-3 px-4 py-2.5 text-sm transition <?= $code === $current
                   ? ($variant === 'light' ? 'bg-brand-50 text-brand-700 font-semibold' : 'bg-white/15 text-white font-semibold')
                   : ($variant === 'light' ? 'text-slate-700 hover:bg-slate-50' : 'text-blue-100 hover:bg-white/10') ?>">
                <?php if ($itemIsCode): ?>
                    <span class="text-xs font-bold tracking-wide w-6 text-center"><?= htmlspecialchars($itemFlag) ?></span>
                <?php else: ?>
                    <span class="text-xl leading-none"><?= $itemFlag ?></span>
                <?php endif; ?>
                <span><?= htmlspecialchars($translator->translate($meta['label_key'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<script>
(function () {
    if (window.__langSwitcherInit) return;
    window.__langSwitcherInit = true;
    document.addEventListener('click', function (e) {
        document.querySelectorAll('[data-lang-switcher]').forEach(function (wrap) {
            var btn = wrap.querySelector('[data-lang-toggle]');
            var menu = wrap.querySelector('[data-lang-menu]');
            if (!btn || !menu) return;
            if (btn.contains(e.target)) {
                var open = !menu.classList.contains('hidden');
                document.querySelectorAll('[data-lang-menu]').forEach(function (m) { m.classList.add('hidden'); });
                document.querySelectorAll('[data-lang-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
                if (!open) {
                    menu.classList.remove('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                }
                return;
            }
            if (!wrap.contains(e.target)) {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    });
})();
</script>
