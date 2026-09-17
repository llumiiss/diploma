<?php

declare(strict_types=1);

// Ustawienia procesu odnowień (ADMIN) — szczegóły w App\Api\SettingsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\SettingsController;
use App\Http\ApiKernel;

ApiKernel::run(new SettingsController());
