<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\EventLogger;
use App\Service\ServiceException;
use App\Service\TimelineService;
use Tests\Support\IntegrationTestCase;

final class EventJournalTest extends IntegrationTestCase
{
    private Actor $admin;
    private Actor $manager;
    private int $certificateId;
    private int $payerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createActor(Rbac::ADMIN);
        $this->manager = $this->createActor(Rbac::MANAGER);
        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.']);
        $this->certificateId = $this->insertCertificate($this->admin->id, $this->payerId, ['name' => 'Pieczęć kwalifikowana']);

        $events = new EventLogger($this->db);
        $events->log('certificate', $this->certificateId, 'updated', $this->admin->id, [
            'certificate_id' => $this->certificateId, 'payer_id' => $this->payerId,
        ], ['changes' => ['expiry_date' => ['from' => '2026-10-01', 'to' => '2027-10-01']]], '2026-01-10 10:00:00');
        $events->log('payer', $this->payerId, 'created', $this->manager->id, ['payer_id' => $this->payerId], [
            'company_name' => 'NovaTech Sp. z o.o.',
        ], '2026-02-01 23:59:00');
        $events->log('system', null, 'renewal_scan', null, [], ['scanned' => 3, 'created' => 1, 'updated' => 0], '2026-02-15 06:00:00');
        $events->log('invitation', 7, 'invitation_sent', $this->manager->id, ['certificate_id' => $this->certificateId], [
            'recipient_email' => 'jan.kowalski@example.com',
        ], '2026-03-01 09:30:00');
        $events->log('user', $this->manager->id, 'role_changed', $this->admin->id, [], [], '2026-03-02 08:00:00');
    }

    private function journal(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        return (new TimelineService($this->db))->journal($this->admin, $filters, $page, $perPage);
    }

    public function testOnlyAdministratorReadsTheJournal(): void
    {
        try {
            (new TimelineService($this->db))->journal($this->manager);
            $this->fail('Dziennik zdarzeń jest tylko dla administratora.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }
    }

    public function testReturnsNewestFirstWithRelatedNames(): void
    {
        $journal = $this->journal();

        $this->assertSame(5, $journal['total']);
        $this->assertSame(1, $journal['pages']);
        $this->assertSame(
            ['role_changed', 'invitation_sent', 'renewal_scan', 'created', 'updated'],
            array_column($journal['events'], 'event_type')
        );
        $roleChange = $journal['events'][0];
        $this->assertSame($this->manager->fullName(), $roleChange['entity_label']);
        $this->assertSame($this->admin->fullName(), $roleChange['user_name']);
        $update = $journal['events'][4];
        $this->assertSame('Pieczęć kwalifikowana', $update['certificate_name']);
        $this->assertSame('NovaTech Sp. z o.o.', $update['payer_name']);
        $this->assertSame('2027-10-01', $update['payload']['changes']['expiry_date']['to']);
        $this->assertSame('', $journal['events'][2]['user_name']);
    }

    public function testFiltersByAreaEventAuthorDatesAndText(): void
    {
        $this->assertSame(['created'], array_column($this->journal(['entity_type' => 'payer'])['events'], 'event_type'));
        $this->assertSame(['renewal_scan'], array_column($this->journal(['event_type' => 'renewal_scan'])['events'], 'event_type'));
        $this->assertSame(['renewal_scan'], array_column($this->journal(['user' => 'system'])['events'], 'event_type'));
        $this->assertSame(
            ['role_changed', 'updated'],
            array_column($this->journal(['user' => (string) $this->admin->id])['events'], 'event_type')
        );
        // Data końcowa obejmuje cały dzień.
        $this->assertSame(
            ['renewal_scan', 'created'],
            array_column($this->journal(['date_from' => '2026-02-01', 'date_to' => '2026-02-15'])['events'], 'event_type')
        );
        $this->assertSame(['invitation_sent'], array_column($this->journal(['q' => 'jan.kowalski@'])['events'], 'event_type'));
        $this->assertSame(
            ['invitation_sent', 'updated'],
            array_column($this->journal(['q' => 'Pieczęć'])['events'], 'event_type')
        );
        $this->assertSame(
            ['invitation_sent', 'updated'],
            array_column($this->journal(['certificate_id' => (string) $this->certificateId])['events'], 'event_type')
        );
        $this->assertSame(0, $this->journal(['q' => '100%'])['total']);
    }

    public function testPaginatesAndReturnsFilterFacets(): void
    {
        $page = $this->journal([], 2, 2);
        $this->assertSame(5, $page['total']);
        $this->assertSame(3, $page['pages']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(['renewal_scan', 'created'], array_column($page['events'], 'event_type'));

        $beyond = $this->journal([], 9, 2);
        $this->assertSame(3, $beyond['page']);
        $this->assertCount(1, $beyond['events']);

        $facets = $page['facets'];
        $this->assertContains(['entity_type' => 'payer', 'event_type' => 'created', 'count' => 1], $facets['event_types']);
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->manager->id], array_column($facets['users'], 'id'));
        $this->assertTrue($facets['has_system']);
    }

    public function testRejectsInvalidFilters(): void
    {
        foreach ([
            ['date_from' => '2026-02-30'],
            ['date_from' => '2026-03-01', 'date_to' => '2026-02-01'],
            ['entity_type' => 'invoice'],
            ['event_type' => 'DROP TABLE'],
            ['user' => 'kowalski'],
        ] as $filters) {
            try {
                $this->journal($filters);
                $this->fail('Filtr powinien zostać odrzucony: ' . json_encode($filters));
            } catch (ServiceException $e) {
                $this->assertSame(422, $e->httpStatus());
            }
        }
    }
}
