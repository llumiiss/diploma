<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\DashboardController;
use App\Cache;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Rbac;
use App\Service\Actor;
use App\Service\CertificateService;
use App\Service\TaskService;
use Tests\Support\IntegrationTestCase;

/**
 * Cache agregatów (§2.5): przyspiesza pulpit, ale nie może pokazać liczb spoza zakresu konta
 * ani danych sprzed ostatniego zapisu.
 */
final class DashboardCacheTest extends IntegrationTestCase
{
    private Actor $admin;
    private Actor $operator;
    private int $payerId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        $this->admin = $this->createActor(Rbac::ADMIN, 'admin.cache@example.com');
        $this->operator = $this->createActor(Rbac::OPERATOR, 'operator.cache@example.com');
        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.']);
        $this->insertCertificate($this->admin->id, $this->payerId, ['name' => 'Certyfikat administratora']);
        $this->insertCertificate($this->operator->id, $this->payerId, ['name' => 'Certyfikat operatora']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Actor $actor): array
    {
        $user = ['id' => $actor->id, 'role' => $actor->role, 'first_name' => 'X', 'last_name' => 'Y', 'email' => $actor->email];
        $kernel = new ApiKernel(static fn (): array => $user, static fn (?string $token): bool => true);
        $response = $kernel->handle(new Request('GET', [], []), new DashboardController($this->db));
        $this->assertSame(200, $response->status);

        return $response->payload['summary'];
    }

    public function testEachRoleGetsItsOwnCachedNumbers(): void
    {
        $this->assertSame(2, array_sum($this->summary($this->admin)['stats']));
        $this->assertSame(1, array_sum($this->summary($this->operator)['stats']));

        // Powtórzony odczyt idzie z cache i nadal jest właściwy dla roli.
        $this->assertSame(2, array_sum($this->summary($this->admin)['stats']));
        $this->assertSame(1, array_sum($this->summary($this->operator)['stats']));
    }

    public function testNewRecordInvalidatesTheCachedSummaryAndTaskStats(): void
    {
        $this->assertSame(2, array_sum($this->summary($this->admin)['stats']));
        $tasks = new TaskService($this->db);
        $this->assertSame(0, $tasks->stats($this->admin)['open']);

        (new CertificateService($this->db))->create($this->admin, [
            'name'             => 'Nowy certyfikat',
            'certificate_type' => 'DOMAIN',
            'expiry_date'      => date('Y-m-d', strtotime('+3 days')),
            'payer_id'         => $this->payerId,
        ]);

        $this->assertSame(3, array_sum($this->summary($this->admin)['stats']));

        $certificateId = (int) $this->db->query("SELECT id FROM certificates WHERE name = 'Nowy certyfikat'")->fetchColumn();
        $tasks->create($this->admin, ['certificate_id' => $certificateId]);
        $this->assertSame(1, $tasks->stats($this->admin)['open']);
    }
}
