<?php

declare(strict_types=1);

// Dziennik zdarzeń administratora — szczegóły w App\Api\EventsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\EventsController;
use App\Http\ApiKernel;

ApiKernel::run(new EventsController(), ['GET']);
