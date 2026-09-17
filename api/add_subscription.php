<?php

declare(strict_types=1);

// Menedżer osobisty (moduł zamrożony, D1) — logika w App\Api\PersonalManagerController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\PersonalManagerController;
use App\Http\ApiKernel;

ApiKernel::run(new PersonalManagerController(), ['POST']);
