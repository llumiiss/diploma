<?php

declare(strict_types=1);

// Szablony odczytu wniosków — szczegóły w App\Api\ReadingRulesController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\ReadingRulesController;
use App\Http\ApiKernel;

ApiKernel::run(new ReadingRulesController());
