<?php

declare(strict_types=1);

// Załączniki wiadomości — szczegóły w App\Api\AttachmentsController.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\AttachmentsController;
use App\Http\ApiKernel;

ApiKernel::run(new AttachmentsController());
