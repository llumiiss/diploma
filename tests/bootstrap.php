<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';

use App\Session;
use App\Settings;

Session::ensureStarted();

// Progi z bazy aplikacji nie mogą zmieniać wyników testów — obowiązują wartości domyślne.
Settings::useDefaultsOnly();
