<?php

declare(strict_types=1);

/**
 * Ustawienia uwierzytelniania.
 *
 * self_registration:
 *   true  — każdy może założyć konto na stronie „Rejestracja”; konto jest nieaktywne,
 *           dopóki adres e-mail nie zostanie potwierdzony linkiem z wiadomości.
 *   false — rejestracja publiczna wyłączona (decyzja D3 z planu pracy): konta zakłada
 *           administrator w panelu „Konta”, a osoba dostaje wiadomość z linkiem do ustawienia hasła.
 *
 * default_role — rola nadawana kontom z rejestracji publicznej (ADMIN | MANAGER | OPERATOR).
 * Uprawnienia podnosi administrator; nowe konto zawsze dostaje najniższą rolę.
 */
return [
    'self_registration' => true,
    'default_role'      => 'OPERATOR',
];
