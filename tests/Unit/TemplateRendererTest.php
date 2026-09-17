<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\TemplateRenderer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function certificate(): array
    {
        return [
            'name'             => 'Certyfikat kwalifikowany — Jan Kowalski',
            'certificate_type' => 'QUALIFIED_SIGNATURE',
            'serial_number'    => '5A3F9C21B7E04D18',
            'issuer'           => 'Certum QCA 2017',
            'expiry_date'      => '2026-10-01',
            'company_name'     => 'NovaTech <Sp. z o.o.>',
            'user_first_name'  => 'Ewa',
            'user_last_name'   => 'Pawlak',
            'user_email'       => 'ewa@example.com',
        ];
    }

    public function testValuesUseTemplateLocaleForLabelsAndDates(): void
    {
        $today = new DateTimeImmutable('2026-09-17');
        $recipient = ['first_name' => 'Jan', 'last_name' => 'Kowalski'];

        $pl = TemplateRenderer::values($this->certificate(), $recipient, 'pl', $today);
        $en = TemplateRenderer::values($this->certificate(), $recipient, 'en', $today);

        $this->assertSame('01.10.2026', $pl['data_waznosci']);
        $this->assertSame('1 Oct 2026', $en['data_waznosci']);
        $this->assertSame('14', $pl['dni_do_wygasniecia']);
        $this->assertSame('Certyfikat kwalifikowany', $pl['typ_certyfikatu']);
        $this->assertSame('Qualified certificate', $en['typ_certyfikatu']);
        $this->assertSame('Ewa Pawlak', $pl['opiekun']);
    }

    public function testHtmlBodyEscapesValuesButTextAndSubjectDoNot(): void
    {
        $values = TemplateRenderer::values($this->certificate(), ['first_name' => 'Jan', 'last_name' => 'Kowalski'], 'pl', new DateTimeImmutable('2026-09-17'));

        $rendered = TemplateRenderer::render(
            "Odnowienie {numer_seryjny}\r\nBcc: atak@example.com",
            '<p>Płatnik: {platnik}, pole {nieznane}</p>',
            'Płatnik: {platnik}',
            $values
        );

        $this->assertSame('Odnowienie 5A3F9C21B7E04D18 Bcc: atak@example.com', $rendered['subject']);
        $this->assertStringContainsString('NovaTech &lt;Sp. z o.o.&gt;', $rendered['body_html']);
        $this->assertStringContainsString('{nieznane}', $rendered['body_html']);
        $this->assertSame('Płatnik: NovaTech <Sp. z o.o.>', $rendered['body_text']);
    }

    public function testTextVersionIsDerivedFromHtmlWhenMissing(): void
    {
        $rendered = TemplateRenderer::render('Temat', "<p>Dzień dobry {imie},</p>\n<p>linia<br>druga &amp; ostatnia</p>", null, ['imie' => 'Jan']);

        $this->assertSame("Dzień dobry Jan,\n\nlinia\ndruga & ostatnia", $rendered['body_text']);
    }

    public function testContactPersonIsSplitIntoFirstAndLastName(): void
    {
        $this->assertSame(['first_name' => 'Katarzyna', 'last_name' => 'Zielińska-Nowak'], TemplateRenderer::splitName(' Katarzyna  Zielińska-Nowak '));
        $this->assertSame(['first_name' => 'Recepcja', 'last_name' => ''], TemplateRenderer::splitName('Recepcja'));
    }
}
