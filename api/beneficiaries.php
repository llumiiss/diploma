<?php

declare(strict_types=1);

// Użytkownicy certyfikatów — szczegóły w App\Api\BeneficiariesController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\BeneficiariesController;
use App\Http\ApiKernel;

ApiKernel::run(new BeneficiariesController());
