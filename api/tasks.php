<?php

declare(strict_types=1);

// Lista ToDo odnowień i statystyki — szczegóły w App\Api\TasksController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\TasksController;
use App\Http\ApiKernel;

ApiKernel::run(new TasksController());
