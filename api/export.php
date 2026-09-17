<?php

declare(strict_types=1);

// Eksport danych do CSV i XML — szczegóły w App\Api\ExportController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\ExportController;
use App\Http\ApiKernel;

ApiKernel::run(new ExportController(), ['GET']);
