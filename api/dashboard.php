<?php

declare(strict_types=1);

// Wskaźniki pulpitu i archiwum — szczegóły w App\Api\DashboardController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\DashboardController;
use App\Http\ApiKernel;

ApiKernel::run(new DashboardController());
