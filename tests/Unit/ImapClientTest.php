<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Intake\ImapClient;
use App\Intake\ImapException;
use App\Intake\MailboxConnector;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScriptedImapServer;

final class ImapClientTest extends TestCase
{
    private function client(ScriptedImapServer $server): ImapClient
    {
        $client = new ImapClient($server);
        $client->greeting();

        return $client;
    }

    public function testLoginSelectSearchAndFetchWithLiteral(): void
    {
        $server = new ScriptedImapServer();
        $raw = "From: jan@example.com\r\nSubject: Wniosek\r\n\r\nNIP: 6342851974\r\n";
        $server->addMessage(7, $raw);
        $server->addMessage(3, "Subject: Starsza\r\n\r\ntreść", true);
        $server->addMessage(9, "Subject: Nowsza\r\n\r\ntreść");

        $client = $this->client($server);
        $client->loginPassword('rejestracja@example.com', 'sekret');
        $client->select('INBOX');

        $this->assertSame([7, 9], $client->searchUnseen(10));
        $this->assertSame([7], $client->searchUnseen(1));
        $this->assertSame(strlen($raw), $client->messageSize(7));
        $this->assertSame($raw, $client->fetchRaw(7));
        // BODY.PEEK nie zmienia flag.
        $this->assertFalse($server->messages[7]['seen']);

        $client->markSeen(7);
        $this->assertTrue($server->messages[7]['seen']);
        $this->assertSame([9], $client->searchUnseen(10));
    }

    public function testLiteralWithLineBreaksAndBinaryContentIsReadByLength(): void
    {
        $server = new ScriptedImapServer();
        $raw = "Subject: Z literałem\r\n\r\nlinia {5}\r\nA001 OK udawana odpowiedź\r\n* 1 FETCH";
        $server->addMessage(1, $raw);

        $client = $this->client($server);
        $client->loginPassword('u', 'sekret');
        $client->select('INBOX');

        $this->assertSame($raw, $client->fetchRaw(1));
    }

    public function testWrongPasswordFailsWithoutLeakingIt(): void
    {
        $server = new ScriptedImapServer('prawidlowe');
        $client = $this->client($server);

        try {
            $client->loginPassword('u@example.com', 'zle-haslo-123');
            $this->fail('Logowanie z błędnym hasłem powinno się nie udać.');
        } catch (ImapException $e) {
            $this->assertStringContainsString('AUTHENTICATIONFAILED', $e->getMessage());
            $this->assertStringNotContainsString('zle-haslo-123', $e->getMessage());
        }
    }

    public function testControlCharactersCannotInjectCommands(): void
    {
        $server = new ScriptedImapServer('x');
        $client = $this->client($server);

        try {
            $client->loginPassword("u\r\nA999 LOGOUT", 'x');
        } catch (ImapException) {
            // Odpowiedź serwera nie ma tu znaczenia — liczy się to, co zostało wysłane.
        }

        // Jedno polecenie — znaki sterujące wycięto, więc „A999 LOGOUT” został tylko tekstem w cudzysłowie.
        $this->assertSame(['A001 LOGIN "uA999 LOGOUT" "x"'], $server->commands);
    }

    public function testXoauth2UsesTheBearerToken(): void
    {
        $server = new ScriptedImapServer();
        $client = $this->client($server);
        $client->authenticateXOAuth2('rejestracja@example.com', 'good-token');

        $this->assertStringStartsWith('A001 AUTHENTICATE XOAUTH2 ', $server->commands[0]);
        $decoded = (string) base64_decode(substr($server->commands[0], strlen('A001 AUTHENTICATE XOAUTH2 ')), true);
        $this->assertSame("user=rejestracja@example.com\001auth=Bearer good-token\001\001", $decoded);

        $other = new ScriptedImapServer();
        $this->expectException(ImapException::class);
        $this->client($other)->authenticateXOAuth2('rejestracja@example.com', 'expired-token');
    }

    public function testMoveCopiesMarksDeletedAndExpunges(): void
    {
        $server = new ScriptedImapServer();
        $server->addMessage(4, 'Subject: x');
        $client = $this->client($server);
        $client->loginPassword('u', 'sekret');
        $client->select('INBOX');

        $client->moveTo(4, 'Przetworzone/Wnioski');

        $this->assertSame(['Subject: x'], $server->copied['Przetworzone/Wnioski']);
        $this->assertTrue($server->expunged);
        $this->assertArrayNotHasKey(4, $server->messages);
    }

    public function testClosedConnectionIsReportedAndLogoutClosesTransport(): void
    {
        $server = new ScriptedImapServer('sekret', true, 1);
        $client = $this->client($server);
        $client->loginPassword('u', 'sekret');

        try {
            $client->select('INBOX');
            $this->fail('Zerwane połączenie powinno zgłosić błąd.');
        } catch (ImapException $e) {
            $this->assertStringContainsString('zamknął połączenie', $e->getMessage());
        }

        $client->logout();
        $this->assertTrue($server->closed);
    }

    public function testBadGreetingIsRejected(): void
    {
        $this->expectException(ImapException::class);
        (new ImapClient(new ScriptedImapServer('x', false)))->greeting();
    }

    public function testConnectorLogsInWithPasswordAndRequiresCredentials(): void
    {
        $server = new ScriptedImapServer('haslo-aplikacji');
        $client = MailboxConnector::connect(['username' => 'u@example.com', 'password' => 'haslo-aplikacji', 'auth' => 'password'], $server);
        $client->select('INBOX');
        $this->assertStringStartsWith('A001 LOGIN "u@example.com"', $server->commands[0]);

        foreach ([['username' => '', 'password' => 'x'], ['username' => 'u@example.com', 'password' => '']] as $config) {
            $transport = new ScriptedImapServer();
            try {
                MailboxConnector::connect($config, $transport);
                $this->fail('Brak danych logowania powinien zgłosić błąd.');
            } catch (ImapException) {
                $this->assertTrue($transport->closed || $transport->commands === [], 'połączenie nie może zostać otwarte bez danych');
            }
        }

        $failing = new ScriptedImapServer('inne');
        try {
            MailboxConnector::connect(['username' => 'u', 'password' => 'zle'], $failing);
            $this->fail('Błędne hasło.');
        } catch (ImapException) {
            $this->assertTrue($failing->closed, 'po nieudanym logowaniu połączenie jest zamykane');
        }
    }

    public function testConnectorWithoutOauth2ConfigurationFailsClearlyAndClosesTheConnection(): void
    {
        $server = new ScriptedImapServer();

        try {
            MailboxConnector::connect(['username' => 'u@example.com', 'auth' => 'oauth2'], $server, ['user_email' => '', 'client_id' => '', 'refresh_token' => '']);
            $this->fail('Brak konfiguracji OAuth2.');
        } catch (ImapException $e) {
            $this->assertStringContainsString('OAuth2', $e->getMessage());
            $this->assertTrue($server->closed);
        }
    }
}
