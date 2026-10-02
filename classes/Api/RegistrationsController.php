<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Exchange\EmlParser;
use App\Http\Request;
use App\Http\UploadedFile;
use App\Intake\ImapClient;
use App\Intake\ImapException;
use App\Intake\IntakeConfig;
use App\Intake\MailboxConnector;
use App\Service\Actor;
use App\Service\RegistrationIntakeService;
use App\Service\RegistrationService;
use App\Service\ServiceException;
use Closure;
use PDO;

/**
 * api/registrations.php — wnioski o certyfikat przesłane e-mailem.
 *   GET   ?status=pending|approved|rejected|all     lista wniosków (domyślnie oczekujące) i liczniki
 *   GET   ?id=N                                     wniosek z formularzem do sprawdzenia
 *   GET   ?view=status                              liczba oczekujących i dostępne drogi odbioru poczty
 *   POST  multipart: action=upload, file            wczytanie wiadomości (.eml)
 *   POST  {action: fetch}                           pobranie nowych wiadomości ze skrzynki IMAP
 *   POST  {action: save|approve, id, data}          zapis poprawek / zatwierdzenie (firma + użytkownik + certyfikat)
 *   POST  {action: claim|reject|refresh_company, id, reason?, tax_id?}
 */
final class RegistrationsController
{
    private readonly RegistrationService $service;
    private readonly RegistrationIntakeService $intake;

    /** @var Closure(): ImapClient */
    private readonly Closure $mailbox;
    private readonly bool $customMailbox;

    /**
     * @param (Closure(): ImapClient)|null $mailbox fabryka połączenia ze skrzynką (testy podstawiają własną)
     */
    public function __construct(
        ?PDO $db = null,
        ?RegistrationService $service = null,
        ?RegistrationIntakeService $intake = null,
        ?Closure $mailbox = null,
    ) {
        $connection = $db ?? Database::getInstance()->getConnection();
        $this->intake = $intake ?? new RegistrationIntakeService($connection);
        $this->service = $service ?? new RegistrationService($connection, $this->intake);
        $this->customMailbox = $mailbox !== null;
        $this->mailbox = $mailbox ?? static fn (): ImapClient => MailboxConnector::connect(IntakeConfig::section('imap'));
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['registration' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'status') {
                return ['status' => [
                    'pending'         => $this->service->pendingCount($actor),
                    'imap_enabled'    => IntakeConfig::imapEnabled(),
                    'webhook_enabled' => IntakeConfig::webhookToken() !== '',
                ]];
            }

            return [
                'registrations' => $this->service->list($actor, ['status' => $request->queryString('status') ?: 'pending']),
                'pending'       => $this->service->pendingCount($actor),
            ];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'upload'          => $this->upload($request, $actor),
            'fetch'           => ['fetch' => $this->fetch($actor)],
            'save'            => ['registration' => $this->service->save($actor, Params::requireId($id), $request->data())],
            'approve'         => ['registration' => $this->service->approve($actor, Params::requireId($id), $request->data())],
            'claim'           => ['registration' => $this->service->claim($actor, Params::requireId($id))],
            'reject'          => ['registration' => $this->service->reject($actor, Params::requireId($id), self::text($request->body['reason'] ?? null))],
            'refresh_company' => ['registration' => $this->service->refreshCompany($actor, Params::requireId($id), self::text($request->body['tax_id'] ?? null))],
            default           => throw Params::unknownAction(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(Request $request, Actor $actor): array
    {
        $file = UploadedFile::fromRequest($request->files, 'file', EmlParser::MAX_BYTES);
        $result = $this->intake->ingest($file->content, 'upload', $actor);

        return ['result' => $result];
    }

    /**
     * @return array{fetched: int, created: int, duplicates: int, skipped: int, failed: int, errors: list<string>}
     */
    private function fetch(Actor $actor): array
    {
        $actor->authorize('registrations.intake');
        if (!$this->customMailbox && !IntakeConfig::imapEnabled()) {
            throw ServiceException::conflict(\__('registration.error.imap_disabled'));
        }

        $imap = IntakeConfig::section('imap');
        try {
            $client = ($this->mailbox)();
            try {
                return $this->intake->fetchMailbox(
                    $client,
                    (string) ($imap['mailbox'] ?? 'INBOX'),
                    max(1, (int) ($imap['max_per_run'] ?? 20)),
                    ($imap['processed_mailbox'] ?? '') !== '' ? (string) $imap['processed_mailbox'] : null
                );
            } finally {
                $client->logout();
            }
        } catch (ImapException $e) {
            throw ServiceException::conflict(\__('registration.error.imap_failed', ['message' => $e->getMessage()]));
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
