<?php

declare(strict_types=1);

// Webhook poczty przychodzącej (wnioski o certyfikat) — szczegóły w App\Api\InboundMailEndpoint.
// Uwierzytelnia wspólny sekret (nagłówek X-Intake-Token), nie sesja.

require dirname(__DIR__) . '/bootstrap.php';

use App\Api\InboundMailEndpoint;
use App\Exchange\EmlParser;

$token = $_SERVER['HTTP_X_INTAKE_TOKEN'] ?? null;

// Czytamy o bajt więcej niż limit, żeby odróżnić „dokładnie limit” od „za duże”.
$body = (string) file_get_contents('php://input', false, null, 0, EmlParser::MAX_BYTES + 1);

(new InboundMailEndpoint())
    ->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), is_string($token) ? $token : null, $body)
    ->send();
