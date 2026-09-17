<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\AttachmentService;
use App\Service\CertificateService;
use App\Service\ServiceException;
use App\Service\SettingsService;
use App\Service\TemplateService;
use Tests\Support\IntegrationTestCase;

final class TemplateAttachmentSettingsTest extends IntegrationTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'certisub-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storage);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $content);

        return $path;
    }

    public function testAttachmentsAreCheckedByExtensionAndContentAndProtectedWhileInUse(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new AttachmentService($this->db, $this->storage);

        foreach (['skrypt.php' => 'type', 'obraz.png' => 'content'] as $name => $expected) {
            try {
                $service->store($admin, $this->file('<?php echo 1;'), $name);
                $this->fail("Plik {$name} powinien zostać odrzucony.");
            } catch (ServiceException $e) {
                $this->assertArrayHasKey('file', $e->errors, $expected);
            }
        }

        $source = $this->file("Instrukcja\n");
        $attachment = $service->store($admin, $source, '..\\..\\Instrukcja odnowienia.txt');
        $this->assertSame('Instrukcja odnowienia.txt', $attachment['original_name']);
        $this->assertSame(hash_file('sha256', $source), $attachment['sha256']);
        $this->assertArrayNotHasKey('stored_name', $attachment);

        $templates = new TemplateService($this->db);
        $template = $templates->create($admin, [
            'code' => 'custom_notice', 'locale' => 'pl', 'name' => 'Informacja', 'subject' => 'Temat {numer_seryjny}',
            'body_html' => '<p>Treść</p>', 'attachment_ids' => [$attachment['id']],
        ]);
        $this->assertSame([$attachment['id']], $template['attachment_ids']);

        try {
            $service->delete($admin, $attachment['id']);
            $this->fail('Załącznik używany w szablonie nie może zostać usunięty.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        $templates->update($admin, $template['id'], [
            'code' => 'custom_notice', 'locale' => 'pl', 'name' => 'Informacja', 'subject' => 'Temat',
            'body_html' => '<p>Treść</p>', 'attachment_ids' => [],
        ]);
        $service->delete($admin, $attachment['id']);
        $this->assertSame([], glob($this->storage . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testTemplatesRejectDuplicateCodeAndLocaleAndRenderPreview(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $templates = new TemplateService($this->db);

        try {
            $templates->create($admin, [
                'code' => 'renewal_invitation', 'locale' => 'pl', 'name' => 'Duplikat', 'subject' => 'X', 'body_html' => '<p>X</p>',
            ]);
            $this->fail('Kod i język szablonu muszą być unikalne.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }

        $operator = $this->createActor(Rbac::OPERATOR);
        $this->insertCertificate($operator->id, $this->insertPayer(['company_name' => 'Grupa Wisła S.A.']), ['serial_number' => 'ABC123']);
        $preview = $templates->previewDraft($admin, [
            'subject' => 'Numer {numer_seryjny}', 'body_html' => '<p>{platnik}</p>', 'locale' => 'pl',
        ], new CertificateService($this->db));

        $this->assertSame('Numer ABC123', $preview['subject']);
        $this->assertSame('<p>Grupa Wisła S.A.</p>', $preview['body_html']);

        $this->expectException(ServiceException::class);
        $templates->list($this->createActor(Rbac::MANAGER));
    }

    public function testSettingsAreValidatedAndLogged(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new SettingsService($this->db);
        $valid = ['renewal.warning_days' => 45, 'renewal.critical_days' => 10, 'reminders.interval_days' => 3, 'reminders.max_count' => 4];

        try {
            $service->update($admin, ['renewal.critical_days' => 60] + $valid);
            $this->fail('Próg krytyczny nie może przekraczać progu ostrzeżenia.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('renewal.critical_days', $e->errors);
        }

        $this->assertSame($valid, $service->update($admin, $valid));
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'settings_changed'")->fetchColumn());

        $this->expectException(ServiceException::class);
        $service->update($this->createActor(Rbac::MANAGER), $valid);
    }
}
