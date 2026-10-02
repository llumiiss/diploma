<?php

declare(strict_types=1);

// Wnioski o certyfikat przesłane e-mailem — szczegóły w App\Api\RegistrationsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\RegistrationsController;
use App\Http\ApiKernel;

ApiKernel::run(new RegistrationsController());
