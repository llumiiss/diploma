<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\PreferencesController;
use App\Http\ApiKernel;

ApiKernel::run(new PreferencesController());
