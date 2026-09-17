<?php

// PHP i MySQL muszą liczyć daty w tej samej strefie. Bez tego progi odnowień
// (dni do wygaśnięcia liczone w PHP) rozjeżdżają się o jeden dzień z zapytaniami
// opartymi na CURDATE() — czyli z licznikami KPI i cronem przypomnień.
date_default_timezone_set('Europe/Warsaw');

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/classes/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/classes/Translator.php';

use App\Session;
use App\Translator;

Session::ensureStarted();

Translator::init();

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return Translator::get($key, $replace);
    }
}
