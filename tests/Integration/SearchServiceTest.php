<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\SearchService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class SearchServiceTest extends IntegrationTestCase
{
    private Actor $owner;
    private Actor $colleague;
    private Actor $manager;
    private int $payerId;
    private int $personId;
    private int $certificateId;
    private int $colleagueCertificateId;
    private int $archivedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createActor(Rbac::OPERATOR);
        $this->colleague = $this->createActor(Rbac::OPERATOR);
        $this->manager = $this->createActor(Rbac::MANAGER);

        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974'], $this->owner->id);
        $this->personId = $this->insertBeneficiary($this->payerId, [
            'first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan.kowalski@novatech.example.com',
        ], $this->owner->id);
        $this->db->exec("UPDATE beneficiaries SET phone = '+48 600 100 200' WHERE id = {$this->personId}");

        $this->certificateId = $this->insertCertificate($this->owner->id, $this->payerId, [
            'name'           => 'Podpis kwalifikowany',
            'serial_number'  => '5A3F9C21B7E04D18',
            'issuer'         => 'Certum',
            'beneficiary_id' => $this->personId,
        ]);

        $otherPayer = $this->insertPayer(['company_name' => 'Grupa Wisła S.A.'], $this->colleague->id);
        $this->colleagueCertificateId = $this->insertCertificate($this->colleague->id, $otherPayer, [
            'name'          => 'SSL novatech-partner.pl',
            'serial_number' => 'FFEE0011',
        ]);
        $this->archivedId = $this->insertCertificate($this->owner->id, $this->payerId, [
            'name'           => 'Podpis kwalifikowany 2023',
            'beneficiary_id' => $this->personId,
            'archived_at'    => '2025-01-10 10:00:00',
        ]);
    }

    private function search(Actor $actor, string $query, int $limit = 10): array
    {
        return (new SearchService($this->db))->search($actor, $query, $limit);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private static function byId(array $result, string $group): array
    {
        return array_column($result['groups'][$group]['items'], null, 'id');
    }

    public function testSerialNumberFindsCertificateWithItsRelations(): void
    {
        $result = $this->search($this->manager, '5a3f9c21');

        $this->assertFalse($result['too_short']);
        $this->assertSame(1, $result['groups']['certificates']['total']);
        $certificate = $result['groups']['certificates']['items'][0];
        $this->assertSame($this->certificateId, $certificate['id']);
        $this->assertSame(['serial_number'], $certificate['matched']);
        $this->assertSame(['id' => $this->personId, 'name' => 'Jan Kowalski'], $certificate['beneficiary']);
        $this->assertSame(['id' => $this->payerId, 'name' => 'NovaTech Sp. z o.o.'], $certificate['payer']);
        $this->assertSame($this->owner->fullName(), $certificate['owner_name']);
        // Osobę i płatnika znajduje przez ich certyfikat.
        $this->assertSame(['certificate'], self::byId($result, 'beneficiaries')[$this->personId]['matched']);
        $this->assertSame(['certificate'], self::byId($result, 'payers')[$this->payerId]['matched']);
    }

    public function testPayerNameFindsItsPeopleAndCertificatesThroughRelations(): void
    {
        $result = $this->search($this->manager, 'NovaTech');

        $payers = self::byId($result, 'payers');
        $this->assertSame(['name'], $payers[$this->payerId]['matched']);
        $this->assertSame(1, $payers[$this->payerId]['beneficiary_count']);
        $this->assertSame(1, $payers[$this->payerId]['certificate_count']);

        $people = self::byId($result, 'beneficiaries');
        $this->assertSame(['email', 'payer'], $people[$this->personId]['matched']);

        // Dopasowanie po nazwie certyfikatu jest wyżej niż dopasowanie przez płatnika.
        $certificates = $result['groups']['certificates']['items'];
        $this->assertSame($this->colleagueCertificateId, $certificates[0]['id']);
        $this->assertSame(['name'], $certificates[0]['matched']);
        $matchedByPayer = array_column($certificates, 'matched', 'id');
        $this->assertSame(['payer'], $matchedByPayer[$this->certificateId]);
        // Certyfikat z archiwum jest na końcu listy i oznaczony datą archiwizacji.
        $this->assertSame($this->archivedId, $certificates[count($certificates) - 1]['id']);
        $this->assertNotNull($certificates[count($certificates) - 1]['archived_at']);
        $this->assertNull($certificates[count($certificates) - 1]['priority']);
    }

    public function testTaxIdAndPhoneMatchWithoutSeparators(): void
    {
        $byTaxId = $this->search($this->manager, '634-285-19');
        $this->assertSame(['tax_id'], self::byId($byTaxId, 'payers')[$this->payerId]['matched']);
        $this->assertArrayHasKey($this->certificateId, self::byId($byTaxId, 'certificates'));

        $byPhone = $this->search($this->manager, '600100200');
        $this->assertSame(['phone'], self::byId($byPhone, 'beneficiaries')[$this->personId]['matched']);
    }

    public function testOwnerNameFindsCertificatesTheyLookAfter(): void
    {
        $result = $this->search($this->manager, $this->colleague->lastName);

        $this->assertSame([$this->colleagueCertificateId], array_column($result['groups']['certificates']['items'], 'id'));
        $this->assertSame(['owner'], $result['groups']['certificates']['items'][0]['matched']);
    }

    public function testOperatorSearchesOnlyOwnScopeWithoutArchive(): void
    {
        $result = $this->search($this->owner, 'Podpis');
        $this->assertSame([$this->certificateId], array_column($result['groups']['certificates']['items'], 'id'));

        $colleagues = $this->search($this->owner, 'novatech-partner');
        $this->assertSame(0, $colleagues['groups']['certificates']['total']);
        $this->assertSame(0, $this->search($this->owner, 'Wisła')['groups']['payers']['total']);

        $manager = $this->search($this->manager, 'Podpis');
        $this->assertSame(2, $manager['groups']['certificates']['total']);
    }

    public function testLimitKeepsTotalCount(): void
    {
        foreach (range(1, 4) as $number) {
            $this->insertCertificate($this->owner->id, $this->payerId, ['name' => 'Domena novatech-' . $number . '.pl']);
        }

        $result = $this->search($this->manager, 'novatech', 2);

        $this->assertCount(2, $result['groups']['certificates']['items']);
        $this->assertSame(7, $result['groups']['certificates']['total']);
    }

    public function testShortQueriesAndWildcardsDoNotMatchEverything(): void
    {
        $short = $this->search($this->manager, ' a ');
        $this->assertTrue($short['too_short']);
        $this->assertSame(0, $short['groups']['certificates']['total']);

        $wildcards = $this->search($this->manager, '%_');
        $this->assertFalse($wildcards['too_short']);
        $this->assertSame(0, $wildcards['groups']['certificates']['total']);
        $this->assertSame(0, $wildcards['groups']['beneficiaries']['total']);
        $this->assertSame(0, $wildcards['groups']['payers']['total']);
    }

    public function testSearchRequiresPermission(): void
    {
        $this->expectException(ServiceException::class);
        $this->search(new Actor(999, 'GUEST'), 'NovaTech');
    }
}
