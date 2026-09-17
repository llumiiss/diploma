<?php

declare(strict_types=1);

// Konta personelu (ADMIN) — szczegóły w App\Api\AccountsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\AccountsController;
use App\Http\ApiKernel;

ApiKernel::run(new AccountsController());
