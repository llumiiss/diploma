<?php

declare(strict_types=1);

// Wyszukiwarka globalna z powiązaniami — szczegóły w App\Api\SearchController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\SearchController;
use App\Http\ApiKernel;

ApiKernel::run(new SearchController(), ['GET']);
