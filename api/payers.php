<?php

declare(strict_types=1);

// Płatnicy — szczegóły w App\Api\PayersController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\PayersController;
use App\Http\ApiKernel;

ApiKernel::run(new PayersController());
