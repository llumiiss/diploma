<?php

declare(strict_types=1);

// Zaproszenia do odnowienia i przypomnienia — szczegóły w App\Api\InvitationsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\InvitationsController;
use App\Http\ApiKernel;

ApiKernel::run(new InvitationsController());
