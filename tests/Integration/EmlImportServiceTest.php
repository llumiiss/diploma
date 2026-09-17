<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\EmlImportService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class EmlImportServiceTest extends IntegrationTestCase
{
    private Actor $admin;
    private int $payerId;
    private int $personId;
    private int $certificateId;
    private int $invitationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createActor(Rbac::ADMIN);
        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974']);
        $this->personId = $this->insertBeneficiary($this->payerId, ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan.kowalski@example.com']);
        $this->certificateId = $this->insertCertificate($this->admin->id, $this->payerId, [
            'name'           => 'Podpis kwalifikowany',
            'serial_number'  => '5A3F9C21B7E04D18',
            'beneficiary_id' => $this->personId,
        ]);
        $this->db->exec(
            "INSERT INTO invitations (certificate_id, recipient_type, recipient_email, subject, body_html, status, sent_at)
             VALUES ({$this->certificateId}, 'beneficiary', 'jan.kowalski@example.com', 'Odnowienie', '<p>x</p>', 'sent', NOW())"
        );
        $this->invitationId = (int) $this->db->lastInsertId();
    }

    private static function message(string $from, string $body, string $attachment = ''): string
    {
        $eml = "From: {$from}\r\nTo: biuro@certisub.local\r\nSubject: =?UTF-8?Q?Re:_Odnowienie_certyfikatu?=\r\n"
            . "Date: Wed, 16 Sep 2026 10:15:00 +0200\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}\r\n";
        if ($attachment !== '') {
            $eml .= "--B\r\nContent-Type: text/csv\r\nContent-Disposition: attachment; filename=\"lista.csv\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($attachment));
        }

        return $eml . "--B--\r\n";
    }

    public function testAnalysisRecognisesSenderCertificatePayerInvitationAndAttachment(): void
    {
        $eml = self::message(
            'Jan Kowalski <jan.kowalski@example.com>',
            'Dzień dobry, w sprawie certyfikatu 5A3F-9C21-B7E0-4D18 firmy NIP 634-285-19-74 — proszę o fakturę.',
            "Nazwa;Typ;Data wygaśnięcia\r\nDomena;Domena;2027-01-01\r\n"
        );

        $analysis = (new EmlImportService($this->db))->analyze($this->admin, 'odpowiedz.eml', $eml);

        $this->assertSame('Re: Odnowienie certyfikatu', $analysis['message']['subject']);
        $this->assertSame([$this->personId], array_column($analysis['sender']['beneficiaries'], 'id'));
        $this->assertNull($analysis['sender']['suggestion']);
        $this->assertSame([$this->certificateId], array_column($analysis['certificates'], 'id'));
        $this->assertSame([$this->payerId], array_column($analysis['payers'], 'id'));
        $this->assertSame([$this->invitationId], array_column($analysis['invitations'], 'id'));
        $this->assertSame(['6342851974'], $analysis['detected']['tax_ids']);
        $this->assertSame('lista.csv', $analysis['message']['attachments'][0]['filename']);
        $this->assertTrue($analysis['message']['attachments'][0]['importable']);
        $this->assertSame('certificates', $analysis['message']['attachments'][0]['dataset']);
        $this->assertSame(1, $analysis['message']['attachments'][0]['rows']);
    }

    public function testUnknownSenderGetsSuggestionAndApplyRecordsTheMessage(): void
    {
        $eml = self::message('"Nowak, Anna" <anna.nowak@example.com>', 'Proszę o kontakt: tel. 600 700 800. Dotyczy 5A3F9C21B7E04D18.');
        $service = new EmlImportService($this->db);

        $analysis = $service->analyze($this->admin, 'nowa.eml', $eml);
        $this->assertSame([], $analysis['sender']['beneficiaries']);
        $this->assertSame(
            ['first_name' => 'Anna', 'last_name' => 'Nowak', 'email' => 'anna.nowak@example.com', 'phone' => '600 700 800'],
            $analysis['sender']['suggestion']
        );

        $result = $service->apply($this->admin, 'nowa.eml', $eml, [
            'create_beneficiary' => $analysis['sender']['suggestion'] + ['payer_id' => $this->payerId],
            'note_certificates'  => [$this->certificateId],
            'responded'          => [(string) $this->invitationId],
        ]);

        $this->assertSame('Anna Nowak', $result['beneficiary']['name']);
        $this->assertSame(1, $result['responded']);
        $this->assertSame(2, $result['notes']);
        $this->assertSame('responded', $this->db->query("SELECT status FROM invitations WHERE id = {$this->invitationId}")->fetchColumn());
        $this->assertSame(1, $this->countEvents('certificate', $this->certificateId, 'email_received'));
        $this->assertSame(1, $this->countEvents('beneficiary', (int) $result['beneficiary']['id'], 'email_received'));

        $payload = json_decode((string) $this->db->query(
            "SELECT payload FROM events WHERE event_type = 'email_received' AND entity_type = 'certificate'"
        )->fetchColumn(), true);
        $this->assertSame('Re: Odnowienie certyfikatu', $payload['subject']);
        $this->assertSame('anna.nowak@example.com', $payload['from']);
        $this->assertStringContainsString('600 700 800', $payload['excerpt']);
        // Zdarzenie certyfikatu trafia też na oś czasu osoby i płatnika.
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'email_received' AND payer_id = {$this->payerId} AND certificate_id = {$this->certificateId}")->fetchColumn());
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'eml_imported'")->fetchColumn());
    }

    public function testApplyIsAtomicAndOnlyForAdministrator(): void
    {
        $eml = self::message('jan.kowalski@example.com', 'Treść');
        $service = new EmlImportService($this->db);

        try {
            $service->apply($this->admin, 'x.eml', $eml, [
                'note_certificates' => [$this->certificateId],
                'responded'         => [999999],
            ]);
            $this->fail('Nieistniejące zaproszenie powinno przerwać całą operację.');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->httpStatus());
        }
        $this->assertSame(0, $this->countEvents('certificate', $this->certificateId, 'email_received'));

        try {
            $service->analyze($this->createActor(Rbac::MANAGER), 'x.eml', $eml);
            $this->fail('Import EML jest tylko dla administratora.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }
    }
}
