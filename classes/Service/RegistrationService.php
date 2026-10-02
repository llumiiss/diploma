<?php

declare(strict_types=1);

namespace App\Service;

use App\Intake\CompanyRecord;
use App\Rbac;
use DateTimeImmutable;
use PDO;

/**
 * Sprawdzanie wniosków przesłanych e-mailem (Etap 10) — praca operatora na gotowym formularzu:
 * podgląd wiadomości i wypełnionych danych, poprawki, ponowne sprawdzenie firmy w rejestrze po NIP-ie,
 * zatwierdzenie jednym przyciskiem albo odrzucenie.
 *
 * Zatwierdzenie jest jedną transakcją: powstaje firma (jeśli jej nie było), użytkownik certyfikatu
 * w tej firmie i certyfikat, a wniosek dostaje status „zatwierdzony” z odnośnikami do utworzonych rekordów.
 * Błąd w dowolnym kroku wycofuje całość, a komunikaty pól wracają z prefiksem sekcji (company., person.,
 * certificate.), żeby formularz podświetlił właściwe pole.
 *
 * Zapisy wykonywane są z uprawnieniami potrzebnymi do założenia rekordów, ale na konto operatora (autor, opiekun
 * certyfikatu): prawo „sprawdzania wniosków” obejmuje podpięcie certyfikatu do firmy i osoby wskazanych we wniosku
 * (dopasowanych po NIP-ie i e-mailu), także gdy operator nie widział ich wcześniej w swoim zakresie danych.
 */
final class RegistrationService
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    private readonly EventLogger $events;
    private readonly RegistrationIntakeService $intake;
    private readonly PayerService $payers;
    private readonly BeneficiaryService $beneficiaries;
    private readonly CertificateService $certificates;

    public function __construct(
        private readonly PDO $db,
        ?RegistrationIntakeService $intake = null,
        ?EventLogger $events = null,
    ) {
        $this->events = $events ?? new EventLogger($db);
        $this->intake = $intake ?? new RegistrationIntakeService($db, null, null, null, $this->events);
        $this->payers = new PayerService($db, $this->events);
        $this->beneficiaries = new BeneficiaryService($db, $this->events, $this->payers);
        $this->certificates = new CertificateService($db, $this->events, $this->payers, $this->beneficiaries);
    }

    // ------------------------------------------------------------------- odczyt

    /**
     * @param array{status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, array $filters = []): array
    {
        $actor->authorize('registrations.view');

        $status = (string) ($filters['status'] ?? 'pending');
        $where = in_array($status, self::STATUSES, true) ? 'WHERE d.status = :status' : '';
        $stmt = $this->db->prepare(
            "SELECT d.id, d.status, d.source, d.subject, d.sender_email, d.sender_name, d.received_at, d.form, d.warnings,
                    d.assigned_user_id, d.reviewed_at, d.created_at, d.result_certificate_id, d.rejection_reason,
                    CONCAT_WS(' ', au.first_name, au.last_name) AS assigned_name,
                    CONCAT_WS(' ', ru.first_name, ru.last_name) AS reviewed_by_name
             FROM registration_drafts d
             LEFT JOIN users au ON au.id = d.assigned_user_id
             LEFT JOIN users ru ON ru.id = d.reviewed_by_user_id
             {$where}
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT 300"
        );
        $stmt->execute($where === '' ? [] : ['status' => $status]);

        return array_map(static function (array $row): array {
            $form = self::decode($row['form']);
            $warnings = self::decodeList($row['warnings']);
            $person = trim((string) ($form['person']['first_name'] ?? '') . ' ' . (string) ($form['person']['last_name'] ?? ''));

            return [
                'id'                    => (int) $row['id'],
                'status'                => (string) $row['status'],
                'source'                => (string) $row['source'],
                'subject'               => $row['subject'],
                'sender_email'          => $row['sender_email'],
                'sender_name'           => $row['sender_name'],
                'received_at'           => $row['received_at'],
                'created_at'            => $row['created_at'],
                'person_name'           => $person,
                'company_name'          => (string) ($form['company']['company_name'] ?? ''),
                'tax_id'                => (string) ($form['company']['tax_id'] ?? ''),
                'certificate_type'      => (string) ($form['certificate']['certificate_type'] ?? ''),
                'company_mode'          => (string) ($form['company']['mode'] ?? 'new'),
                'blocking'              => count(array_filter($warnings, static fn (array $w): bool => ($w['level'] ?? '') === 'error')),
                'warnings'              => count(array_filter($warnings, static fn (array $w): bool => ($w['level'] ?? '') === 'warning')),
                'assigned_user_id'      => $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
                'assigned_name'         => $row['assigned_user_id'] !== null ? (string) $row['assigned_name'] : null,
                'reviewed_at'           => $row['reviewed_at'],
                'reviewed_by_name'      => $row['reviewed_at'] !== null ? (string) $row['reviewed_by_name'] : null,
                'result_certificate_id' => $row['result_certificate_id'] !== null ? (int) $row['result_certificate_id'] : null,
                'rejection_reason'      => $row['rejection_reason'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Liczba wniosków czekających na sprawdzenie — znaczek w menu.
     */
    public function pendingCount(Actor $actor): int
    {
        $actor->authorize('registrations.view');

        return (int) $this->db->query("SELECT COUNT(*) FROM registration_drafts WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('registrations.view');
        $row = $this->find($id);

        $form = self::decode($row['form']);
        $matchedPayer = $row['matched_payer_id'] !== null ? $this->visiblePayer($actor, (int) $row['matched_payer_id']) : null;
        $matchedPerson = $row['matched_beneficiary_id'] !== null ? $this->visiblePerson($actor, (int) $row['matched_beneficiary_id']) : null;

        return [
            'id'                  => (int) $row['id'],
            'status'              => (string) $row['status'],
            'source'              => (string) $row['source'],
            'message'             => [
                'subject'      => $row['subject'],
                'sender_email' => $row['sender_email'],
                'sender_name'  => $row['sender_name'],
                'received_at'  => $row['received_at'],
                'body_text'    => $row['body_text'],
                'stored'       => $row['raw_file'] !== null,
            ],
            'form'                => $form,
            'extracted'           => self::decode($row['extracted']),
            'company_lookup'      => $row['company_lookup'] !== null ? self::decode($row['company_lookup']) : null,
            'warnings'            => self::decodeList($row['warnings']),
            'matched_payer'       => $matchedPayer,
            'matched_beneficiary' => $matchedPerson,
            'assigned_user_id'    => $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
            'assigned_name'       => $row['assigned_user_id'] !== null ? $this->userName((int) $row['assigned_user_id']) : null,
            'reviewed_at'         => $row['reviewed_at'],
            'reviewed_by_name'    => $row['reviewed_by_user_id'] !== null ? $this->userName((int) $row['reviewed_by_user_id']) : null,
            'rejection_reason'    => $row['rejection_reason'],
            'result'              => [
                'payer_id'       => $row['result_payer_id'] !== null ? (int) $row['result_payer_id'] : null,
                'beneficiary_id' => $row['result_beneficiary_id'] !== null ? (int) $row['result_beneficiary_id'] : null,
                'certificate_id' => $row['result_certificate_id'] !== null ? (int) $row['result_certificate_id'] : null,
            ],
            'can_review'          => $row['status'] === 'pending' && $actor->can('registrations.review'),
            'created_at'          => $row['created_at'],
        ];
    }

    // ------------------------------------------------------------------- zapis

    /**
     * Zapisuje poprawki formularza bez zakładania rekordów (praca można być przerwana i wznowiona).
     *
     * @param array<string, mixed> $data company, person, certificate
     * @return array<string, mixed>
     */
    public function save(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('registrations.review');
        $row = $this->findPending($id);

        $form = $this->normalizeForm($data, self::decode($row['form']));
        $this->db->prepare('UPDATE registration_drafts SET form = :form, assigned_user_id = COALESCE(assigned_user_id, :me) WHERE id = :id')->execute([
            'form' => self::json($form),
            'me'   => $actor->id > 0 ? $actor->id : null,
            'id'   => $id,
        ]);

        return $this->get($actor, $id);
    }

    /**
     * Przejmuje wniosek do opracowania (sygnał dla pozostałych, że ktoś już nad nim pracuje).
     *
     * @return array<string, mixed>
     */
    public function claim(Actor $actor, int $id): array
    {
        $actor->authorize('registrations.review');
        $row = $this->findPending($id);

        $current = $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null;
        if ($current !== null && $current !== $actor->id && !$actor->can('tasks.assign')) {
            throw ServiceException::conflict(\__('registration.error.claimed', ['name' => $this->userName($current)]));
        }

        $this->db->prepare('UPDATE registration_drafts SET assigned_user_id = :me WHERE id = :id')->execute(['me' => $actor->id, 'id' => $id]);

        return $this->get($actor, $id);
    }

    /**
     * Ponownie sprawdza firmę w rejestrze (np. po poprawieniu NIP-u) i odświeża sekcję firmy w formularzu.
     * Sekcje użytkownika i certyfikatu zostają tak, jak je ustawił operator.
     *
     * @return array<string, mixed>
     */
    public function refreshCompany(Actor $actor, int $id, ?string $taxId = null): array
    {
        $actor->authorize('registrations.review');
        $row = $this->findPending($id);
        $form = self::decode($row['form']);
        $extracted = self::decode($row['extracted']);

        $raw = trim((string) ($taxId ?? ($form['company']['tax_id'] ?? '')));
        $nip = Validator::normalizeTaxId($raw);
        if ($raw === '' || !Validator::isValidTaxId($nip)) {
            throw ServiceException::validation(['company.tax_id' => \__('validation.tax_id')]);
        }

        $today = new DateTimeImmutable((string) $this->db->query('SELECT CURDATE()')->fetchColumn());
        $lookup = $this->intake->lookupCompany($nip, $today);
        $record = $lookup['status'] === 'found' ? CompanyRecord::fromArray($lookup['record']) : null;
        $payer = $this->intake->findPayerByTaxId($nip);

        // Ręcznie podany NIP zastępuje ostrzeżenia o jego braku albo błędzie w wiadomości.
        $extracted['company']['nip'] = $nip;
        $extracted['warnings'] = array_values(array_filter(
            $extracted['warnings'],
            static fn (array $warning): bool => !in_array($warning['code'], ['nip_missing', 'nip_invalid', 'nip_multiple'], true)
        ));

        $assumed = [];
        $fresh = RegistrationIntakeService::buildForm($extracted, $record, $payer, null, $today, $assumed);
        $previous = is_array($form['company'] ?? null) ? $form['company'] : [];
        $form['company'] = $fresh['company'];
        $form['company']['tax_id'] = $nip;
        if ($payer === null) {
            // Dane kontaktowe wpisane przez operatora mają pierwszeństwo przed tym, co podpowiada rejestr.
            foreach (['contact_person', 'email', 'phone'] as $key) {
                if (trim((string) ($previous[$key] ?? '')) !== '') {
                    $form['company'][$key] = (string) $previous[$key];
                }
            }
        }

        $beneficiary = $this->intake->findBeneficiary($form['person']['email'] ?? null, $payer !== null ? (int) $payer['id'] : null);
        $extracted['assumed'] = array_values(array_filter(
            $extracted['assumed'] ?? [],
            static fn (mixed $code): bool => is_string($code)
        ));
        $warnings = RegistrationIntakeService::buildWarnings($extracted, $lookup, $payer, $beneficiary);

        $this->db->prepare(
            'UPDATE registration_drafts SET extracted = :extracted, form = :form, company_lookup = :lookup, warnings = :warnings,
                    matched_payer_id = :payer, assigned_user_id = COALESCE(assigned_user_id, :me)
             WHERE id = :id'
        )->execute([
            'extracted' => self::json($extracted),
            'form'      => self::json($form),
            'lookup'    => self::json($lookup),
            'warnings'  => self::json($warnings),
            'payer'     => $payer !== null ? (int) $payer['id'] : null,
            'me'        => $actor->id > 0 ? $actor->id : null,
            'id'        => $id,
        ]);

        return $this->get($actor, $id);
    }

    public function reject(Actor $actor, int $id, ?string $reason = null): array
    {
        $actor->authorize('registrations.review');
        $this->findPending($id);

        $v = new Validator(['reason' => $reason]);
        $text = $v->string('reason', false, 500);
        $v->throwIfFailed();

        Transaction::run($this->db, function () use ($actor, $id, $text): void {
            $this->db->prepare(
                "UPDATE registration_drafts SET status = 'rejected', reviewed_by_user_id = :me, reviewed_at = NOW(), rejection_reason = :reason WHERE id = :id"
            )->execute(['me' => $actor->id > 0 ? $actor->id : null, 'reason' => $text, 'id' => $id]);
            $this->events->log('system', null, 'registration_rejected', $actor->id, [], array_filter(['draft_id' => $id, 'reason' => $text], static fn (mixed $v): bool => $v !== null));
        });

        return $this->get($actor, $id);
    }

    /**
     * Zatwierdzenie: firma (nowa albo istniejąca) → użytkownik certyfikatu → certyfikat.
     *
     * @param array<string, mixed> $data formularz po poprawkach operatora
     * @return array<string, mixed>
     */
    public function approve(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('registrations.review');
        $this->findPending($id);

        Transaction::run($this->db, function () use ($actor, $id, $data): void {
            $stmt = $this->db->prepare('SELECT * FROM registration_drafts WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if ($row === false || $row['status'] !== 'pending') {
                throw ServiceException::conflict(\__('registration.error.not_pending'));
            }

            $form = $this->normalizeForm($data, self::decode($row['form']));
            $writer = new Actor($actor->id, Rbac::ADMIN, $actor->firstName, $actor->lastName, $actor->email);

            $payerId = $this->resolveCompany($actor, $writer, $row, $form['company']);
            $beneficiaryId = $this->resolvePerson($actor, $writer, $row, $form['person'], $payerId);
            $certificate = $this->createCertificate($writer, $actor, $form['certificate'], $beneficiaryId, $payerId);

            $this->db->prepare(
                "UPDATE registration_drafts SET status = 'approved', form = :form, reviewed_by_user_id = :me, reviewed_at = NOW(),
                        result_payer_id = :payer, result_beneficiary_id = :beneficiary, result_certificate_id = :certificate
                 WHERE id = :id"
            )->execute([
                'form'        => self::json($form),
                'me'          => $actor->id > 0 ? $actor->id : null,
                'payer'       => $payerId,
                'beneficiary' => $beneficiaryId,
                'certificate' => (int) $certificate['id'],
                'id'          => $id,
            ]);

            $this->events->log('system', null, 'registration_approved', $actor->id, [
                'certificate_id' => (int) $certificate['id'],
                'beneficiary_id' => $beneficiaryId,
                'payer_id'       => $payerId,
            ], [
                'draft_id'     => $id,
                'company_new'  => $form['company']['mode'] === 'new',
                'person_new'   => $form['person']['mode'] === 'new',
            ]);
        });

        return $this->get($actor, $id);
    }

    // ----------------------------------------------------------- kroki zatwierdzenia

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $company
     */
    private function resolveCompany(Actor $actor, Actor $writer, array $row, array $company): int
    {
        if ($company['mode'] === 'existing') {
            $payerId = (int) ($company['payer_id'] ?? 0);
            $matched = $row['matched_payer_id'] !== null ? (int) $row['matched_payer_id'] : null;
            $known = $payerId > 0 && ($payerId === $matched || $this->payers->findActiveVisible($actor, $payerId) !== null);
            if (!$known) {
                throw ServiceException::validation(['company.payer_id' => \__('registration.error.company_unavailable')]);
            }

            $archived = $this->db->prepare('SELECT archived_at FROM payers WHERE id = :id');
            $archived->execute(['id' => $payerId]);
            $state = $archived->fetchColumn();
            if ($state === false) {
                throw ServiceException::validation(['company.payer_id' => \__('registration.error.company_unavailable')]);
            }
            if ($state !== null) {
                throw ServiceException::conflict(\__('registration.error.company_archived'), [], ['company.payer_id' => \__('registration.error.company_archived')]);
            }

            return $payerId;
        }

        try {
            return (int) $this->payers->create($writer, [
                'company_name'   => $company['company_name'],
                'contact_person' => $company['contact_person'],
                'tax_id'         => $company['tax_id'],
                'email'          => $company['email'],
                'phone'          => $company['phone'],
                'address_line'   => $company['address_line'],
                'postal_code'    => $company['postal_code'],
                'city'           => $company['city'],
            ])['id'];
        } catch (ServiceException $e) {
            throw self::prefixed($e, 'company.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $person
     */
    private function resolvePerson(Actor $actor, Actor $writer, array $row, array $person, int $payerId): int
    {
        if ($person['mode'] === 'existing') {
            $personId = (int) ($person['beneficiary_id'] ?? 0);
            $matched = $row['matched_beneficiary_id'] !== null ? (int) $row['matched_beneficiary_id'] : null;
            $known = $personId > 0 && ($personId === $matched || $this->beneficiaries->findActiveVisible($actor, $personId) !== null);
            if (!$known) {
                throw ServiceException::validation(['person.beneficiary_id' => \__('registration.error.person_unavailable')]);
            }

            $stmt = $this->db->prepare('SELECT payer_id, archived_at FROM beneficiaries WHERE id = :id');
            $stmt->execute(['id' => $personId]);
            $found = $stmt->fetch();
            if ($found === false || $found['archived_at'] !== null) {
                throw ServiceException::validation(['person.beneficiary_id' => \__('registration.error.person_unavailable')]);
            }
            if ((int) $found['payer_id'] !== $payerId) {
                throw ServiceException::validation(['person.beneficiary_id' => \__('registration.error.person_other_company')]);
            }

            return $personId;
        }

        try {
            return (int) $this->beneficiaries->create($writer, [
                'first_name' => $person['first_name'],
                'last_name'  => $person['last_name'],
                'email'      => $person['email'],
                'phone'      => $person['phone'],
                'payer_id'   => $payerId,
            ])['id'];
        } catch (ServiceException $e) {
            throw self::prefixed($e, 'person.');
        }
    }

    /**
     * @param array<string, mixed> $certificate
     * @return array<string, mixed>
     */
    private function createCertificate(Actor $writer, Actor $actor, array $certificate, int $beneficiaryId, int $payerId): array
    {
        try {
            return $this->certificates->create($writer, [
                'name'              => $certificate['name'],
                'certificate_type'  => $certificate['certificate_type'],
                'serial_number'     => $certificate['serial_number'],
                'issuer'            => $certificate['issuer'],
                'valid_from'        => $certificate['valid_from'],
                'expiry_date'       => $certificate['expiry_date'],
                'renewal_lead_days' => $certificate['renewal_lead_days'],
                'user_id'           => $actor->id,
                'beneficiary_id'    => $beneficiaryId,
                'payer_id'          => $payerId,
                'status'            => $certificate['status'],
                'discount_percent'  => $certificate['discount_percent'],
                'billing_cycle'     => $certificate['billing_cycle'],
                'payment_status'    => $certificate['payment_status'],
                'notes'             => $certificate['notes'],
            ]);
        } catch (ServiceException $e) {
            throw self::prefixed($e, 'certificate.');
        }
    }

    // ------------------------------------------------------------------ szczegóły

    /**
     * Formularz z żądania zredukowany do znanych pól (nieznane klucze są odrzucane), z przycięciem tekstów.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $current
     * @return array{company: array<string, mixed>, person: array<string, mixed>, certificate: array<string, mixed>}
     */
    private function normalizeForm(array $data, array $current): array
    {
        $text = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $id = static fn (mixed $value): ?int => (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
        $section = static fn (string $name): array => is_array($data[$name] ?? null) ? $data[$name] : (is_array($current[$name] ?? null) ? $current[$name] : []);

        $company = $section('company');
        $person = $section('person');
        $certificate = $section('certificate');

        return [
            'company'     => [
                'mode'           => ($company['mode'] ?? 'new') === 'existing' ? 'existing' : 'new',
                'payer_id'       => $id($company['payer_id'] ?? null),
                'company_name'   => $text($company['company_name'] ?? ''),
                'tax_id'         => $text($company['tax_id'] ?? ''),
                'contact_person' => $text($company['contact_person'] ?? ''),
                'email'          => $text($company['email'] ?? ''),
                'phone'          => $text($company['phone'] ?? ''),
                'address_line'   => $text($company['address_line'] ?? ''),
                'postal_code'    => $text($company['postal_code'] ?? ''),
                'city'           => $text($company['city'] ?? ''),
            ],
            'person'      => [
                'mode'           => ($person['mode'] ?? 'new') === 'existing' ? 'existing' : 'new',
                'beneficiary_id' => $id($person['beneficiary_id'] ?? null),
                'first_name'     => $text($person['first_name'] ?? ''),
                'last_name'      => $text($person['last_name'] ?? ''),
                'email'          => $text($person['email'] ?? ''),
                'phone'          => $text($person['phone'] ?? ''),
            ],
            'certificate' => [
                'name'              => $text($certificate['name'] ?? ''),
                'certificate_type'  => $text($certificate['certificate_type'] ?? ''),
                'serial_number'     => $text($certificate['serial_number'] ?? ''),
                'issuer'            => $text($certificate['issuer'] ?? ''),
                'valid_from'        => $text($certificate['valid_from'] ?? ''),
                'expiry_date'       => $text($certificate['expiry_date'] ?? ''),
                'renewal_lead_days' => $text($certificate['renewal_lead_days'] ?? ''),
                'discount_percent'  => $text($certificate['discount_percent'] ?? '0'),
                'billing_cycle'     => $text($certificate['billing_cycle'] ?? 'annual'),
                'payment_status'    => $text($certificate['payment_status'] ?? 'due_soon'),
                'status'            => $text($certificate['status'] ?? 'pending'),
                'notes'             => $text($certificate['notes'] ?? ''),
            ],
        ];
    }

    private static function prefixed(ServiceException $e, string $prefix): ServiceException
    {
        if ($e->kind === ServiceException::FORBIDDEN || $e->kind === ServiceException::NOT_FOUND || $e->kind === ServiceException::BAD_REQUEST) {
            return $e;
        }

        $errors = [];
        foreach ($e->errors as $field => $message) {
            $errors[$prefix . $field] = $message;
        }

        return $e->kind === ServiceException::VALIDATION
            ? ServiceException::validation($errors)
            : ServiceException::conflict($e->getMessage(), $e->details, $errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM registration_drafts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ServiceException::notFound(\__('registration.error.not_found'));
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findPending(int $id): array
    {
        $row = $this->find($id);
        if ($row['status'] !== 'pending') {
            throw ServiceException::conflict(\__('registration.error.not_pending'));
        }

        return $row;
    }

    /**
     * Firma dopasowana do wniosku: szczegóły tylko wtedy, gdy konto ją widzi (inaczej samo istnienie).
     *
     * @return array{id: int, visible: bool, company_name?: string, tax_id?: ?string, archived?: bool}
     */
    private function visiblePayer(Actor $actor, int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, company_name, tax_id, archived_at FROM payers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['id' => $id, 'visible' => false];
        }

        if (!$actor->seesAllRecords()) {
            [$visibility, $params] = Visibility::payers($actor, 'p');
            $check = $this->db->prepare("SELECT 1 FROM payers p WHERE p.id = :id AND {$visibility}");
            $check->execute(['id' => $id] + $params);
            if ($check->fetchColumn() === false) {
                return ['id' => $id, 'visible' => false, 'archived' => $row['archived_at'] !== null];
            }
        }

        return ['id' => $id, 'visible' => true, 'company_name' => (string) $row['company_name'], 'tax_id' => $row['tax_id'], 'archived' => $row['archived_at'] !== null];
    }

    /**
     * @return array{id: int, visible: bool, name?: string, email?: ?string}
     */
    private function visiblePerson(Actor $actor, int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, first_name, last_name, email FROM beneficiaries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['id' => $id, 'visible' => false];
        }

        if (!$actor->seesAllRecords()) {
            [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
            $check = $this->db->prepare("SELECT 1 FROM beneficiaries b WHERE b.id = :id AND {$visibility}");
            $check->execute(['id' => $id] + $params);
            if ($check->fetchColumn() === false) {
                return ['id' => $id, 'visible' => false];
            }
        }

        return ['id' => $id, 'visible' => true, 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'email' => $row['email']];
    }

    private function userName(int $id): string
    {
        $stmt = $this->db->prepare("SELECT CONCAT_WS(' ', first_name, last_name) FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeList(mixed $json): array
    {
        return array_values(array_filter(self::decode($json), 'is_array'));
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
