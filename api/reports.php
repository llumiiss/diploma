<?php

declare(strict_types=1);

// Raporty perspektyw (karty osoby i płatnika) i harmonogram wygaśnięć — szczegóły w App\Api\ReportsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\ReportsController;
use App\Http\ApiKernel;

ApiKernel::run(new ReportsController(), ['GET']);
