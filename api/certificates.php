<?php

declare(strict_types=1);

// Ewidencja certyfikatów — szczegóły w App\Api\CertificatesController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\CertificatesController;
use App\Http\ApiKernel;

ApiKernel::run(new CertificatesController());
