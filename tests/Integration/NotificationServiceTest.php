<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\NotificationsController;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Rbac;
use App\Service\Actor;
use App\Service\NotificationService;
use App\Service\ServiceException;
use App\Service\TaskService;
use Tests\Support\IntegrationTestCase;

/**
 * Powiadomienia wewnętrzne (Etap 10): wiadomości, wątki, prośby do załatwienia i komunikaty systemowe.
 */
final class NotificationServiceTest extends IntegrationTestCase
{
    private Actor $admin;
    private Actor $operator;
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createActor(Rbac::ADMIN);
        $this->operator = $this->createActor(Rbac::OPERATOR);
        $this->service = new NotificationService($this->db);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'to'      => [$this->admin->id],
            'type'    => 'request',
            'subject' => 'Brakuje numeru seryjnego',
            'body'    => 'Uzupełnij numer seryjny certyfikatu Jana Kowalskiego.',
        ], $overrides);
    }

    public function testOperatorSendsRequestAndAdministratorReadsAndAnswersIt(): void
    {
        $result = $this->service->send($this->operator, $this->message());
        $this->assertSame(1, $result['recipients']);

        $this->assertSame(['unread' => 1, 'open_requests' => 1], $this->service->counters($this->admin));
        $inbox = $this->service->inbox($this->admin);
        $this->assertCount(1, $inbox);
        $this->assertSame('request', $inbox[0]['type']);
        $this->assertTrue($inbox[0]['unread']);
        $this->assertTrue($inbox[0]['open']);
        $this->assertSame($this->operator->fullName(), $inbox[0]['sender_name']);

        // Otwarcie wątku oznacza wiadomość jako przeczytaną.
        $thread = $this->service->thread($this->admin, $inbox[0]['id']);
        $this->assertCount(1, $thread['messages']);
        $this->assertSame(0, $this->service->counters($this->admin)['unread']);

        $this->service->reply($this->admin, $inbox[0]['id'], 'Numer to 5A3F9C21B7E04D18.');
        $answer = $this->service->inbox($this->operator);
        $this->assertCount(1, $answer);
        $this->assertSame('Re: Brakuje numeru seryjnego', $answer[0]['subject']);
        $this->assertSame($this->admin->id, $answer[0]['sender_user_id']);

        $conversation = $this->service->thread($this->operator, $answer[0]['id']);
        $this->assertSame(['Uzupełnij numer seryjny certyfikatu Jana Kowalskiego.', 'Numer to 5A3F9C21B7E04D18.'], array_column($conversation['messages'], 'body'));
        $this->assertSame([true, false], array_column($conversation['messages'], 'mine'));
    }

    public function testRequestCanBeMarkedAsDoneAndTheSenderIsInformed(): void
    {
        $this->service->send($this->operator, $this->message());
        $id = $this->service->inbox($this->admin)[0]['id'];

        $this->service->resolve($this->admin, $id);

        $this->assertSame(0, $this->service->counters($this->admin)['open_requests']);
        $this->assertNotNull($this->service->inbox($this->admin)[0]['resolved_at']);
        $informing = $this->service->inbox($this->operator);
        $this->assertCount(1, $informing);
        $this->assertStringStartsWith('Załatwione:', $informing[0]['subject']);

        $this->expectException(ServiceException::class);
        $this->service->resolve($this->admin, $id);
    }

    public function testMessageToGroupOfAdministratorsReachesEveryActiveAdministrator(): void
    {
        $second = $this->createActor(Rbac::ADMIN);
        $inactive = $this->createActor(Rbac::ADMIN);
        $this->db->exec("UPDATE users SET deactivated_at = NOW() WHERE id = {$inactive->id}");

        $result = $this->service->send($this->operator, $this->message(['to' => [], 'groups' => ['admins']]));

        $this->assertSame(2, $result['recipients']);
        $this->assertCount(1, $this->service->inbox($this->admin));
        $this->assertCount(1, $this->service->inbox($second));
        $this->assertCount(0, $this->service->inbox($inactive));

        $sent = $this->service->sent($this->operator);
        $this->assertCount(1, $sent);
        $this->assertSame(2, $sent[0]['recipient_count']);
        $this->assertSame(0, $sent[0]['read_count']);
    }

    public function testOnlyAdministratorBroadcastsToEveryone(): void
    {
        $this->createActor(Rbac::EMPLOYEE);
        $manager = $this->createActor(Rbac::MANAGER);

        try {
            $this->service->send($this->operator, $this->message(['to' => [], 'groups' => ['all']]));
            $this->fail('Operator nie wysyła do wszystkich.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('to', $e->errors);
        }

        $result = $this->service->send($this->admin, $this->message(['to' => [], 'groups' => ['all'], 'type' => 'message']));
        $this->assertSame(3, $result['recipients']);
        $this->assertCount(1, $this->service->inbox($manager));
    }

    public function testRecipientListFollowsTheRoleOfTheSender(): void
    {
        $employee = $this->createActor(Rbac::EMPLOYEE);
        $manager = $this->createActor(Rbac::MANAGER);
        $accountant = $this->createActor(Rbac::ACCOUNTANT);

        $roles = static fn (array $recipients): array => array_values(array_unique(array_column($recipients['users'], 'role')));

        // Administrator pisze do wszystkich innych kont (siebie na liście nie ma).
        $this->assertEqualsCanonicalizing([Rbac::OPERATOR, Rbac::MANAGER, Rbac::ACCOUNTANT, Rbac::EMPLOYEE], $roles($this->service->recipients($this->admin)));
        $this->assertEqualsCanonicalizing([Rbac::ADMIN, Rbac::MANAGER, Rbac::ACCOUNTANT], $roles($this->service->recipients($this->operator)));
        $this->assertEqualsCanonicalizing([Rbac::ADMIN, Rbac::MANAGER], $roles($this->service->recipients($employee)));
        $this->assertNotContains($this->operator->id, array_column($this->service->recipients($employee)['users'], 'id'));
        $this->assertNotContains($employee->id, array_column($this->service->recipients($this->operator)['users'], 'id'));
        $this->assertNotContains($accountant->id, array_column($this->service->recipients($employee)['users'], 'id'));
        $this->assertSame(['admins', 'all'], $this->service->recipients($this->admin)['groups']);
        $this->assertSame(['admins'], $this->service->recipients($manager)['groups']);

        $this->expectException(ServiceException::class);
        $this->service->send($employee, $this->message(['to' => [$this->operator->id]]));
    }

    public function testStrangersCannotReadTheirThreadsOrActOnForeignMessages(): void
    {
        $stranger = $this->createActor(Rbac::OPERATOR);
        $this->service->send($this->operator, $this->message());
        $id = $this->service->inbox($this->admin)[0]['id'];

        foreach ([
            fn () => $this->service->thread($stranger, $id),
            fn () => $this->service->reply($stranger, $id, 'Wtrącam się'),
            fn () => $this->service->resolve($stranger, $id),
            fn () => $this->service->archive($stranger, $id),
            // Nadawca nie rozstrzyga prośby, którą wysłał — robi to odbiorca.
            fn () => $this->service->resolve($this->operator, $id),
        ] as $index => $attempt) {
            try {
                $attempt();
                $this->fail("Próba {$index} powinna się nie udać.");
            } catch (ServiceException $e) {
                $this->assertSame(404, $e->httpStatus(), "Próba {$index}");
            }
        }

        $this->assertSame(0, $this->service->markRead($stranger, [$id]));
        $this->assertSame(1, $this->service->counters($this->admin)['unread']);
    }

    public function testValidationAndRelatedRecordVisibility(): void
    {
        foreach ([['subject' => ''], ['body' => ''], ['to' => []], ['type' => 'system'], ['to' => [999999]]] as $index => $overrides) {
            try {
                $this->service->send($this->operator, $this->message($overrides));
                $this->fail("Wiadomość {$index} powinna zostać odrzucona.");
            } catch (ServiceException $e) {
                $this->assertSame(422, $e->httpStatus(), "Wiadomość {$index}");
            }
        }

        $payerId = $this->insertPayer(['company_name' => 'Widoczna'], $this->operator->id);
        $foreignPayer = $this->insertPayer(['company_name' => 'Cudza']);

        $this->service->send($this->operator, $this->message(['related_type' => 'payer', 'related_id' => $payerId]));
        $received = $this->service->inbox($this->admin)[0];
        $this->assertSame('payer', $received['related_type']);
        $this->assertSame($payerId, $received['related_id']);

        $this->expectException(ServiceException::class);
        $this->service->send($this->operator, $this->message(['related_type' => 'payer', 'related_id' => $foreignPayer]));
    }

    public function testSystemMessagesHaveNoSenderAndCannotBeAnswered(): void
    {
        $count = $this->service->notify([$this->operator->id, $this->operator->id, 999999], 'Nowy wniosek', 'Z e-maila przyszedł wniosek.', 'registration', 7);
        $this->assertSame(1, $count);

        $inbox = $this->service->inbox($this->operator);
        $this->assertSame('system', $inbox[0]['type']);
        $this->assertNull($inbox[0]['sender_user_id']);
        $this->assertNull($inbox[0]['sender_name']);
        $this->assertSame('registration', $inbox[0]['related_type']);

        $this->assertSame([], $this->service->inbox($this->operator, ['type' => 'message']));

        $this->expectException(ServiceException::class);
        $this->service->reply($this->operator, $inbox[0]['id'], 'Odpowiedź');
    }

    public function testUsersWithPermissionAreActiveAccountsOnly(): void
    {
        $inactive = $this->createActor(Rbac::OPERATOR);
        $this->db->exec("UPDATE users SET deactivated_at = NOW() WHERE id = {$inactive->id}");
        $employee = $this->createActor(Rbac::EMPLOYEE);

        $ids = $this->service->usersWithPermission('registrations.review');

        $this->assertContains($this->admin->id, $ids);
        $this->assertContains($this->operator->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($employee->id, $ids);
    }

    public function testReadingArchivingAndMarkAllRead(): void
    {
        foreach (['Pierwsza', 'Druga', 'Trzecia'] as $subject) {
            $this->service->send($this->operator, $this->message(['subject' => $subject, 'type' => 'message']));
        }
        $ids = array_column($this->service->inbox($this->admin), 'id');
        $this->assertSame(3, $this->service->counters($this->admin)['unread']);

        $this->assertSame(1, $this->service->markRead($this->admin, [$ids[0]]));
        $this->service->markUnread($this->admin, $ids[0]);
        $this->assertSame(3, $this->service->counters($this->admin)['unread']);

        $this->service->archive($this->admin, $ids[1]);
        $this->assertCount(2, $this->service->inbox($this->admin));
        $this->assertCount(1, $this->service->inbox($this->admin, ['q' => 'Trzecia']));
        $this->assertSame(2, $this->service->counters($this->admin)['unread']);

        $this->assertSame(2, $this->service->markRead($this->admin));
        $this->assertSame([], $this->service->inbox($this->admin, ['status' => 'unread']));
    }

    public function testTaskAssignmentNotifiesTheAssignee(): void
    {
        $payerId = $this->insertPayer();
        $certificateId = $this->insertCertificate($this->admin->id, $payerId, [
            'name' => 'SSL do odnowienia', 'certificate_type' => 'SSL_CERTIFICATE', 'expiry_date' => date('Y-m-d', strtotime('+4 days')),
        ]);
        $tasks = new TaskService($this->db);

        $task = $tasks->create($this->admin, ['certificate_id' => $certificateId, 'assigned_user_id' => $this->operator->id]);
        $notices = $this->service->inbox($this->operator);
        $this->assertCount(1, $notices);
        $this->assertSame('system', $notices[0]['type']);
        $this->assertStringContainsString('SSL do odnowienia', $notices[0]['subject']);
        $this->assertSame($certificateId, $notices[0]['related_id']);

        // Przydzielenie zadania samemu sobie nie wysyła komunikatu, a zmiana osoby — wysyła.
        $other = $this->createActor(Rbac::MANAGER);
        $tasks->assign($this->admin, $task['id'], $other->id);
        $this->assertCount(1, $this->service->inbox($other));
        $tasks->assign($this->admin, $task['id'], $this->admin->id);
        $this->assertCount(0, $this->service->inbox($this->admin));
    }

    public function testApiRoundTrip(): void
    {
        $asUser = function (Actor $actor): ApiKernel {
            $user = ['id' => $actor->id, 'role' => $actor->role, 'first_name' => 'X', 'last_name' => 'Y', 'email' => $actor->email];

            return new ApiKernel(static fn (): array => $user, static fn (?string $token): bool => true);
        };
        $controller = new NotificationsController($this->db);

        $sent = $asUser($this->operator)->handle(new Request('POST', [], ['action' => 'send', 'data' => $this->message()]), $controller);
        $this->assertSame(200, $sent->status);

        $inbox = $asUser($this->admin)->handle(new Request('GET', ['status' => 'unread']), $controller);
        $this->assertSame(1, $inbox->payload['counters']['unread']);
        $id = $inbox->payload['notifications'][0]['id'];

        $thread = $asUser($this->admin)->handle(new Request('GET', ['view' => 'thread', 'id' => (string) $id]), $controller);
        $this->assertSame(200, $thread->status);

        $reply = $asUser($this->admin)->handle(new Request('POST', [], ['action' => 'reply', 'id' => $id, 'body' => 'Ok']), $controller);
        $this->assertSame(200, $reply->status);

        $this->assertSame(400, $asUser($this->admin)->handle(new Request('POST', [], ['action' => 'nope']), $controller)->status);
        $this->assertSame(422, $asUser($this->operator)->handle(new Request('POST', [], ['action' => 'send', 'data' => ['to' => []]]), $controller)->status);
    }
}
