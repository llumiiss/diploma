<?php

declare(strict_types=1);

namespace App;

/**
 * Wiadomości procesu uwierzytelniania. Interfejs pozwala podstawić w testach atrapę,
 * a w aplikacji korzysta z EmailService (PHPMailer + wybrany sterownik, w tym OAuth2).
 */
interface AuthMailer
{
    /** Link potwierdzający adres e-mail po rejestracji. */
    public function sendEmailVerification(string $toEmail, string $toName, string $link): bool;

    /** Link do ustawienia hasła — konto założone przez administratora lub „nie pamiętam hasła”. */
    public function sendPasswordSetLink(string $toEmail, string $toName, string $link): bool;
}
