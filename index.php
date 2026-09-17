<?php
require __DIR__ . '/bootstrap.php';
$langQ = \App\Translator::init()->querySuffix();
$loginCorporate = 'login.php?redirect=' . urlencode('dashboard.php' . $langQ);
$loginPersonal = 'login.php?redirect=' . urlencode('dashboard-personal.php' . $langQ);

$corporateTrack = [
    ['icon' => '🔒', 'label' => __('landing.track.ssl')],
    ['icon' => '☁️', 'label' => __('landing.track.saas')],
    ['icon' => '🌐', 'label' => __('landing.track.domain')],
    ['icon' => '🛠️', 'label' => __('landing.track.cloud')],
    ['icon' => '✍️', 'label' => __('landing.track.signing')],
];
$personalTrack = [
    ['icon' => '🎬', 'label' => __('landing.track.netflix')],
    ['icon' => '🎵', 'label' => __('landing.track.spotify')],
    ['icon' => '✨', 'label' => __('landing.track.disney')],
    ['icon' => '📺', 'label' => __('landing.track.hbo')],
    ['icon' => '☁️', 'label' => __('landing.track.icloud')],
    ['icon' => '🎮', 'label' => __('landing.track.psplus')],
];
$featureCards = [
    ['icon' => '📊', 'color' => 'brand', 'title' => __('landing.features.f1.title'), 'desc' => __('landing.features.f1.desc')],
    ['icon' => '💳', 'color' => 'amber', 'title' => __('landing.features.f2.title'), 'desc' => __('landing.features.f2.desc')],
    ['icon' => '🔎', 'color' => 'emerald', 'title' => __('landing.features.f3.title'), 'desc' => __('landing.features.f3.desc')],
    ['icon' => '🚨', 'color' => 'red', 'title' => __('landing.features.f4.title'), 'desc' => __('landing.features.f4.desc')],
    ['icon' => '👥', 'color' => 'purple', 'title' => __('landing.features.f5.title'), 'desc' => __('landing.features.f5.desc')],
    ['icon' => '📅', 'color' => 'slate', 'title' => __('landing.features.f6.title'), 'desc' => __('landing.features.f6.desc')],
];
$featureIconBg = [
    'brand' => 'bg-brand-100 text-brand-600',
    'amber' => 'bg-amber-100 text-amber-600',
    'emerald' => 'bg-emerald-100 text-emerald-600',
    'red' => 'bg-red-100 text-red-600',
    'purple' => 'bg-purple-100 text-purple-600',
    'slate' => 'bg-slate-100 text-slate-600',
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\App\Translator::init()->locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('landing.title')) ?></title>
    <meta name="description" content="<?= htmlspecialchars(__('landing.meta.description')) ?>">
    <?php require __DIR__ . '/includes/head.php'; ?>
</head>
<body class="bg-white text-slate-800 font-sans antialiased">

<!-- Navigation -->
<header class="sticky top-0 z-50 bg-white/90 backdrop-blur border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-3">
            <div class="w-10 h-10 bg-brand-600 rounded-xl flex items-center justify-center text-white font-bold text-sm shadow-sm">CS</div>
            <span class="font-semibold text-brand-900 text-lg"><?= htmlspecialchars(__('landing.brand')) ?></span>
        </a>
        <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
            <a href="#track" class="hover:text-brand-600 transition"><?= htmlspecialchars(__('landing.nav.track')) ?></a>
            <a href="#how-it-works" class="hover:text-brand-600 transition"><?= htmlspecialchars(__('landing.nav.how')) ?></a>
            <a href="#choose" class="hover:text-brand-600 transition"><?= htmlspecialchars(__('landing.nav.choose')) ?></a>
            <a href="#features" class="hover:text-brand-600 transition"><?= htmlspecialchars(__('landing.nav.features')) ?></a>
        </nav>
        <div class="flex items-center gap-2">
            <?php $variant = 'light'; require __DIR__ . '/includes/lang_switcher.php'; ?>
            <a href="<?= htmlspecialchars($loginCorporate) ?>"
               class="hidden sm:inline-flex px-4 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition shadow-sm">
                <?= htmlspecialchars(__('landing.nav.corporate')) ?>
            </a>
            <a href="<?= htmlspecialchars($loginPersonal) ?>"
               class="hidden sm:inline-flex px-4 py-2.5 bg-violet-600 text-white text-sm font-semibold rounded-lg hover:bg-violet-700 transition shadow-sm">
                <?= htmlspecialchars(__('landing.nav.personal')) ?>
            </a>
        </div>
    </div>
</header>

<!-- Hero -->
<section class="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-slate-900 text-white">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-20 left-10 w-72 h-72 bg-brand-500 rounded-full blur-3xl"></div>
        <div class="absolute bottom-10 right-10 w-96 h-96 bg-blue-400 rounded-full blur-3xl"></div>
    </div>
    <div class="relative max-w-6xl mx-auto px-6 py-24 md:py-32">
        <div class="max-w-3xl">
            <p class="text-brand-200 text-sm font-semibold uppercase tracking-widest mb-4"><?= htmlspecialchars(__('landing.hero.badge')) ?></p>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold leading-tight mb-6">
                <?= htmlspecialchars(__('landing.hero.title')) ?>
            </h1>
            <p class="text-lg md:text-xl text-blue-100 leading-relaxed mb-10">
                <?= htmlspecialchars(__('landing.hero.subtitle')) ?>
            </p>
            <div class="flex flex-col sm:flex-row gap-4">
                <a href="<?= htmlspecialchars($loginCorporate) ?>"
                   class="inline-flex items-center justify-center px-8 py-3.5 bg-white text-brand-900 font-semibold rounded-lg hover:bg-brand-50 transition shadow-lg">
                    🏢 <?= htmlspecialchars(__('landing.hero.corporate')) ?>
                </a>
                <a href="<?= htmlspecialchars($loginPersonal) ?>"
                   class="inline-flex items-center justify-center px-8 py-3.5 bg-violet-500 text-white font-semibold rounded-lg hover:bg-violet-400 transition shadow-lg border border-violet-400">
                    🎬 <?= htmlspecialchars(__('landing.hero.personal')) ?>
                </a>
            </div>
        </div>

        <!-- Co robi asystent (bez zmyślonych liczb — §5 pkt 13) -->
        <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach (['scan' => '🔎', 'invite' => '✉️', 'report' => '📈'] as $feature => $icon): ?>
            <div class="bg-white/10 backdrop-blur rounded-xl p-5 border border-white/20">
                <p class="text-2xl" aria-hidden="true"><?= $icon ?></p>
                <p class="font-semibold mt-2"><?= htmlspecialchars(__('landing.hero.feature_' . $feature)) ?></p>
                <p class="text-blue-200 text-sm mt-1"><?= htmlspecialchars(__('landing.hero.feature_' . $feature . '_desc')) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- What you can track -->
<section id="track" class="py-16 md:py-20 bg-white border-b border-slate-100">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-10">
            <h2 class="text-3xl font-bold text-brand-900 mb-3"><?= htmlspecialchars(__('landing.track.title')) ?></h2>
            <p class="text-slate-600 max-w-xl mx-auto"><?= htmlspecialchars(__('landing.track.subtitle')) ?></p>
        </div>

        <div class="grid lg:grid-cols-2 gap-10">
            <div>
                <p class="text-xs font-bold text-brand-600 uppercase tracking-widest mb-4 text-center lg:text-left"><?= htmlspecialchars(__('landing.track.corporate')) ?></p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <?php foreach ($corporateTrack as $item): ?>
                        <div class="flex flex-col items-center text-center p-5 rounded-2xl bg-brand-50 border border-brand-100 hover:border-brand-300 hover:shadow-md transition">
                            <span class="text-4xl mb-3"><?= $item['icon'] ?></span>
                            <span class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($item['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <p class="text-xs font-bold text-violet-600 uppercase tracking-widest mb-4 text-center lg:text-left"><?= htmlspecialchars(__('landing.track.personal')) ?></p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <?php foreach ($personalTrack as $item): ?>
                        <div class="flex flex-col items-center text-center p-5 rounded-2xl bg-violet-50 border border-violet-100 hover:border-violet-300 hover:shadow-md transition">
                            <span class="text-4xl mb-3"><?= $item['icon'] ?></span>
                            <span class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($item['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- How it works -->
<section id="how-it-works" class="py-16 md:py-20 bg-slate-50">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-brand-900 mb-3"><?= htmlspecialchars(__('landing.how.title')) ?></h2>
            <p class="text-slate-600 max-w-2xl mx-auto"><?= htmlspecialchars(__('landing.how.subtitle')) ?></p>
        </div>

        <div class="grid lg:grid-cols-2 gap-12 items-center">
            <div class="space-y-8">
                <div class="flex gap-4">
                    <div class="w-10 h-10 shrink-0 bg-brand-600 text-white rounded-full flex items-center justify-center font-bold">1</div>
                    <div>
                        <h3 class="font-bold text-lg text-slate-900 mb-1"><?= htmlspecialchars(__('landing.how.step1.title')) ?></h3>
                        <p class="text-slate-600 text-sm leading-relaxed"><?= htmlspecialchars(__('landing.how.step1.desc')) ?></p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-10 h-10 shrink-0 bg-brand-600 text-white rounded-full flex items-center justify-center font-bold">2</div>
                    <div>
                        <h3 class="font-bold text-lg text-slate-900 mb-1"><?= htmlspecialchars(__('landing.how.step2.title')) ?></h3>
                        <p class="text-slate-600 text-sm leading-relaxed"><?= htmlspecialchars(__('landing.how.step2.desc')) ?></p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-10 h-10 shrink-0 bg-brand-600 text-white rounded-full flex items-center justify-center font-bold">3</div>
                    <div>
                        <h3 class="font-bold text-lg text-slate-900 mb-1"><?= htmlspecialchars(__('landing.how.step3.title')) ?></h3>
                        <p class="text-slate-600 text-sm leading-relaxed"><?= htmlspecialchars(__('landing.how.step3.desc')) ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 max-w-md mx-auto lg:mx-0 lg:ml-auto w-full">
                <div class="text-center mb-6">
                    <div class="w-14 h-14 bg-brand-100 text-brand-600 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-3">✉️</div>
                    <h3 class="font-bold text-slate-900"><?= htmlspecialchars(__('landing.how.step1.title')) ?></h3>
                </div>
                <form class="space-y-4" method="get" action="login.php">
                    <input type="hidden" name="redirect" value="dashboard-personal.php<?= htmlspecialchars($langQ) ?>">
                    <input type="email" name="email"
                           placeholder="<?= htmlspecialchars(__('landing.how.email_placeholder')) ?>"
                           class="w-full px-4 py-3 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <button type="submit"
                            class="block w-full text-center px-4 py-3 bg-brand-600 text-white font-semibold rounded-lg hover:bg-brand-700 transition">
                        <?= htmlspecialchars(__('landing.how.sign_in')) ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- Choose assistant -->
<section id="choose" class="py-16 md:py-20 bg-white">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-brand-900 mb-3"><?= htmlspecialchars(__('landing.choose.title')) ?></h2>
            <p class="text-slate-600"><?= htmlspecialchars(__('landing.choose.subtitle')) ?></p>
        </div>
        <div class="grid md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            <a href="<?= htmlspecialchars($loginCorporate) ?>" class="group block p-8 rounded-2xl border-2 border-brand-200 hover:border-brand-500 hover:shadow-lg transition bg-brand-50/50">
                <div class="text-4xl mb-4">🏢</div>
                <h3 class="text-xl font-bold text-brand-900 mb-2"><?= htmlspecialchars(__('landing.choose.corporate.title')) ?></h3>
                <p class="text-slate-600 text-sm leading-relaxed mb-6"><?= htmlspecialchars(__('landing.choose.corporate.desc')) ?></p>
                <span class="inline-flex items-center text-brand-600 font-semibold text-sm group-hover:underline"><?= htmlspecialchars(__('landing.choose.corporate.cta')) ?></span>
            </a>
            <a href="<?= htmlspecialchars($loginPersonal) ?>" class="group block p-8 rounded-2xl border-2 border-violet-200 hover:border-violet-500 hover:shadow-lg transition bg-violet-50/50">
                <div class="text-4xl mb-4">🎬</div>
                <h3 class="text-xl font-bold text-violet-900 mb-2"><?= htmlspecialchars(__('landing.choose.personal.title')) ?></h3>
                <p class="text-slate-600 text-sm leading-relaxed mb-6"><?= htmlspecialchars(__('landing.choose.personal.desc')) ?></p>
                <span class="inline-flex items-center text-violet-600 font-semibold text-sm group-hover:underline"><?= htmlspecialchars(__('landing.choose.personal.cta')) ?></span>
            </a>
        </div>
    </div>
</section>

<!-- Features -->
<section id="features" class="py-16 md:py-20 bg-slate-50">
    <div class="max-w-6xl mx-auto px-6">
        <div class="text-center mb-14">
            <h2 class="text-3xl font-bold text-brand-900 mb-3"><?= htmlspecialchars(__('landing.features.title')) ?></h2>
            <p class="text-slate-600 max-w-2xl mx-auto"><?= htmlspecialchars(__('landing.features.subtitle')) ?></p>
        </div>
        <div class="grid md:grid-cols-3 gap-8">
            <?php foreach ($featureCards as $card): ?>
            <article class="p-6 rounded-2xl border border-slate-200 bg-white hover:border-brand-200 hover:shadow-md transition">
                <div class="w-12 h-12 <?= $featureIconBg[$card['color']] ?> rounded-xl flex items-center justify-center text-xl mb-4"><?= $card['icon'] ?></div>
                <h3 class="font-semibold text-lg mb-2"><?= htmlspecialchars($card['title']) ?></h3>
                <p class="text-slate-600 text-sm leading-relaxed"><?= htmlspecialchars($card['desc']) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="py-16 md:py-20 bg-white">
    <div class="max-w-4xl mx-auto px-6 text-center">
        <h2 class="text-3xl font-bold text-brand-900 mb-4"><?= htmlspecialchars(__('landing.cta.title')) ?></h2>
        <p class="text-slate-600 mb-8"><?= htmlspecialchars(__('landing.cta.subtitle')) ?></p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="<?= htmlspecialchars($loginCorporate) ?>"
               class="inline-flex px-8 py-4 bg-brand-600 text-white font-semibold rounded-xl hover:bg-brand-700 transition shadow-lg">
                <?= htmlspecialchars(__('landing.cta.corporate')) ?>
            </a>
            <a href="<?= htmlspecialchars($loginPersonal) ?>"
               class="inline-flex px-8 py-4 bg-violet-600 text-white font-semibold rounded-xl hover:bg-violet-700 transition shadow-lg">
                <?= htmlspecialchars(__('landing.cta.personal')) ?>
            </a>
        </div>
    </div>
</section>

<footer class="bg-brand-900 text-blue-200 py-10">
    <div class="max-w-6xl mx-auto px-6">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6 text-sm mb-6">
            <p>© <?= date('Y') ?> <?= htmlspecialchars(__('landing.footer.copyright')) ?></p>
            <p><?= htmlspecialchars(__('landing.footer.tagline')) ?></p>
        </div>
        <div class="border-t border-blue-800 pt-6 text-center text-xs text-blue-300/80">
            <?= htmlspecialchars(__('landing.footer.stack')) ?>
        </div>
    </div>
</footer>

</body>
</html>
