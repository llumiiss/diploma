<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Exchange\EmlParser;
use App\Http\ApiKernel;
use App\Http\Response;
use App\Intake\IntakeConfig;
use App\Service\RegistrationIntakeService;
use App\Service\ServiceException;
use PDO;
use Throwable;

/**
 * api/inbound-mail.php — webhook do odbioru wniosków: POST z surową treścią wiadomości (MIME, format .eml)
 * i nagłówkiem X-Intake-Token. Służy do podpięcia usługi poczty przychodzącej (Mailgun, Postmark, SendGrid
 * w trybie „raw MIME”, własny serwer pocztowy z curl) bez dawania jej dostępu do skrzynki.
 *
 * Punkt wejścia nie korzysta z sesji ani CSRF — uwierzytelnia go wspólny sekret z config/intake.local.php
 * (webhook.token), porównywany odpornie na ataki czasowe. Pusty token wyłącza punkt wejścia.
 *   200 {status: created|duplicate|skipped, draft_id}   422 nieczytelna wiadomość   401 zły token
 *   413 za duża wiadomość                               503 webhook wyłączony       405 inna metoda niż POST
 */
final class InboundMailEndpoint
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null, private readonly ?RegistrationIntakeService $intake = null, private readonly ?string $token = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function handle(string $method, ?string $providedToken, string $body): Response
    {
        $expected = $this->token ?? IntakeConfig::webhookToken();
        if ($expected === '') {
            return ApiKernel::error(503, \__('registration.error.webhook_disabled'));
        }
        if (strtoupper($method) !== 'POST') {
            return ApiKernel::error(405, \__('api.error.method'));
        }
        if ($providedToken === null || !hash_equals($expected, $providedToken)) {
            return ApiKernel::error(401, \__('api.error.unauthorized'));
        }
        if (strlen($body) > EmlParser::MAX_BYTES) {
            return ApiKernel::error(413, \__('exchange.error.file_too_large', ['size' => '10 MB']));
        }
        if (trim($body) === '') {
            return ApiKernel::error(422, \__('exchange.error.eml_invalid'));
        }

        try {
            $result = ($this->intake ?? new RegistrationIntakeService($this->db))->ingest($body, 'webhook');

            return Response::json(200, ['success' => true] + $result);
        } catch (ServiceException $e) {
            return ApiKernel::error($e->httpStatus(), $e->errors['file'] ?? $e->getMessage());
        } catch (Throwable $e) {
            error_log('[CertiSub inbound-mail] ' . $e::class . ': ' . $e->getMessage());

            return ApiKernel::error(500, \__('api.error.server'));
        }
    }
}
