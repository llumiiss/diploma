# -*- coding: utf-8 -*-
"""Rozdziały 3–4 części pisemnej (stan aplikacji po Etapie 10); rozdziały 5–9 w thesis_chapters_5_9.py."""

from __future__ import annotations

from pathlib import Path

import thesis_erd
from thesis_helpers import draw_architecture, rbac_matrix, screenshot

ERD_SVG = Path(__file__).resolve().parent / "erd" / "certisub-erd.svg"


def chapter3(pdf) -> None:
    pdf.add_page()
    pdf.heading("3. Założenia projektowe aplikacji", 14)

    pdf.subheading("3.1. Cel i zakres projektu")
    pdf.paragraph(
        "Celem projektu jest firmowy rejestr certyfikatów i subskrypcji CertiSub Assistant, w którym "
        "personel organizacji (a nie użytkownicy indywidualni) wprowadza i utrzymuje dane o certyfikatach, "
        "ich użytkownikach (beneficjentach) oraz firmach płacących za certyfikaty (w opisie pracy — "
        "płatnikach), a system samodzielnie wykrywa zbliżające się terminy odnowienia, zakłada zadania, "
        "wysyła zaproszenia e-mail i rejestruje historię zdarzeń."
    )
    pdf.paragraph(
        "Zakres projektu obejmuje jedną domenę i jedną bazę danych: ewidencję certyfikatów ośmiu rodzajów "
        "(kwalifikowany podpis elektroniczny, kwalifikowana pieczęć elektroniczna, certyfikat SSL, "
        "certyfikat podpisu kodu, domena, licencja SaaS, wsparcie chmurowe oraz kategoria „inne”), "
        "użytkowników certyfikatów i firmy, proces odnowień z listą zadań ToDo, szablony wiadomości "
        "i załączniki, rejestr zaproszeń i przypomnień, raporty perspektyw (użytkownika, płatnika, "
        "administratora), wyszukiwarkę globalną, wymianę danych (import i eksport CSV, XML oraz import "
        "wiadomości EML), panel administracyjny z kontami i rolami stanowisk, a także — dodane w ostatnim "
        "etapie prac — wnioski o certyfikat przesyłane e-mailem, powiadomienia wewnętrzne między kontami "
        "i filtr firm."
    )
    pdf.paragraph(
        "Pierwotna koncepcja projektu obejmowała również osobisty moduł subskrypcji konsumenckich "
        "(streaming, muzyka, aplikacje miesięczne) dostępny każdemu zarejestrowanemu użytkownikowi. W toku "
        "prac przyjęto decyzję projektową D1: moduł prywatny został wydzielony do osobnej aplikacji "
        "(„Menedżer Subskrypcji”) z własną bazą danych, kontami i interfejsem, niepowiązanej z rejestrem "
        "firmowym żadną tabelą ani odnośnikiem. Powodem były odmienne modele danych (brak beneficjenta i "
        "płatnika po stronie prywatnej), odmienny krąg odbiorców (dowolny użytkownik kontra personel z "
        "przydzieloną rolą) oraz chęć zachowania przejrzystej granicy uprawnień: operator rejestru "
        "firmowego nie powinien mieć żadnej technicznej możliwości dotarcia do prywatnych danych "
        "finansowych innego użytkownika. Niniejsza praca opisuje rejestr firmowy; aplikacja prywatna, "
        "zbudowana na tych samych zasadach jakościowych (PDO, CSRF, testy), jest wymieniona w rozdziale "
        "poświęconym kierunkom rozwoju jako powiązany, lecz niezależny projekt."
    )
    pdf.paragraph(
        "W trakcie realizacji dwie decyzje zmieniły pierwotny model danych. Po pierwsze, zamiast kwoty "
        "certyfikatu system przechowuje rabat procentowy (decyzja D10): organizacje negocjują warunki "
        "z wystawcami, a informacja o stopniu upustu jest dla personelu ważniejsza niż cennik, który "
        "zmienia się niezależnie od aplikacji. Po drugie, od Etapu 10 obowiązuje łańcuch zależności "
        "„firma → użytkownik certyfikatu → certyfikat kwalifikowany”: osoba nie istnieje bez firmy, "
        "a certyfikat kwalifikowany nie istnieje bez osoby, której dotyczy. Reguła jest wymuszana "
        "jednocześnie przez bazę danych, usługi i interfejs (podrozdział 4.4)."
    )

    pdf.subheading("3.2. Wymagania funkcjonalne")
    pdf.paragraph(
        "Wymagania wynikają z oficjalnego opisu pracy (Załącznik A) i zostały ponumerowane F1–F19. "
        "Poniższa lista grupuje je tematycznie; ostatnie pozycje to rozszerzenia dodane przez autora w "
        "Etapie 10. System powinien umożliwiać:"
    )
    for item in [
        "logowanie personelu adresem e-mail i hasłem z potwierdzeniem adresu linkiem z wiadomości, "
        "zakładanie kont przez administratora (z wiadomością z linkiem do ustawienia hasła) oraz "
        "konfigurowalną rejestrację publiczną (F15),",
        "prowadzenie ewidencji certyfikatów: numer seryjny, wystawca, okres ważności, wymagany czas "
        "odnowienia, rabat, status płatności; dodawanie, edycję i archiwizację zamiast usuwania (F1, F5),",
        "prowadzenie ewidencji użytkowników certyfikatów (beneficjentów) i firm (płatników) wraz z ich "
        "powiązaniami (F2, F3, F4),",
        "automatyczne skanowanie certyfikatów w marginesie odnowienia i zakładanie zadań ToDo "
        "z priorytetem rosnącym wraz ze zbliżaniem się terminu (F11, F12),",
        "przydział zadań do personelu, zmianę statusu (do zrobienia / w toku / zrobione / porzucone), "
        "odnowienie certyfikatu z poziomu zadania i statystyki realizacji wg statusów (F12, F18),",
        "wysyłkę zaproszeń e-mail na podstawie szablonów z polami podstawianymi automatycznie oraz "
        "załącznikami z biblioteki plików; rejestr zaproszeń z przypomnieniami, ponowną wysyłką i "
        "oznaczaniem odpowiedzi (F13, F14),",
        "raporty perspektyw: karty użytkownika certyfikatu i płatnika z datami odnowienia, ścieżką "
        "realizacji i osią czasu oraz widok administratora (F6, F7, F8, F17),",
        "eksport danych do CSV i XML (listy, karty raportowe, harmonogram, dziennik zdarzeń) oraz import "
        "z CSV, XML i wiadomości e-mail (EML) z podglądem każdego wiersza przed zapisem (F9, F10),",
        "wyszukiwanie certyfikatów, osób i firm wraz z powiązaniami i podaniem powodu dopasowania (F16),",
        "obsługę planu kont z rolami stanowisk i zakresem danych zależnym od roli (F19),",
        "przyjmowanie wniosków o certyfikat przesłanych e-mailem: odczyt danych z wiadomości, "
        "uzupełnienie danych firmy z rejestru po NIP-ie, kolejka robocza operatora i zatwierdzenie "
        "jednym przyciskiem (rozszerzenie),",
        "powiadomienia wewnętrzne między kontami (wiadomości, prośby o uzupełnienie danych, zgłoszenia "
        "błędów, komunikaty systemowe) oraz filtr firm zawężający pracę do wybranych podmiotów "
        "(rozszerzenie),",
        "obsługę wielu języków interfejsu (PL jako domyślny, EN, ES, DE, UK).",
    ]:
        pdf.bullet(item)

    pdf.subheading("3.3. Wymagania niefunkcjonalne")
    pdf.paragraph("System powinien spełniać następujące wymagania niefunkcjonalne (N1–N6 z opisu pracy oraz wymagania własne):")
    for item in [
        "realizacja w PHP + HTML + JavaScript, zgodnie z kompetencjami autora i wynikiem studium "
        "wykonalności (podrozdział 2.5),",
        "baza danych zgodna z MySQL, możliwa do uruchomienia na standardowym hostingu LAMP bez "
        "dodatkowych usług; aplikacja wdrożona w wersji demonstracyjnej na bezpłatnym hostingu,",
        "możliwość instalacji publicznej lub wyłącznie w sieci wewnętrznej (katalogi wewnętrzne "
        "zablokowane w konfiguracji serwera, frontend bez zależności od CDN),",
        "ochrona danych osobowych beneficjentów i płatników: dostęp według roli i zakresu danych, "
        "hasła przechowywane wyłącznie jako skróty, tokeny jednorazowe z terminem ważności, zapis "
        "historii zmian, w tym informacji o eksporcie danych,",
        "odporność na SQL Injection dzięki przygotowanym zapytaniom (PDO), ochrona przed CSRF, XSS "
        "oraz ograniczanie liczby prób logowania i wysyłek wiadomości liczone w bazie,",
        "responsywny, czytelny i intuicyjny interfejs działający w popularnych przeglądarkach "
        "bez konieczności instalacji wtyczek; szkielety treści zamiast pustych ekranów,",
        "wydajna komunikacja między frontendem a backendem przez REST API (JSON) i buforowanie "
        "kosztownych zestawień z kluczem zawierającym rolę i zakres danych,",
        "możliwość dalszej rozbudowy bez zmiany architektury (warstwa usług niezależna od warstwy HTTP),",
        "pokrycie testami jednostkowymi i integracyjnymi kluczowej logiki biznesowej (N6) oraz "
        "analiza statyczna kodu,",
        "niski koszt wdrożenia dzięki technologiom open-source (stos LAMP).",
    ]:
        pdf.bullet(item)

    pdf.subheading("3.4. Role użytkowników i przypadki użycia")
    pdf.paragraph(
        "Opis pracy zakłada hierarchiczny plan kont z podziałem na role. W pierwszej wersji systemu "
        "zaimplementowano trzy role (administrator, menedżer, operator) ułożone w hierarchię. W Etapie 10 "
        "model rozszerzono do siedmiu profili stanowisk w firmie: okazało się, że księgowa powinna widzieć "
        "płatności całej organizacji, a informatyk — wyłącznie certyfikaty techniczne, choć żadna z tych "
        "ról nie jest „wyższa” od operatora. Każda rola ma więc własny zestaw uprawnień do akcji oraz "
        "własny zakres danych (klasa App\\Rbac), a ukrycie przycisku w interfejsie nie zastępuje kontroli "
        "po stronie serwera — każda usługa wywołuje Actor::authorize()."
    )
    pdf.data_table(
        "Role stanowisk, zakres danych i najważniejsze uprawnienia",
        ["Rola", "Zakres danych", "Najważniejsze uprawnienia"],
        [
            ["Administrator (ADMIN)", "cała organizacja",
             "wszystko: konta i role, szablony i załączniki, ustawienia, import, dziennik zdarzeń, wiadomości do wszystkich"],
            ["Szef (DIRECTOR)", "cała organizacja",
             "podgląd ewidencji i archiwum, przydział opiekunów i zadań, statystyki, raporty, eksport; bez edycji"],
            ["Menedżer (MANAGER)", "cała organizacja",
             "ewidencja, archiwizacja, przydziały, skaner, zaproszenia, raporty, eksport, wnioski e-mail"],
            ["Księgowa (ACCOUNTANT)", "cała organizacja",
             "podgląd, zmiana płatności i rabatów, edycja danych firm, raporty, eksport"],
            ["Informatyk (IT)", "certyfikaty techniczne (SSL, podpis kodu, domena, SaaS, chmura, inne)",
             "dodawanie i edycja certyfikatów technicznych, zadania, zaproszenia; bez certyfikatów kwalifikowanych"],
            ["Operator (OPERATOR)", "własne i przydzielone certyfikaty oraz powiązane osoby i firmy (D8)",
             "ewidencja w swoim zakresie, zadania, zaproszenia, sprawdzanie wniosków e-mail"],
            ["Pracownik (EMPLOYEE)", "wyłącznie własne certyfikaty i ich firmy",
             "tylko odczyt, własne zadania, raporty, powiadomienia"],
        ],
        widths=(2.1, 3.0, 5.2),
        size=8.5,
    )
    pdf.paragraph(
        "Zakres operatora wynika z zasady najmniejszych uprawnień (decyzja D8): operator widzi "
        "certyfikaty, których jest opiekunem lub ma przydzielone zadanie, oraz osoby i firmy, które sam "
        "wprowadził albo które są powiązane z jego certyfikatami. Pracownik (EMPLOYEE) może mieć konto "
        "powiązane z własnym rekordem użytkownika certyfikatu i wtedy widzi również certyfikaty, "
        "w których jest beneficjentem. Pełna macierz uprawnień znajduje się w załączniku B."
    )
    pdf.paragraph("Typowe przypadki użycia:")
    for item in [
        "UC1 — Logowanie: użytkownik podaje adres e-mail i hasło; po pięciu nieudanych próbach w ciągu "
        "15 minut logowanie jest blokowane; konto z niepotwierdzonym adresem nie zaloguje się.",
        "UC2 — Rejestracja certyfikatu (operator, menedżer): wybór firmy i użytkownika certyfikatu, "
        "wpisanie numeru seryjnego, wystawcy, dat i rabatu; formularz podświetla błędy przy polach.",
        "UC3 — Obsługa zadania odnowienia (operator): zadanie założone przez skaner trafia na listę ToDo; "
        "operator rozpoczyna pracę, wysyła zaproszenie z szablonu i po odnowieniu zamyka zadanie "
        "z nową datą ważności — stary certyfikat trafia do archiwum.",
        "UC4 — Przegląd firmy (księgowa, szef): karta firmy z harmonogramem wygaśnięć i rabatami; "
        "eksport zestawienia do CSV.",
        "UC5 — Wniosek z e-maila (operator): wiadomość z danymi wnioskodawcy zostaje zamieniona na "
        "formularz z danymi firmy z rejestru VAT; operator sprawdza i zatwierdza wniosek.",
        "UC6 — Administracja (administrator): założenie konta z rolą, przekazanie rekordów odchodzącego "
        "pracownika, zmiana progów procesu odnowień, przegląd dziennika zdarzeń.",
    ]:
        pdf.bullet(item)

    pdf.subheading("3.5. Architektura ogólna systemu")
    pdf.paragraph(
        "Aplikacja jest zrealizowana w architekturze klient–serwer z podziałem na cztery warstwy: "
        "prezentacji (Vue 3 bez kroku budowania, Tailwind CSS), HTTP (jądro API mapujące metodę żądania, "
        "token CSRF i sesję na odpowiedź JSON ze statusem błędu), usług domenowych (klasy w przestrzeni "
        "App\\Service, każda z walidacją, kontrolą uprawnień przez Rbac::can(), zakresem danych i zapisem "
        "zdarzeń do historii) oraz dostępu do danych (MySQL przez PDO z przygotowanymi zapytaniami). "
        "Rysunek poniżej przedstawia warstwy i miejsca integracji z usługami zewnętrznymi."
    )
    draw_architecture(pdf)
    pdf.figure_caption("Architektura warstwowa aplikacji CertiSub Assistant")
    pdf.paragraph(
        "Warstwa usług nie odwołuje się bezpośrednio do sesji HTTP — przyjmuje połączenie PDO i obiekt "
        "aktora (Actor) jako parametry, dzięki czemu ta sama logika jest wykorzystywana zarówno przez "
        "kontrolery API, jak i przez skrypty uruchamiane z linii poleceń (skaner odnowień, odbiór poczty, "
        "import danych), bez duplikowania reguł biznesowych. Taki podział to odmiana wzorca „warstwy "
        "usług” opisanego m.in. przez M. Fowlera [9]."
    )
    pdf.paragraph(
        "Centralnym mechanizmem systemu jest proces odnowień: cykliczne zadanie cron/renewals.php wyszukuje "
        "certyfikaty w marginesie odnowienia i zakłada zadanie ToDo, zadanie trafia na listę personelu "
        "z priorytetem zależnym od liczby dni do wygaśnięcia, obsługa zadania może zakończyć się wysyłką "
        "zaproszenia e-mail (z szablonu i załączników) do beneficjenta lub płatnika, a finalnie — "
        "odnowieniem certyfikatu (zapisaniem nowego certyfikatu powiązanego z poprzednim, który trafia do "
        "archiwum) albo świadomym porzuceniem zadania. Każdy krok zapisuje zdarzenie w tabeli events, na "
        "podstawie której budowane są oś czasu i raporty perspektyw."
    )
    pdf.paragraph(
        "System stosuje dodatkowo: warstwę pamięci podręcznej dla kosztownych zestawień (agregaty KPI "
        "i statystyki zadań) z kluczem zawierającym rolę, konto i filtr firm oraz unieważnianiem przy "
        "każdym zapisie; moduł wymiany danych (App\\Exchange) z jednolitym opisem kolumn i normalizacją "
        "wartości, współdzielonym przez eksport i import; moduł odbioru wniosków (App\\Intake) z "
        "własnym klientem IMAP i klientem rejestru VAT; mechanizm i18n z domyślnym językiem polskim "
        "oraz zestaw testów jednostkowych i integracyjnych (PHPUnit) i statyczną analizę kodu (PHPStan) "
        "uruchamiane przy każdej zmianie."
    )


def chapter4(pdf) -> None:
    pdf.add_page()
    pdf.heading("4. Projekt bazy danych", 14)

    pdf.subheading("4.1. Model pojęciowy danych")
    pdf.paragraph(
        "Model danych obejmuje sześć grup encji. Centralną encją jest certyfikat, powiązany jednocześnie "
        "z kontem opiekuna (personel), użytkownikiem certyfikatu (beneficjentem) i firmą-płatnikiem, a "
        "poprzez pole previous_certificate_id — także z poprzednim certyfikatem w łańcuchu kolejnych "
        "odnowień. Baza liczy 18 tabel (17 tabel domenowych i tabelę schema_migrations)."
    )
    pdf.paragraph("Główne encje i ich rola w systemie:")
    for item in [
        "Konto personelu (users) — pracownik organizacji z jedną z siedmiu ról; opiekun certyfikatów "
        "i wykonawca zadań; konto może wskazywać swoją osobę (beneficiary_id) i przechowuje filtr firm,",
        "Firma / płatnik (payers) — podmiot rozliczający certyfikaty, z unikalnym NIP-em (tax_id),",
        "Użytkownik certyfikatu / beneficjent (beneficiaries) — osoba, której dotyczy certyfikat; "
        "zawsze przypisana do firmy,",
        "Certyfikat (certificates) — pozycja podlegająca odnowieniu: typ, numer seryjny, wystawca, "
        "okres ważności, rabat, status płatności,",
        "Zadanie odnowieniowe (renewal_tasks) — wpis listy ToDo założony przez skaner albo ręcznie,",
        "Szablon wiadomości (email_templates), załącznik (attachments) i zaproszenie (invitations) wraz "
        "z tabelami łączącymi — treści i pliki wielokrotnego użytku oraz rejestr wysyłek,",
        "Wniosek z e-maila (registration_drafts) i powiadomienie (notifications) — kolejka robocza "
        "operatora i komunikacja wewnętrzna,",
        "Zdarzenie (events) i ustawienie (settings) — historia operacji i administracyjne nadpisania "
        "progów procesu odnowień,",
        "Tabele obsługujące logowanie: email_verifications, login_attempts oraz login_otps "
        "(historia logowań kodem sprzed Etapu 9).",
    ]:
        pdf.bullet(item)
    pdf.paragraph(
        "Relacje między głównymi encjami: firma 1–N użytkownik certyfikatu (relacja obowiązkowa), "
        "użytkownik certyfikatu 1–N certyfikat, firma 1–N certyfikat (certyfikat może być opłacany przez "
        "inną firmę niż domyślna firma beneficjenta, np. po zmianie pracodawcy), certyfikat 1–N zadanie "
        "odnowieniowe (co najwyżej jedno zadanie otwarte naraz), zadanie 1–N zaproszenie, szablon N–N "
        "załącznik, a wszystkie encje biznesowe łączy relacja 1–N ze zdarzeniami historii."
    )

    pdf.subheading("4.2. Diagram ERD")
    pdf.paragraph(
        "Rysunek na następnej stronie przedstawia pełny model związków encji (ERD) bazy "
        "assistent_subscriptions: tabele, klucze główne, obce i unikalne oraz kardynalność relacji "
        "opisanych w podrozdziale 4.1. Dla czytelności pominięto klucze obce do tabeli users, które "
        "wskazują wyłącznie autora lub wykonawcę zapisu; pełną listę zawierają plik database/schema.sql "
        "oraz dokument docs/BAZA_DANYCH.md."
    )
    pdf.add_page(orientation="L")
    pdf.figure_caption("Diagram ERD bazy danych assistent_subscriptions (stan po Etapie 10)", y=7)
    thesis_erd.draw_pdf(pdf)
    thesis_erd.write_svg(ERD_SVG)
    pdf.add_page()

    pdf.subheading("4.3. Struktura bazy danych i relacje między encjami")
    pdf.paragraph(
        "Baza działa na silniku InnoDB ze zestawem znaków utf8mb4 (pełne wsparcie Unicode, w tym polskich "
        "znaków diakrytycznych). Schemat jest utrzymywany w dwóch równoważnych postaciach: jako plik "
        "database/schema.sql (świeża instalacja) oraz jako ciąg idempotentnych migracji (aktualizacja "
        "istniejących baz); oba źródła dają identyczny kształt bazy — 190 kolumn, 87 pozycji indeksów, "
        "32 klucze obce i 2 ograniczenia CHECK — co sprawdzono porównaniem information_schema."
    )
    pdf.data_table(
        "Tabele bazy danych i ich przeznaczenie",
        ["Tabela", "Przeznaczenie"],
        [
            ["users", "konta personelu: rola, skrót hasła, potwierdzenie adresu, filtr firm, powiązanie z osobą"],
            ["payers", "firmy (płatnicy): nazwa, NIP, adres, osoba kontaktowa"],
            ["beneficiaries", "użytkownicy certyfikatów: dane osobowe, obowiązkowa firma"],
            ["certificates", "certyfikaty i usługi: typ, numer seryjny, wystawca, daty, rabat, płatność"],
            ["renewal_tasks", "lista ToDo: zadania odnowień ze statusem, priorytetem i przydziałem"],
            ["email_templates", "szablony wiadomości (kod + język)"],
            ["attachments, email_template_attachments, invitation_attachments", "biblioteka plików i jej powiązania z szablonami oraz zaproszeniami"],
            ["invitations", "rejestr zaproszeń i przypomnień z treścią z chwili wysyłki"],
            ["registration_drafts", "wnioski o certyfikat odczytane z e-maili — kolejka robocza operatora"],
            ["notifications", "powiadomienia wewnętrzne między kontami (wątki, prośby, komunikaty systemowe)"],
            ["events", "historia zdarzeń (oś czasu, dziennik administratora), bez kluczy obcych"],
            ["settings", "administracyjne nadpisania progów procesu odnowień"],
            ["email_verifications, login_attempts, login_otps", "tokeny z e-maili, limit prób logowania, historia kodów OTP"],
            ["schema_migrations", "lista zastosowanych migracji"],
        ],
        widths=(3.2, 6.8),
        size=8.5,
    )
    pdf.subheading("users", 11)
    pdf.paragraph(
        "Konta personelu: first_name, last_name, role (ENUM siedmiu ról), email (unikalny), password_hash "
        "(skrót hasła, NULL dla konta bez ustawionego jeszcze hasła), email_verified_at (NULL blokuje "
        "logowanie do czasu potwierdzenia adresu), last_login_at, deactivated_at (wyłączenie konta bez "
        "usuwania historii), beneficiary_id (konto pracownika wskazuje swoją osobę; klucz SET NULL) oraz "
        "company_filter (JSON z filtrem firm wybranym w panelu)."
    )
    pdf.subheading("payers i beneficiaries", 11)
    pdf.paragraph(
        "Firma: nazwa, osoba kontaktowa, NIP (tax_id, unikalny także w archiwum), dane adresowe, "
        "created_by_user_id (kto wprowadził rekord — podstawa zakresu danych operatora), archived_at. "
        "Użytkownik certyfikatu: imię, nazwisko, kontakt, notatki oraz payer_id — kolumna NOT NULL z "
        "kluczem obcym ON DELETE RESTRICT, więc osoba nie istnieje bez firmy, a firmy z osobami nie da "
        "się usunąć."
    )
    pdf.subheading("certificates", 11)
    pdf.paragraph(
        "Najbardziej rozbudowana tabela: certificate_type (ENUM ośmiu rodzajów), serial_number i issuer "
        "z unikalnym kluczem złożonym (issuer, serial_number) — ten sam numer seryjny może wystąpić u "
        "dwóch różnych wystawców, ale nie dwukrotnie u tego samego; valid_from, expiry_date, "
        "renewal_lead_days (indywidualny margines odnowienia), klucze obce do users (opiekun), "
        "beneficiaries, payers oraz previous_certificate_id (łańcuch odnowień, ON DELETE SET NULL), a "
        "także status, payment_status, billing_cycle, last_payment_date, auto_renew i archived_at. "
        "Rabat przechowuje kolumna discount_percent DECIMAL(5,2) z ograniczeniem CHECK od 0 do 100; "
        "interfejs pokazuje go z minusem (np. -5%). Ograniczenie chk_certificates_qualified_user "
        "wymaga użytkownika certyfikatu dla typów kwalifikowanych."
    )
    pdf.subheading("renewal_tasks", 11)
    pdf.paragraph(
        "Zadanie ToDo: certificate_id, assigned_user_id, status (todo / in_progress / done / "
        "abandoned), priority (expired / critical / warning / ok), due_date, resolution_note. Kolumna "
        "wyliczana open_marker (1 dla zadań otwartych, NULL dla zamkniętych) w parze z unikalnym "
        "kluczem (certificate_id, open_marker) wymusza na poziomie bazy regułę „co najwyżej jedno "
        "otwarte zadanie na certyfikat” — MySQL traktuje NULL jako wartość niepowtarzalną, więc "
        "zamknięte zadania nie kolidują ze sobą."
    )
    pdf.subheading("email_templates, attachments, invitations", 11)
    pdf.paragraph(
        "Szablon wiadomości ma unikalną parę (code, locale). Załączniki (attachments) przechowują "
        "nazwę oryginalną, losową nazwę pliku na dysku (storage/attachments), typ MIME, rozmiar i skrót "
        "SHA-256; ich powiązania z szablonami i zaproszeniami to tabele łączące N–N. Zaproszenie zapisuje "
        "treść z chwili wysyłki (niezależnie od późniejszych zmian szablonu), odbiorcę, status "
        "(queued / sent / failed / responded / closed), licznik i termin następnego przypomnienia."
    )
    pdf.subheading("registration_drafts i notifications", 11)
    pdf.paragraph(
        "Wniosek z e-maila przechowuje status (pending / approved / rejected), źródło (upload, imap, "
        "webhook, cli), unikalne message_id i content_hash (ochrona przed podwójnym wczytaniem tej "
        "samej wiadomości), nadawcę, treść oraz cztery dokumenty JSON: extracted (co odczytano), form "
        "(formularz edytowany przez operatora), company_lookup (odpowiedź rejestru VAT) i warnings "
        "(wątpliwości). Kolumny matched_* wskazują rekordy już istniejące, result_* — rekordy "
        "utworzone przy zatwierdzeniu. Powiadomienie to jeden wiersz na odbiorcę z polami thread_id "
        "i parent_id (wątki) oraz related_type / related_id (rekord, którego dotyczy wiadomość)."
    )
    pdf.subheading("events", 11)
    pdf.paragraph(
        "Historia zdarzeń: typ i identyfikator encji, typ zdarzenia, konto wykonujące, kolumny "
        "kontekstu (certyfikat, użytkownik certyfikatu, firma), dane w formacie JSON oraz znacznik "
        "czasu. Tabela celowo nie ma kluczy obcych, dzięki czemu zdarzenie pozostaje czytelne nawet po "
        "archiwizacji lub zmianie powiązanego rekordu."
    )
    pdf.paragraph(
        "Poniższe zrzuty przedstawiają bazę wdrożoną w wersji demonstracyjnej na bezpłatnym hostingu, "
        "oglądaną w phpMyAdmin: listę 18 tabel oraz zawartość tabeli certyfikatów."
    )
    screenshot(pdf, "pma_tables", "Lista tabel bazy w phpMyAdmin (wersja demonstracyjna)", 160)
    screenshot(pdf, "pma_certificates", "Zawartość tabeli certificates w phpMyAdmin (dane demonstracyjne)", 165)

    pdf.subheading("4.4. Integralność i bezpieczeństwo danych")
    pdf.paragraph("Integralność i bezpieczeństwo danych zapewnia kilka mechanizmów działających razem:")
    for item in [
        "wszystkie zapytania SQL wykonywane przez PDO z przygotowanymi zapytaniami, przy wyłączonej "
        "emulacji (PDO::ATTR_EMULATE_PREPARES = false) — parametry nigdy nie są sklejane z treścią "
        "zapytania, co eliminuje SQL Injection [5],",
        "łańcuch „firma → użytkownik → certyfikat kwalifikowany” wymuszony trzema warstwami: baza "
        "(NOT NULL, CHECK, klucze RESTRICT), usługi (błędy przy polach) i interfejs (pola wymagane); "
        "baza jest ostatnią linią obrony — wyjątek SQL zamiast cichego zapisu niespójnych danych,",
        "klucze obce dobrane do semantyki relacji: RESTRICT tam, gdzie usunięcie rekordu nadrzędnego "
        "zepsułoby historię (np. certyfikat → firma), SET NULL tam, gdzie powiązanie jest pomocnicze "
        "(np. zadanie → opiekun po wyłączeniu konta), CASCADE dla danych ściśle zależnych od rekordu "
        "nadrzędnego (np. tokeny konta, załączniki szablonu),",
        "archiwizacja zamiast usuwania (archived_at) dla certyfikatów, użytkowników certyfikatów i firm "
        "— rekord i jego historia zostają w bazie, a zapytania „bieżące” filtrują archived_at IS NULL; "
        "rekord archiwalny jest tylko do odczytu,",
        "unikalne klucze wymuszające reguły biznesowe w bazie, nie tylko w kodzie: adres e-mail konta, "
        "NIP firmy, para wystawca + numer seryjny, co najwyżej jedno otwarte zadanie na certyfikat, "
        "Message-ID i skrót treści wniosku e-mail,",
        "hasła przechowywane wyłącznie jako skrót password_hash(), tokeny weryfikacji adresu i "
        "ustawienia hasła — jako skrót SHA-256 z terminem ważności i znacznikiem zużycia [4],",
        "limit prób logowania i wysyłek wiadomości liczony w bazie (login_attempts, email_verifications) "
        "per adres e-mail i per adres IP, co uniemożliwia obejście przez wyczyszczenie ciasteczek,",
        "migracje schematu jako idempotentne klasy (App\\MigrationRunner, classes/Migrations/) "
        "sprawdzające stan bazy przed każdym krokiem (SchemaInspector); przed migracją zalecany jest "
        "zrzut bazy (mysqldump), bo migracja rabatu usuwa dotychczasową kolumnę z kwotą,",
        "przypisanie danych osobowych do ról: zakres danych liczony z roli (Visibility), nie zapisany w "
        "tabelach; eksport danych zapisuje zdarzenie z autorem i czasem (rozliczalność w rozumieniu "
        "RODO [2]).",
    ]:
        pdf.bullet(item)


def chapters_5_to_8(pdf) -> None:
    from thesis_chapters_5_9 import write_all

    write_all(pdf)
