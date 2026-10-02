<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\InboundMailEndpoint;
use App\Api\RegistrationsController;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Intake\ImapClient;
use App\Rbac;
use App\Service\Actor;
use App\Service\RegistrationIntakeService;
use Tests\Support\FakeCompanyRegistry;
use Tests\Support\IntegrationTestCase;
use Tests\Support\ScriptedImapServer;

/**
 * Warstwa HTTP wniosków e-mail: kontroler panelu (sesja i CSRF) oraz webhook (wspólny sekret).
 */
final class RegistrationApiTest extends IntegrationTestCase
{
    private FakeCompanyRegistry $registry;
    private RegistrationIntakeService $intake;
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new FakeCompanyRegistry();
        $this->registry->add('6342851974', FakeCompanyRegistry::subject('NOVATECH SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '6342851974', 'PRZEMYSŁOWA 12, 40-020 KATOWICE'));
        $this->storage = sys_get_temp_dir() . '/certisub-api-' . bin2hex(random_bytes(4));
        $this->intake = new RegistrationIntakeService($this->db, $this->registry, $this->storage);
    }

    protected function tearDown(): void
    {
        // Bez flagi RUN_INTEGRATION_TESTS test jest pominięty w setUp, zanim powstał katalog.
        if ($this->storage !== '') {
            foreach (glob($this->storage . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storage);
        }
        parent::tearDown();
    }

    private function raw(string $body = "Imię: Jan\nNazwisko: Kowalski\nNIP: 6342851974\nE-mail: jan@novatech.example.com\n"): string
    {
        static $sequence = 0;
        ++$sequence;

        return "From: Jan Kowalski <jan@novatech.example.com>\r\nSubject: Wniosek\r\nMessage-ID: <api-{$sequence}@example.com>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body;
    }

    private function kernel(Actor $actor): ApiKernel
    {
        $user = ['id' => $actor->id, 'role' => $actor->role, 'first_name' => 'X', 'last_name' => 'Y', 'email' => $actor->email];

        return new ApiKernel(static fn (): array => $user, static fn (?string $token): bool => true);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function call(Actor $actor, string $method, array $query = [], array $body = [], ?RegistrationsController $controller = null): \App\Http\Response
    {
        return $this->kernel($actor)->handle(new Request($method, $query, $body), $controller ?? new RegistrationsController($this->db, null, $this->intake));
    }

    public function testListDetailAndApprovalOverHttp(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $draftId = (int) $this->intake->ingest($this->raw())['draft_id'];

        $list = $this->call($operator, 'GET');
        $this->assertSame(200, $list->status);
        $this->assertSame(1, $list->payload['pending']);
        $this->assertSame($draftId, $list->payload['registrations'][0]['id']);

        $status = $this->call($operator, 'GET', ['view' => 'status']);
        $this->assertSame(1, $status->payload['status']['pending']);
        $this->assertFalse($status->payload['status']['imap_enabled']);
        $this->assertFalse($status->payload['status']['webhook_enabled']);

        $detail = $this->call($operator, 'GET', ['id' => (string) $draftId]);
        $this->assertSame(200, $detail->status);
        $form = $detail->payload['registration']['form'];
        $this->assertSame('Jan', $form['person']['first_name']);

        $saved = $this->call($operator, 'POST', [], ['action' => 'save', 'id' => $draftId, 'data' => array_replace_recursive($form, ['person' => ['phone' => '600 100 200']])]);
        $this->assertSame('600 100 200', $saved->payload['registration']['form']['person']['phone']);

        $approved = $this->call($operator, 'POST', [], ['action' => 'approve', 'id' => $draftId, 'data' => $saved->payload['registration']['form']]);
        $this->assertSame(200, $approved->status);
        $this->assertSame('approved', $approved->payload['registration']['status']);
        $this->assertNotNull($approved->payload['registration']['result']['certificate_id']);

        $this->assertSame(409, $this->call($operator, 'POST', [], ['action' => 'approve', 'id' => $draftId, 'data' => $form])->status);
        $this->assertSame(0, $this->call($operator, 'GET')->payload['pending']);
    }

    public function testValidationErrorsAreReturnedWithSectionPrefixes(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $draftId = (int) $this->intake->ingest($this->raw())['draft_id'];
        $form = $this->call($operator, 'GET', ['id' => (string) $draftId])->payload['registration']['form'];
        $form['person']['last_name'] = '';

        $response = $this->call($operator, 'POST', [], ['action' => 'approve', 'id' => $draftId, 'data' => $form]);

        $this->assertSame(422, $response->status);
        $this->assertArrayHasKey('person.last_name', (array) $response->payload['errors']);
    }

    public function testRejectRefreshAndClaimActions(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $draftId = (int) $this->intake->ingest($this->raw())['draft_id'];

        $this->assertSame($operator->id, $this->call($operator, 'POST', [], ['action' => 'claim', 'id' => $draftId])->payload['registration']['assigned_user_id']);
        $refresh = $this->call($operator, 'POST', [], ['action' => 'refresh_company', 'id' => $draftId, 'tax_id' => '634-285-19-74']);
        $this->assertSame('found', $refresh->payload['registration']['company_lookup']['status']);
        $this->assertSame(422, $this->call($operator, 'POST', [], ['action' => 'refresh_company', 'id' => $draftId, 'tax_id' => '123'])->status);

        $reject = $this->call($operator, 'POST', [], ['action' => 'reject', 'id' => $draftId, 'reason' => 'Spam']);
        $this->assertSame('rejected', $reject->payload['registration']['status']);

        $this->assertSame(400, $this->call($operator, 'POST', [], ['action' => 'nope'])->status);
        $this->assertSame(400, $this->call($operator, 'POST', [], ['action' => 'approve'])->status);
        $this->assertSame(404, $this->call($operator, 'GET', ['id' => '999999'])->status);
    }

    public function testRolesWithoutPermissionAreRejected(): void
    {
        $draftId = (int) $this->intake->ingest($this->raw())['draft_id'];

        foreach ([Rbac::EMPLOYEE, Rbac::ACCOUNTANT] as $role) {
            $actor = $this->createActor($role);
            $this->assertSame(403, $this->call($actor, 'GET')->status, $role);
            $this->assertSame(403, $this->call($actor, 'GET', ['id' => (string) $draftId])->status, $role);
        }
        $this->assertSame(403, $this->call($this->createActor(Rbac::DIRECTOR), 'POST', [], ['action' => 'reject', 'id' => $draftId])->status);
    }

    public function testFetchingTheMailboxFromThePanel(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $server = new ScriptedImapServer();
        $server->addMessage(1, $this->raw());
        $server->addMessage(2, $this->raw("Imię: Ewa\nNazwisko: Pawlak\nNIP: 6342851974\n"));
        $controller = new RegistrationsController($this->db, null, $this->intake, static function () use ($server): ImapClient {
            $client = new ImapClient($server);
            $client->greeting();
            $client->loginPassword('u', 'sekret');

            return $client;
        });

        $response = $this->call($operator, 'POST', [], ['action' => 'fetch'], $controller);

        $this->assertSame(200, $response->status);
        $this->assertSame(2, $response->payload['fetch']['created']);
        $this->assertTrue($server->closed, 'połączenie jest zamykane po przebiegu');

        // Bez konfiguracji IMAP i bez własnej fabryki przycisk zgłasza, że odbiór jest wyłączony.
        $disabled = $this->call($operator, 'POST', [], ['action' => 'fetch']);
        $this->assertSame(409, $disabled->status);
        $this->assertStringContainsString('IMAP', (string) $disabled->payload['message']);

        $this->assertSame(403, $this->call($this->createActor(Rbac::DIRECTOR), 'POST', [], ['action' => 'fetch'], $controller)->status);
    }

    public function testMailboxFailureIsReportedWithoutLeakingCredentials(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $controller = new RegistrationsController($this->db, null, $this->intake, static function (): ImapClient {
            $client = new ImapClient(new ScriptedImapServer('prawidlowe'));
            $client->greeting();
            $client->loginPassword('u@example.com', 'zle-haslo-xyz');

            return $client;
        });

        $response = $this->call($operator, 'POST', [], ['action' => 'fetch'], $controller);

        $this->assertSame(409, $response->status);
        $this->assertStringNotContainsString('zle-haslo-xyz', (string) $response->payload['message']);
    }

    public function testWebhookRequiresTheSharedSecret(): void
    {
        $endpoint = new InboundMailEndpoint($this->db, $this->intake, 'sekret-webhooka-1234567890');

        $this->assertSame(401, $endpoint->handle('POST', null, $this->raw())->status);
        $this->assertSame(401, $endpoint->handle('POST', 'zly-sekret', $this->raw())->status);
        $this->assertSame(405, $endpoint->handle('GET', 'sekret-webhooka-1234567890', '')->status);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM registration_drafts')->fetchColumn());

        $ok = $endpoint->handle('POST', 'sekret-webhooka-1234567890', $this->raw());
        $this->assertSame(200, $ok->status);
        $this->assertSame('created', $ok->payload['status']);
        $this->assertTrue($ok->payload['success']);
        $this->assertSame('webhook', $this->db->query("SELECT source FROM registration_drafts WHERE id = {$ok->payload['draft_id']}")->fetchColumn());
    }

    public function testWebhookIsDisabledWithoutAToken(): void
    {
        $this->assertSame(503, (new InboundMailEndpoint($this->db, $this->intake, ''))->handle('POST', '', $this->raw())->status);
    }

    public function testWebhookHandlesDuplicatesJunkAndBadInput(): void
    {
        $endpoint = new InboundMailEndpoint($this->db, $this->intake, 'sekret-webhooka-1234567890');
        $raw = $this->raw();

        $this->assertSame('created', $endpoint->handle('POST', 'sekret-webhooka-1234567890', $raw)->payload['status']);
        $this->assertSame('duplicate', $endpoint->handle('POST', 'sekret-webhooka-1234567890', $raw)->payload['status']);

        $junk = "From: Ktoś <ktos@example.com>\r\nSubject: Spotkanie\r\nMessage-ID: <junk@example.com>\r\n\r\nSpotkanie o 10.";
        $this->assertSame('skipped', $endpoint->handle('POST', 'sekret-webhooka-1234567890', $junk)->payload['status']);

        $this->assertSame(422, $endpoint->handle('POST', 'sekret-webhooka-1234567890', 'to nie jest e-mail')->status);
        $this->assertSame(422, $endpoint->handle('POST', 'sekret-webhooka-1234567890', '   ')->status);
        $this->assertSame(413, $endpoint->handle('POST', 'sekret-webhooka-1234567890', str_repeat('x', 10_000_001))->status);
    }
}
