<?php

declare(strict_types=1);

// Powiadomienia wewnętrzne — szczegóły w App\Api\NotificationsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\NotificationsController;
use App\Http\ApiKernel;

ApiKernel::run(new NotificationsController());
