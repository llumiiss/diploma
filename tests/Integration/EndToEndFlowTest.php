<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\BeneficiariesController;
use App\Api\CertificatesController;
use App\Api\EventsController;
use App\Api\ExportController;
use App\Api\ImportController;
use App\Api\InvitationsController;
use App\Api\PayersController;
use App\Api\ReportsController;
use App\Api\SearchController;
use App\Api\TasksController;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use App\Rbac;
use App\Service\AttachmentService;
use App\Service\InvitationService;
use Tests\Support\IntegrationTestCase;
use Tests\Support\RecordingInvitationMailer;

/**
 * Scenariusz od początku do końca przez warstwę API (N6): ewidencja → skaner → zadanie →
 * zaproszenie → odnowienie → karta raportowa → eksport → import → dziennik i wyszukiwarka.
 *
 * Każdy krok przechodzi przez to samo jądro co żądanie HTTP (metoda, CSRF, sesja, kody odpowiedzi),
 * więc test pilnuje nie tylko usług, ale i umowy API oraz uprawnień ról.
 */
final class EndToEndFlowTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $user;

    private RecordingInvitationMailer $mail;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new RecordingInvitationMailer();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'certisub-e2e-' . bin2hex(random_bytes(4));
        $admin = $this->createActor(Rbac::ADMIN, 'admin.e2e@example.com');
        $this->user = [
            'id'         => $admin->id,
            'role'       => $admin->role,
            'first_name' => $admin->firstName,
            'last_name'  => $admin->lastName,
            'email'      => $admin->email,
        ];
    }

    protected function tearDown(): void
    {
        if (!isset($this->storage)) {
            return;
        }
        foreach (glob($this->storage . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storage);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function call(callable $controller, string $method = 'GET', array $query = [], array $body = []): Response
    {
        $kernel = new ApiKernel(
            fn (): array|false => $this->user,
            static fn (?string $token): bool => $token === 'valid-token',
        );

        return $kernel->handle(new Request($method, $query, $body, 'valid-token'), $controller);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function ok(callable $controller, string $method = 'GET', array $query = [], array $body = []): array
    {
        $response = $this->call($controller, $method, $query, $body);
        $this->assertSame(200, $response->status, (string) json_encode($response->payload, JSON_UNESCAPED_UNICODE));

        return $response->payload;
    }

    private function invitations(): InvitationsController
    {
        return new InvitationsController(
            $this->db,
            new InvitationService($this->db, $this->mail, new AttachmentService($this->db, $this->storage))
        );
    }

    public function testWholeRenewalLifecycleThroughTheApi(): void
    {
        $certificates = new CertificatesController($this->db);
        $tasks = new TasksController($this->db);

        // 1. Ewidencja: płatnik → osoba → certyfikat bliski wygaśnięcia.
        $payer = $this->ok(new PayersController($this->db), 'POST', [], ['action' => 'create', 'data' => [
            'company_name'   => 'NovaTech Sp. z o.o.',
            'contact_person' => 'Katarzyna Zielińska',
            'tax_id'         => '6342851974',
            'email'          => 'faktury@novatech.example.com',
        ]])['payer'];

        $person = $this->ok(new BeneficiariesController($this->db), 'POST', [], ['action' => 'create', 'data' => [
            'first_name' => 'Jan',
            'last_name'  => 'Kowalski',
            'email'      => 'jan.kowalski@novatech.example.com',
            'payer_id'   => $payer['id'],
        ]])['beneficiary'];
        $this->assertSame($payer['id'], $person['payer_id']);

        $certificate = $this->ok($certificates, 'POST', [], ['action' => 'create', 'data' => [
            'name'             => 'Podpis kwalifikowany — Jan Kowalski',
            'certificate_type' => 'QUALIFIED_SIGNATURE',
            'serial_number'    => '5A3F9C21B7E04D18',
            'issuer'           => 'Certum',
            'valid_from'       => date('Y-m-d', strtotime('-2 years')),
            'expiry_date'      => date('Y-m-d', strtotime('+5 days')),
            'beneficiary_id'   => $person['id'],
            'discount_percent' => '-10%',
        ]])['certificate'];
        $this->assertSame('critical', $certificate['priority']);

        // 2. Skaner zakłada zadanie ToDo z priorytetem.
        $scan = $this->ok($tasks, 'POST', [], ['action' => 'scan'])['scan'];
        $this->assertSame(1, $scan['created']);
        $task = $this->ok($tasks, 'GET', ['status' => 'open'])['tasks'][0];
        $this->assertSame('critical', $task['priority']);
        $this->assertSame($certificate['id'], $task['certificate_id']);

        // 3. Zaproszenie do użytkownika certyfikatu — wysyłka przez zamiennik poczty.
        $invitation = $this->ok($this->invitations(), 'POST', [], ['action' => 'send', 'data' => [
            'certificate_id' => $certificate['id'],
            'recipient_type' => 'beneficiary',
            'template_id'    => (int) $this->db->query("SELECT id FROM email_templates WHERE code = 'renewal_invitation' AND locale = 'pl'")->fetchColumn(),
        ]])['invitation'];
        $this->assertSame('sent', $invitation['status']);
        $this->assertCount(1, $this->mail->sent);
        $this->assertSame('jan.kowalski@novatech.example.com', $this->mail->sent[0]['to']);
        $this->assertStringContainsString('5A3F9C21B7E04D18', $this->mail->sent[0]['html']);
        $this->assertSame('in_progress', $this->ok($tasks, 'GET', ['id' => $task['id']])['task']['status']);

        // 4. Odnowienie: nowy certyfikat, stary do archiwum, zadanie zrobione.
        $renewal = $this->ok($tasks, 'POST', [], [
            'action' => 'renew',
            'id'     => $task['id'],
            'data'   => ['expiry_date' => date('Y-m-d', strtotime('+2 years')), 'serial_number' => 'QS-2028-777'],
        ]);
        $this->assertSame('done', $renewal['task']['status']);
        $new = $renewal['certificate'];
        $this->assertSame($certificate['id'], $new['previous_certificate_id']);
        $this->assertNotNull($this->ok($certificates, 'GET', ['id' => $certificate['id']])['certificate']['archived_at']);

        // 5. Karta użytkownika certyfikatu: bieżący certyfikat, historia i oś czasu.
        $card = $this->ok(new ReportsController($this->db), 'GET', ['view' => 'beneficiary', 'id' => $person['id']])['report'];
        $this->assertSame([$new['id']], array_column($card['certificates'], 'id'));
        $this->assertSame([$certificate['id']], array_column($card['history'], 'id'));
        $this->assertSame(1, $card['summary']['invitations_sent']);
        $this->assertContains('renewed', array_column($card['timeline'], 'event_type'));

        // 6. Wyszukiwarka znajduje certyfikat po numerze seryjnym starego wydania.
        $search = $this->ok(new SearchController($this->db), 'GET', ['q' => '5A3F9C21'])['search'];
        $this->assertSame([$certificate['id']], array_column($search['groups']['certificates']['items'], 'id'));

        // 7. Eksport certyfikatów do CSV — plik do pobrania z nagłówkami.
        $export = $this->call(new ExportController($this->db), 'GET', ['dataset' => 'certificates', 'format' => 'csv']);
        $this->assertSame(200, $export->status);
        $this->assertSame('text/csv; charset=UTF-8', $export->headers['Content-Type']);
        $this->assertStringContainsString('attachment; filename="certyfikaty-', $export->headers['Content-Disposition']);
        $this->assertStringContainsString('QS-2028-777', (string) $export->rawBody);

        // 8. Import tego samego eksportu płatników niczego nie dubluje.
        $payersCsv = $this->call(new ExportController($this->db), 'GET', ['dataset' => 'payers', 'format' => 'csv'])->rawBody;
        $import = (new \App\Service\ImportService($this->db))->commit(
            \App\Service\Actor::fromUser($this->user),
            'payers',
            'platnicy.csv',
            (string) $payersCsv,
            ['mode' => 'update']
        );
        $this->assertSame(1, $import['totals']['unchanged']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM payers')->fetchColumn());

        // 9. Dziennik zdarzeń zawiera całą ścieżkę realizacji.
        $journal = $this->ok(new EventsController($this->db), 'GET', ['per_page' => '100'])['journal'];
        $this->assertGreaterThanOrEqual(8, $journal['total']);
        foreach (['created', 'task_opened', 'invitation_sent', 'renewed', 'archived', 'data_exported', 'data_imported'] as $event) {
            $this->assertContains($event, array_column($journal['events'], 'event_type'), $event);
        }

        // 10. Dane widać też przez pulpit, a średni rabat liczy się z bieżących certyfikatów.
        $summary = $this->ok(new \App\Api\DashboardController($this->db))['summary'];
        $this->assertSame(['pending' => 0, 'active' => 1, 'renewal_in_progress' => 0, 'expired' => 0], $summary['stats']);
        $this->assertEqualsWithDelta(10.0, $summary['renewal_summary']['average_discount'], 0.01);
    }

    public function testApiRejectsWrongMethodMissingCsrfAndForbiddenRole(): void
    {
        $payers = new PayersController($this->db);

        $kernel = new ApiKernel(fn (): array|false => $this->user, static fn (?string $token): bool => $token === 'valid-token');
        $this->assertSame(405, $kernel->handle(new Request('DELETE', [], []), $payers)->status);
        $this->assertSame(403, $kernel->handle(new Request('POST', [], ['action' => 'create'], 'zły-token'), $payers)->status);
        $this->assertSame(401, (new ApiKernel(static fn (): array|false => false, static fn (?string $token): bool => true))
            ->handle(new Request('GET', [], []), $payers)->status);

        // Operator: brak dostępu do kont, dziennika i importu, 404 dla cudzej karty.
        $operator = $this->createActor(Rbac::OPERATOR, 'operator.e2e@example.com');
        $this->user = ['id' => $operator->id, 'role' => $operator->role, 'first_name' => 'Op', 'last_name' => 'Erator', 'email' => $operator->email];

        $this->assertSame(403, $this->call(new EventsController($this->db))->status);
        $this->assertSame(403, $this->call(new ImportController($this->db), 'GET', ['view' => 'columns', 'dataset' => 'payers'])->status);
        $this->assertSame(403, $this->call(new ExportController($this->db), 'GET', ['dataset' => 'payers', 'format' => 'csv'])->status);

        $foreignPayer = $this->insertPayer(['company_name' => 'Cudzy płatnik']);
        $this->assertSame(404, $this->call(new ReportsController($this->db), 'GET', ['view' => 'payer', 'id' => $foreignPayer])->status);
    }
}
