<?php

declare(strict_types=1);

// Import CSV, XML i EML z podglądem — szczegóły w App\Api\ImportController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\ImportController;
use App\Http\ApiKernel;

ApiKernel::run(new ImportController());
