<?php

declare(strict_types=1);

// Szablony wiadomości — szczegóły w App\Api\TemplatesController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\TemplatesController;
use App\Http\ApiKernel;

ApiKernel::run(new TemplatesController());
