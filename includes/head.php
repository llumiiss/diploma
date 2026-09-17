<?php

declare(strict_types=1);

/**
 * Wspólny arkusz stylów aplikacji.
 *
 * Styl powstaje z assets/css/app.src.css poleceniem `npm run css` (Tailwind 3) i jest w repozytorium
 * razem z krojem Inter (assets/fonts) oraz biblioteką Vue (assets/vendor). Aplikacja nie pobiera
 * niczego z internetu, więc działa też w sieci wewnętrznej bez dostępu do CDN (wymaganie N3).
 */

$certisubAsset = static function (string $path): string {
    $file = dirname(__DIR__) . '/' . $path;

    return $path . '?v=' . (is_file($file) ? (string) filemtime($file) : '0');
};
?>
<link rel="stylesheet" href="<?= htmlspecialchars($certisubAsset('assets/css/app.css')) ?>">
