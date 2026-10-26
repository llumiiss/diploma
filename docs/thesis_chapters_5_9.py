# -*- coding: utf-8 -*-
"""Rozdziały 5–9, bibliografia, spisy i załączniki części pisemnej (stan po Etapie 10)."""

from __future__ import annotations

import re

from thesis_helpers import REPO, code_block, rbac_matrix, screenshot

BIBLIOGRAPHY = [
    "Rozporządzenie Parlamentu Europejskiego i Rady (UE) nr 910/2014 z dnia 23 lipca 2014 r. w sprawie "
    "identyfikacji elektronicznej i usług zaufania w odniesieniu do transakcji elektronicznych na rynku "
    "wewnętrznym oraz uchylające dyrektywę 1999/93/WE (eIDAS), Dz. Urz. UE L 257 z 28.8.2014.",
    "Rozporządzenie Parlamentu Europejskiego i Rady (UE) 2016/679 z dnia 27 kwietnia 2016 r. w sprawie "
    "ochrony osób fizycznych w związku z przetwarzaniem danych osobowych (RODO), Dz. Urz. UE L 119 z 4.5.2016.",
    "Ustawa z dnia 5 września 2016 r. o usługach zaufania oraz identyfikacji elektronicznej (Dz. U. z 2016 r. poz. 1579).",
    "Grassi P. A., Garcia M. E., Fenton J. L., Digital Identity Guidelines: Authentication and Lifecycle "
    "Management, NIST Special Publication 800-63B, National Institute of Standards and Technology, 2017.",
    "OWASP Top 10:2021 — The Ten Most Critical Web Application Security Risks, OWASP Foundation, 2021.",
    "OWASP Application Security Verification Standard 4.0, OWASP Foundation, 2019.",
    "Nielsen J., Usability Engineering, Academic Press, Boston 1993.",
    "Brooke J., SUS: A “quick and dirty” usability scale, [w:] Jordan P. W. i in. (red.), Usability Evaluation "
    "in Industry, Taylor & Francis, London 1996, s. 189–194.",
    "Fowler M., Patterns of Enterprise Application Architecture, Addison-Wesley, Boston 2002.",
    "Sommerville I., Software Engineering, wyd. 10, Pearson, Harlow 2015.",
    "PHP Manual, The PHP Group, https://www.php.net/manual (dostęp: październik 2026).",
    "MySQL 8.4 Reference Manual, Oracle Corporation, https://dev.mysql.com/doc/refman/8.4/en/ (dostęp: październik 2026).",
    "Vue.js — The Progressive JavaScript Framework, dokumentacja, https://vuejs.org (dostęp: październik 2026).",
    "Tailwind CSS — dokumentacja, https://tailwindcss.com/docs (dostęp: październik 2026).",
    "PHPMailer — A full-featured email creation and transfer class for PHP, https://github.com/PHPMailer/PHPMailer (dostęp: październik 2026).",
    "PHPUnit Manual, https://docs.phpunit.de (dostęp: październik 2026).",
    "PHPStan — PHP Static Analysis Tool, dokumentacja, https://phpstan.org (dostęp: październik 2026).",
    "Biała lista podatników VAT — interfejs API, Ministerstwo Finansów, https://www.gov.pl/web/kas/api-wykazu-podatnikow-vat (dostęp: październik 2026).",
    "Crispin M., RFC 3501: Internet Message Access Protocol — Version 4rev1, IETF, 2003.",
    "Resnick P., RFC 5322: Internet Message Format, IETF, 2008.",
    "Hardt D., RFC 6749: The OAuth 2.0 Authorization Framework, IETF, 2012.",
    "Spring Boot Reference Documentation, VMware / Broadcom, https://docs.spring.io/spring-boot (dostęp: październik 2026).",
    "Nielsen J., 10 Usability Heuristics for User Interface Design, Nielsen Norman Group, https://www.nngroup.com/articles/ten-usability-heuristics/ (dostęp: październik 2026).",
    "VIES — VAT Information Exchange System, interfejs REST, Komisja Europejska, https://ec.europa.eu/taxation_customs/vies/ (dostęp: październik 2026).",
    "Krajowy Rejestr Sądowy — interfejs API, Ministerstwo Sprawiedliwości, https://api-krs.ms.gov.pl (dostęp: październik 2026).",
]

UNIT_TESTS = [
    ["ApiKernelTest", "6", "kolejność kontroli w jądrze API: CSRF przed uwierzytelnieniem, 401, odczyt bez tokenu, zła metoda i JSON, mapowanie błędów usług na kody HTTP, brak szczegółów przy błędzie 500"],
    ["AuthManagerTest", "31", "rejestracja, potwierdzenie adresu, jednorazowość i wygasanie tokenów, blokada po pięciu próbach, jednakowa odpowiedź dla błędnego hasła i nieznanego adresu, reset hasła, rehash, lista dozwolonych przekierowań, hasła tylko jako skróty"],
    ["PasswordPolicyTest", "8", "polityka haseł wg NIST SP 800-63B (minimalna długość, lista haseł zakazanych)"],
    ["OAuth2TokenProviderTest", "4", "wymiana refresh tokenu na token dostępu i jego pamięć podręczna"],
    ["MailConfigTest, MailerRateLimitTest", "7", "konfiguracja sterowników poczty, ponowienie wysyłki po odrzuceniu przez limit dostawcy"],
    ["RbacTest", "12", "macierz uprawnień siedmiu ról; nieznane uprawnienie lub rola oznacza brak dostępu"],
    ["ValidatorTest, CertificateHelperTest", "12", "daty sprawdzane kalendarzem, NIP z sumą kontrolną, rabat procentowy, priorytety wg progów"],
    ["TemplateRendererTest, TranslatorTest", "10", "podstawianie pól szablonu z escapowaniem, wersje językowe z domyślnym polskim"],
    ["TranslationFilesTest", "6", "komplet kluczy w pięciu plikach językowych (polski, angielski, hiszpański, niemiecki, ukraiński) i brak pozostawionych tekstów angielskich"],
    ["ExchangeFormatsTest, EmlParserTest", "15", "CSV (BOM, separator, ochrona przed formułami), XML, własny parser MIME wiadomości EML"],
    ["ImapClientTest, WhiteListRegistryTest", "18", "klient IMAP na skryptowanym serwerze, odpowiedzi i błędy rejestru Białej Listy (w tym limit zapytań)"],
    ["ViesRegistryTest, CompositeCompanyRegistryTest", "17", "numery VAT z Unii w systemie VIES (także państwa nieujawniające danych), kierowanie numeru do właściwego rejestru i odporność na awarię jednego źródła"],
    ["KrsRegistryTest, CachedCompanyRegistryTest, LookupThrottleTest", "18", "uzupełnienie danych z Krajowego Rejestru Sądowego, pamięć podręczna odpowiedzi rejestru (bez zapamiętywania awarii) i limit zapytań na konto"],
    ["RegistrationExtractorTest", "19", "odczyt wniosku z formularza, tabeli HTML i zwykłego zdania, polskie i angielskie etykiety, NIP z sumą kontrolną, odporność na wrogi HTML i przekroczone limity rozmiaru"],
    ["CacheTest, ReportScheduleTest", "8", "pamięć podręczna agregatów i grupowanie harmonogramu wygaśnięć"],
    ["TarArchiveTest, CodeDeployerTest", "18", "własny czytnik archiwum tar.gz (nagłówki GNU i pax, uszkodzone dane) oraz reguły wdrożenia: pliki chronione i pomijane, odrzucenie ścieżek wychodzących poza katalog aplikacji"],
]

INTEGRATION_TESTS = [
    ["EndToEndFlowTest", "2", "cały cykl odnowienia przez warstwę API (ewidencja → skaner → zaproszenie → odnowienie → karta → eksport → import → dziennik) oraz odrzucenie złej metody, braku CSRF i niedozwolonej roli"],
    ["RenewalScannerTest, TaskServiceTest", "9", "zadania tylko dla certyfikatów w marginesie, eskalacja priorytetu, brak duplikatów, statusy i odnowienie certyfikatu"],
    ["InvitationServiceTest, TemplateAttachmentSettingsTest", "8", "wysyłka z szablonu i załącznikami, rejestr, przypomnienia wg ustawień, biblioteka plików"],
    ["CertificateServiceTest, BeneficiaryServiceTest, PayerServiceTest", "27", "walidacja pól, unikalność, archiwizacja i przywracanie z zachowaniem powiązań, łańcuch odnowień oraz pobranie danych firmy z rejestru po NIP-ie wraz z limitem zapytań"],
    ["CompanyIntegrityTest", "7", "łańcuch firma → osoba → certyfikat kwalifikowany wymuszany przez usługi i bazę; migracja naprawiająca stare dane"],
    ["RoleScopeTest, AccountRolesTest, AdminFullAccessTest", "19", "zakres danych i uprawnienia każdej z siedmiu ról, osobne liczniki pulpitu dla ról"],
    ["CompanyFilterTest", "11", "filtr firm: wszystkie / jedna / lista, wpływ na listy i pulpit, pominięcie przy eksporcie"],
    ["AccountServiceTest", "5", "zakładanie i wyłączanie kont, ochrona ostatniego administratora, przekazanie rekordów"],
    ["RegistrationIntakeTest, RegistrationServiceTest, RegistrationApiTest", "38", "wnioski z e-maila: firma z rejestru, dopasowanie osób, duplikaty, kanały odbioru, zatwierdzenie w jednej transakcji z wycofaniem"],
    ["NotificationServiceTest", "12", "wiadomości, wątki, prośby załatwione, komunikaty systemowe, zakres odbiorców"],
    ["ReportServiceTest, SearchServiceTest, EventJournalTest", "18", "karty raportowe, wyszukiwarka z powodem dopasowania, oś czasu i dziennik administratora"],
    ["ExportServiceTest, ImportServiceTest, EmlImportServiceTest", "21", "eksport w zakresie danych konta, import z podglądem wycofywanym transakcją i uzupełnianiem danych firmy z rejestru, dopasowanie wiadomości EML"],
    ["MigrationRunnerTest, DashboardCacheTest", "8", "idempotentne migracje, zgodność schematu, pamięć podręczna z kluczem roli"],
]

SCENARIOS = [
    ["S1", "Pełny cykl odnowienia: certyfikat → skaner → zadanie → zaproszenie → odnowienie → raport", "wszystkie kroki zapisują zdarzenia; stary certyfikat w archiwum, nowy powiązany łańcuchem", "EndToEndFlowTest"],
    ["S2", "Skaner uruchomiony dwa razy tego samego dnia", "brak duplikatów zadań; priorytet rośnie wraz ze zbliżaniem się daty", "RenewalScannerTest"],
    ["S3", "Operator, informatyk i pracownik przeglądają listy", "każdy widzi wyłącznie rekordy ze swojego zakresu; osobne liczniki pulpitu", "RoleScopeTest"],
    ["S4", "Zapis osoby bez firmy i certyfikatu kwalifikowanego bez osoby", "odrzucone przez usługę (błąd przy polu) i przez bazę (wyjątek)", "CompanyIntegrityTest"],
    ["S5", "Rejestracja, potwierdzenie adresu, logowanie, blokada po błędnych hasłach", "konto nieaktywne do czasu potwierdzenia; blokada po 5 próbach; ta sama odpowiedź dla nieznanego adresu", "AuthManagerTest"],
    ["S6", "Wiadomość z wnioskiem trafia do aplikacji dwukrotnie", "powstaje jeden wniosek; firma uzupełniona z rejestru po NIP-ie", "RegistrationIntakeTest"],
    ["S7", "Zatwierdzenie wniosku z błędem w danych certyfikatu", "cała transakcja wycofana, błąd przy właściwym polu", "RegistrationServiceTest"],
    ["S8", "Podgląd importu pliku CSV z błędnymi wierszami", "podgląd pokazuje wynik zapisu, a w bazie nic nie przybywa", "ImportServiceTest"],
    ["S9", "Eksport listy przez konto o ograniczonym zakresie", "plik zawiera tylko rekordy w zakresie konta; w dzienniku zdarzenie eksportu", "ExportServiceTest"],
    ["S10", "Migracje uruchomione na świeżym schemacie i na bazie ze starszego etapu", "brak zaległych migracji; kształt bazy identyczny", "MigrationRunnerTest"],
]

OWASP = [
    ["A01 Broken Access Control", "Rbac + Visibility w każdej usłudze, zakres danych liczony z roli; ukrycie przycisków nie jest kontrolą", "RbacTest, RoleScopeTest, EndToEndFlowTest (403), sprawdzenie HTTP"],
    ["A02 Cryptographic Failures", "password_hash(), tokeny 256-bitowe zapisywane jako SHA-256, cookie HttpOnly i SameSite=Lax (Secure przy HTTPS)", "AuthManagerTest (hasła tylko jako skróty, rehash)"],
    ["A03 Injection", "PDO bez emulacji, escapowanie treści szablonów, flagi JSON_HEX_* w danych startowych, apostrof przed formułami w CSV", "ValidatorTest, TemplateRendererTest, ExchangeFormatsTest"],
    ["A04 Insecure Design", "limity prób i wysyłek liczone w bazie, jednakowe komunikaty, archiwizacja zamiast usuwania, zatwierdzenie wniosku w transakcji", "AuthManagerTest, RegistrationServiceTest"],
    ["A05 Security Misconfiguration", ".htaccess blokuje katalogi wewnętrzne, nagłówki Cache-Control, brak zależności od CDN", "kontrola HTTP: 403 dla classes/, config/, storage/, .git/"],
    ["A07 Identification and Authentication Failures", "polityka haseł NIST, blokada po 5 błędach, potwierdzanie adresu, jednorazowe linki z terminem ważności", "AuthManagerTest, PasswordPolicyTest"],
    ["A08 Software and Data Integrity Failures", "token CSRF (hash_equals) przy każdym zapisie, kontrola zawartości przesyłanych plików", "ApiKernelTest, TemplateAttachmentSettingsTest"],
    ["A09 Security Logging and Monitoring Failures", "każdy zapis biznesowy w tabeli events, dziennik administratora, logi crona i poczty", "EventJournalTest"],
    ["A10 Server-Side Request Forgery", "klient rejestru VAT buduje adres wyłącznie ze stałego hosta i dziesięciu cyfr NIP", "WhiteListRegistryTest"],
]

HEURISTICS = [
    ["Widoczność stanu systemu", "szkielety treści w czasie ładowania, powiadomienia o wyniku operacji, licznik zadań i nieprzeczytanych wiadomości w menu", "spełniona"],
    ["Zgodność z rzeczywistością", "polskie nazwy z dziedziny (firma, użytkownik certyfikatu, odnowienie), daty i kwoty w formacie polskim", "spełniona"],
    ["Kontrola i swoboda użytkownika", "cofnięcie zadania do „do zrobienia”, przywracanie z archiwum, podgląd importu bez zapisu", "spełniona"],
    ["Spójność i standardy", "wspólne komponenty (tabele, okna, formularze, plakietki statusów), jednolite kolory priorytetów", "spełniona"],
    ["Zapobieganie błędom", "walidacja pól po stronie serwera, blokada archiwizacji rekordów z powiązaniami, zatwierdzanie wniosków w transakcji", "spełniona"],
    ["Rozpoznawanie zamiast przypominania", "wyszukiwarka globalna z powodem dopasowania, filtry list, podpowiadanie firmy po wyborze osoby", "spełniona"],
    ["Elastyczność i efektywność", "skrót Ctrl+K, filtr firm, eksport z każdej listy; brak skrótów dla pozostałych akcji", "częściowo"],
    ["Estetyczny i minimalistyczny projekt", "karty KPI i tabele bez zbędnych elementów; gęsty ekran szczegółów certyfikatu", "częściowo"],
    ["Pomoc w rozpoznawaniu i naprawie błędów", "komunikaty przy polach formularza w języku użytkownika, wskazanie istniejącego NIP-u", "spełniona"],
    ["Pomoc i dokumentacja", "instrukcja obsługi jest poza aplikacją (plik w repozytorium), brak pomocy kontekstowej w interfejsie", "częściowo"],
]

SUS_ITEMS = [
    "Myślę, że chciałbym często korzystać z tego systemu.",
    "Uważam, że system jest niepotrzebnie skomplikowany.",
    "Uważam, że system jest łatwy w użyciu.",
    "Myślę, że potrzebowałbym pomocy osoby technicznej, aby móc korzystać z tego systemu.",
    "Uważam, że różne funkcje systemu są dobrze ze sobą zintegrowane.",
    "Uważam, że w systemie jest zbyt wiele niespójności.",
    "Wyobrażam sobie, że większość osób szybko nauczy się korzystać z systemu.",
    "Uważam, że system jest bardzo niewygodny w użyciu.",
    "Czułem się bardzo pewnie, korzystając z systemu.",
    "Musiałem nauczyć się wielu rzeczy, zanim mogłem zacząć korzystać z systemu.",
]


def write_all(pdf) -> None:
    chapter5(pdf)
    chapter6(pdf)
    chapter7(pdf)
    chapter8(pdf)
    chapter9(pdf)
    back_matter(pdf)


# --------------------------------------------------------------------------------------------- 5
def chapter5(pdf) -> None:
    pdf.add_page()
    pdf.heading("5. Projekt i implementacja aplikacji webowej", 14)

    pdf.subheading("5.1. Wykorzystane technologie i narzędzia")
    pdf.paragraph(
        "Zestaw technologii wynika ze studium wykonalności (podrozdział 2.5) i z wymagania, by aplikacja "
        "działała na standardowym hostingu LAMP. Poniższa tabela zawiera wersje używane w projekcie "
        "oraz ich rolę."
    )
    pdf.data_table(
        "Technologie i narzędzia wykorzystane w projekcie",
        ["Warstwa", "Technologia (wersja)", "Zastosowanie"],
        [
            ["Backend", "PHP 8.3 (wymagane ≥ 8.1), PDO", "logika biznesowa, API JSON, skrypty CLI; strict_types, klasy final, przestrzeń nazw App"],
            ["Baza danych", "MySQL 8.4 (InnoDB, utf8mb4)", "ewidencja, historia zdarzeń, kolejki; CHECK, kolumny wyliczane, JSON"],
            ["Frontend", "Vue 3.5 (bez kroku budowania), Tailwind CSS 3.4", "reaktywny panel; arkusz stylów budowany lokalnie i zapisany w repozytorium"],
            ["Poczta", "PHPMailer 6.9; SMTP + OAuth2 (XOAUTH2), Mailtrap, własny klient IMAP", "wiadomości z linkami, zaproszenia, przypomnienia, odbiór wniosków"],
            ["Integracje", "API Białej Listy podatników VAT (Ministerstwo Finansów)", "uzupełnianie danych firmy po NIP-ie"],
            ["Testy i jakość", "PHPUnit 11, PHPStan 2 (poziom 5), PHP-CS-Fixer 3", "testy jednostkowe i integracyjne, analiza statyczna, styl PSR-12"],
            ["Środowisko", "Windows, Laragon (Apache, PHP, MySQL); Composer; npm (tylko do stylów); Git, GitHub", "praca lokalna, kontrola wersji"],
            ["Wdrożenie demonstracyjne", "bezpłatny hosting InfinityFree (LAMP), phpMyAdmin", "wersja do oglądania przez promotora, bez dostępu SSH"],
        ],
        widths=(1.7, 3.6, 4.7),
        size=8.5,
    )
    pdf.paragraph(
        "Kod aplikacji liczy około 18,7 tys. wierszy PHP w 108 plikach klas, 8,2 tys. wierszy JavaScript "
        "w 19 plikach interfejsu oraz 8 tys. wierszy testów w 52 plikach; plik tłumaczeń polskich zawiera "
        "ponad 1200 kluczy. Zależności Composera ograniczono do jednej biblioteki produkcyjnej (PHPMailer); "
        "pozostałe elementy — klient IMAP, parser wiadomości MIME, klient OAuth2, klient rejestru VAT — "
        "napisano samodzielnie, aby aplikacja działała na hostingu bez rozszerzeń takich jak mailparse "
        "czy imap."
    )

    pdf.subheading("5.2. Implementacja warstwy backendowej (PHP)")
    pdf.paragraph(
        "Punkt wejścia każdego żądania ładuje plik bootstrap.php, który ustawia strefę czasową "
        "Europe/Warsaw (PHP i MySQL muszą liczyć daty w tej samej strefie — w przeciwnym razie dni do "
        "wygaśnięcia liczone w PHP rozjeżdżały się o jeden dzień z licznikami opartymi na CURDATE()), "
        "rejestruje autoloader klas z przestrzeni App, uruchamia sesję i tłumaczenia. Połączenie z bazą "
        "tworzy klasa Database z trybem wyjątków, domyślnym pobieraniem asocjacyjnym i wyłączoną "
        "emulacją zapytań przygotowanych."
    )
    pdf.paragraph(
        "Logika biznesowa mieszka w usługach (classes/Service). Usługa przyjmuje połączenie PDO i "
        "obiekt Actor opisujący zalogowane konto (id, rola, powiązana osoba, filtr firm), nigdy nie "
        "czyta sesji. Każda metoda zaczyna się od Actor::authorize('uprawnienie'), co wywołuje "
        "Rbac::can() (listing 1); następnie walidator sprawdza dane, a zakres rekordów ogranicza klasa "
        "Visibility, która zamienia rolę w warunek SQL z unikalnie nazwanymi parametrami (przy "
        "wyłączonej emulacji PDO ta sama nazwa parametru nie może wystąpić w zapytaniu dwa razy). "
        "Zapisy wielotabelowe wykonuje Transaction::run, a każda zmiana biznesowa jest rejestrowana "
        "przez EventLogger z identyfikatorami kontekstu (certyfikat, osoba, firma), z których powstają "
        "osie czasu."
    )
    code_block(
        pdf,
        "Rbac::can() — nieznana rola albo uprawnienie oznacza brak dostępu",
        "public static function can(?string $role, string $permission): bool\n"
        "{\n"
        "    $profile = self::PROFILES[self::normalize($role)] ?? null;\n"
        "    if ($profile === null || !in_array($permission, self::ALL, true)) {\n"
        "        return false;\n"
        "    }\n"
        "\n"
        "    return $profile['can'] === null || in_array($permission, $profile['can'], true);\n"
        "}",
    )
    pdf.paragraph(
        "Warstwa HTTP jest cienka: każdy plik w katalogu api/ to jedna linia uruchamiająca kontroler "
        "przez ApiKernel::run(). Jądro (listing 2) wymusza stałą kolejność kontroli — metoda żądania, "
        "token CSRF dla zapisów, sesja i aktywne konto, poprawność JSON — a następnie mapuje wyjątki "
        "usług na kody HTTP (400, 403, 404, 409, 422) wraz z błędami przypisanymi do pól formularza. "
        "Nieoczekiwany wyjątek kończy się kodem 500 bez ujawniania szczegółów, a szczegóły trafiają do "
        "dziennika błędów serwera. Dzięki temu logika jest testowalna bez serwera WWW, a kolejność "
        "kontroli — sprawdzona jednym testem jednostkowym."
    )
    code_block(
        pdf,
        "ApiKernel::handle() — kolejność kontroli żądania (skrócony)",
        "if (!in_array($request->method, $methods, true)) {\n"
        "    return self::error(405, __('api.error.method'));\n"
        "}\n"
        "if (!$request->isRead() && !($this->csrfValidator)($request->csrfToken)) {\n"
        "    return self::error(403, __('api.error.csrf'));\n"
        "}\n"
        "$user = ($this->userResolver)();\n"
        "if ($user === false) {\n"
        "    return self::error(401, __('api.error.unauthorized'));\n"
        "}\n"
        "try {\n"
        "    $result = $handler($request, Actor::fromUser($user));\n"
        "    return Response::json(200, ['success' => true] + $result);\n"
        "} catch (ServiceException $e) {\n"
        "    return new Response($e->httpStatus(), [...]);   // 400/403/404/409/422\n"
        "} catch (Throwable $e) {\n"
        "    error_log(...);\n"
        "    return self::error(500, __('api.error.server'));\n"
        "}",
    )
    pdf.paragraph(
        "Pozostałe elementy backendu: klasa Cache zapisuje agregaty pulpitu w plikach JSON z "
        "czasem życia pięć minut, a EventLogger przy każdym zapisie podbija wersję, co unieważnia "
        "wszystkie wpisy; MigrationRunner stosuje migracje z katalogu classes/Migrations, sprawdzając "
        "stan bazy przez SchemaInspector, więc ponowne uruchomienie niczego nie psuje; Translator ładuje "
        "tłumaczenia z katalogu lang (polski domyślnie, angielski, a hiszpański, niemiecki i ukraiński "
        "korzystają z angielskich kluczy zastępczych). Skrypty CLI (scripts/, cron/) zaczynają się od "
        "sprawdzenia PHP_SAPI, więc przeglądarka dostaje 403."
    )

    pdf.subheading("5.3. Implementacja warstwy frontendowej (Vue.js, Tailwind CSS)")
    pdf.paragraph(
        "Panel jest aplikacją jednostronicową napisaną w Vue 3 bez kroku budowania: plik biblioteki "
        "znajduje się w assets/vendor, a kod interfejsu w assets/js (core.js — klient API, store, "
        "powiadomienia i stos okien; components.js — wspólne komponenty; views/ — po jednym pliku "
        "na widok; app.js — montowanie i routing). Widoki są adresowane znacznikiem hash (np. "
        "dashboard.php#/payers/22), więc odświeżenie strony zostawia użytkownika w tym samym miejscu. "
        "Strona dashboard.php sprawdza sesję po stronie serwera i wstrzykuje obiekt "
        "window.CERTISUB_BOOT: tłumaczenia, token CSRF, dane konta, listę uprawnień i adresy "
        "punktów API. Lista uprawnień służy wyłącznie do ukrywania przycisków — dostępu pilnuje serwer."
    )
    pdf.paragraph(
        "Do stylowania użyto Tailwind CSS 3.4. Arkusz jest budowany poleceniem npm run css z pliku "
        "assets/css/app.src.css (klasy komponentów) i zapisany jako assets/css/app.css w repozytorium, "
        "dzięki czemu aplikacja uruchamia się bez Node.js i bez dostępu do Internetu — wymagało tego "
        "wymaganie niefunkcjonalne N3 (instalacja w sieci wewnętrznej); także krój Inter jest "
        "dostarczany lokalnie. Zasoby mają wersję w adresie (parametr ?v=czas zmiany pliku), więc "
        "serwer może je oddawać z nagłówkiem Cache-Control: immutable na rok, a strony PHP i API — "
        "zawsze z no-store."
    )
    pdf.paragraph(
        "Doświadczenie ładowania poprawiają szkielety treści: układ strony (menu, pasek wyszukiwania, "
        "kafelki, tabela) jest częścią HTML, więc użytkownik nie widzi pustego ekranu przed "
        "zamontowaniem Vue; wiersze tabel, kafelki KPI, panele szczegółów i formularze mają własne "
        "szkielety o tych samych wymiarach co docelowa treść, a animacja jest wyłączana, gdy system "
        "ma ustawione ograniczenie ruchu (prefers-reduced-motion). Elementy szkieletu mają atrybuty "
        "ARIA, dzięki czemu czytnik ekranu zapowiada „Ładowanie…” zamiast czytać puste pola."
    )

    pdf.subheading("5.4. Implementacja dashboardu i formularzy")
    pdf.paragraph(
        "Pierwszym elementem, który widzi odwiedzający, jest strona główna (trzy rysunki poniżej) — krótko opisuje "
        "rodzaje pozycji do śledzenia (podpisy kwalifikowane, certyfikaty SSL, licencje SaaS, domeny, "
        "wsparcie chmurowe, podpis kodu), sposób działania oraz funkcje systemu i prowadzi do logowania."
    )
    screenshot(pdf, "landing_hero", "Strona główna aplikacji — nagłówek z opisem funkcji", 150)
    screenshot(pdf, "landing_track", "Strona główna — rodzaje śledzonych pozycji i opis działania", 150)
    screenshot(pdf, "landing_features", "Strona główna — przegląd funkcji i zaproszenie do logowania", 150)
    pdf.paragraph(
        "Po zalogowaniu użytkownik trafia na pulpit (rysunek poniżej). Układ tworzą: nagłówek z nazwą "
        "aplikacji, wyborem języka i kontem, menu boczne zależne od uprawnień roli, pasek „Zakres firm” "
        "(filtr firm), globalna wyszukiwarka (Ctrl+K) i treść widoku. Cztery kafelki KPI pokazują "
        "liczbę certyfikatów w ewidencji, odnowienia w ciągu 30 dni (w tym krytyczne w ciągu 7), "
        "płatności do obsługi oraz średni rabat; pod nimi znajdują się liczniki statusów (oczekujące, "
        "odnowienie w toku, aktywne, wygasłe), lista priorytetowych odnowień i panel statystyk "
        "realizacji zadań z podziałem na statusy i osoby. Wskaźniki są liczone raz na pięć minut "
        "dla każdej roli i konta i odświeżają się natychmiast po zapisie danych."
    )
    screenshot(pdf, "dashboard", "Pulpit administratora: wskaźniki KPI, liczniki statusów i statystyki zadań")
    pdf.paragraph(
        "Listy rekordów (rysunki poniżej) mają jednolity układ: nagłówek z przyciskami eksportu CSV i XML "
        "oraz akcją dodawania, wyszukiwarkę i filtry (typ, status, płatność, priorytet), tabelę z "
        "kolorami wierszy zależnymi od pilności i panel szczegółów otwierany kliknięciem wiersza. "
        "Lista certyfikatów pokazuje m.in. rabat (np. -5%), status płatności i liczbę dni do wygaśnięcia."
    )
    screenshot(pdf, "certificates", "Lista certyfikatów z filtrami, rabatem, płatnością i statusem")
    screenshot(pdf, "beneficiaries", "Lista użytkowników certyfikatów — każda osoba należy do firmy")
    pdf.paragraph(
        "Formularze (dodawania i edycji certyfikatu, osoby, firmy) są podzielone na sekcje i pokazują "
        "błędy walidacji przy polach, zwracane przez serwer; po wyborze osoby firma podpowiada się "
        "sama, a przyciski „+” przy polach pozwalają dodać brakujący rekord bez zamykania formularza. "
        "Pole rabatu przyjmuje zapis „-5%”, „-5”, „5” lub „5,5 %” i ma przyciski szybkiego wyboru."
    )
    screenshot(pdf, "form_cert", "Formularz dodawania certyfikatu (sekcje: dane, ważność, powiązania, rabat i płatność)", 125)
    screenshot(pdf, "cert_details", "Panel szczegółów certyfikatu: powiązania, sekcja „Odnowienie” i historia zdarzeń", 110)
    pdf.paragraph(
        "Widok „Raporty” (rysunek poniżej) udostępnia perspektywy z opisu pracy: kartę użytkownika "
        "certyfikatu, kartę płatnika i skróty administracyjne oraz harmonogram wygaśnięć na 3, 6, 12 lub "
        "24 miesiące z wykresem słupkowym i grupą „Zaległe” dla certyfikatów po terminie. Karty i "
        "harmonogram można wydrukować lub zapisać do PDF."
    )
    screenshot(pdf, "reports", "Widok „Raporty”: perspektywy i harmonogram wygaśnięć")
    pdf.paragraph(
        "Karta firmy (perspektywa płatnika) zawiera dane kontaktowe, wskaźniki (powiązane osoby, bieżące "
        "certyfikaty, najbliższe wygaśnięcie, pozycje wymagające uwagi, otwarte zadania, średni rabat), "
        "harmonogram wygaśnięć na 12 miesięcy, tabelę certyfikatów z datą „odnowienie od” i priorytetem, "
        "archiwum, ścieżkę realizacji zadań, zaproszenia i oś czasu zdarzeń."
    )
    screenshot(pdf, "card_top", "Karta firmy: dane kontaktowe, wskaźniki i powiązane osoby")
    screenshot(pdf, "card_schedule", "Karta firmy: harmonogram wygaśnięć na 12 miesięcy", 150)
    screenshot(pdf, "card_certs", "Karta firmy: certyfikaty z datami odnowienia, priorytetem i rabatem", 150)
    screenshot(pdf, "card_path", "Karta firmy: ścieżka realizacji, zaproszenia i historia na osi czasu", 150)
    screenshot(pdf, "scope_popup", "Pasek „Zakres firm” — okno wyboru: wszystkie firmy, jedna firma albo lista", 150)

    pdf.subheading("5.5. System logowania hasłem i weryfikacji adresu e-mail")
    pdf.paragraph(
        "Pierwsza wersja aplikacji uwierzytelniała personel jednorazowymi kodami wysyłanymi e-mailem "
        "(passwordless OTP). W Etapie 9 mechanizm zastąpiono klasycznym logowaniem adresem e-mail i "
        "hasłem z jednorazowym potwierdzeniem adresu, co upraszcza pracę kont używanych codziennie "
        "i pozwoliło zastosować jednolity limit prób logowania. Tabela login_otps została w bazie "
        "jako historia."
    )
    screenshot(pdf, "login", "Ekran logowania", 120)
    pdf.paragraph("Przepływ uwierzytelniania obejmuje cztery ścieżki:")
    for item in [
        "rejestracja (jeśli włączona w config/auth.php): konto powstaje nieaktywne, a na podany adres "
        "trafia jednorazowy link potwierdzający ważny 24 godziny; zapis na zajęty adres wygląda "
        "identycznie jak udany, ale niczego nie zmienia i nie wysyła wiadomości,",
        "konto założone przez administratora: osoba dostaje link do ustawienia hasła ważny 2 godziny — "
        "administrator nie zna hasła ani go nie widzi,",
        "logowanie: ta sama odpowiedź („Nieprawidłowy e-mail lub hasło”) dla błędnego hasła i nieznanego "
        "adresu; po pięciu nieudanych próbach na adres lub dwudziestu na adres IP w ciągu 15 minut "
        "kolejne próby są blokowane, a udane logowanie zeruje licznik,",
        "zapomniane hasło: wiadomość z linkiem do ustawienia nowego hasła; zapisanie hasła unieważnia "
        "pozostałe linki, a pomyłka w haśle nie „spala” linku.",
    ]:
        pdf.bullet(item)
    pdf.paragraph(
        "Polityka haseł (klasa PasswordPolicy) wzorowana jest na wytycznych NIST SP 800-63B [4]: "
        "minimum 10 znaków bez wymuszania zestawów znaków i okresowej zmiany, z odrzucaniem haseł "
        "łatwych do odgadnięcia. Hasła są zapisywane wyłącznie jako skrót password_hash() i "
        "przeliczane automatycznie, gdy zmieni się koszt algorytmu. Tokeny to 32 losowe bajty "
        "(random_bytes) przekazywane w linku jako 64 znaki szesnastkowe, a w bazie znajduje się tylko "
        "ich skrót SHA-256 z terminem ważności i znacznikiem użycia, więc kopia bazy nie pozwala "
        "użyć cudzego linku. Wysyłki wiadomości weryfikacyjnych są limitowane (3 na adres i 10 na "
        "adres IP w 15 minut), a linki budowane są z adresu zapisanego w konfiguracji (klasa AppUrl), "
        "nie z nagłówka Host żądania."
    )
    pdf.paragraph(
        "Sesja używa ciasteczka HttpOnly z atrybutem SameSite=Lax (oraz Secure przy HTTPS), "
        "po zalogowaniu identyfikator sesji jest regenerowany, a wyłączenie konta kończy sesję przy "
        "następnym żądaniu. Wszystkie zapisy wymagają tokenu CSRF porównywanego funkcją hash_equals. "
        "Po zalogowaniu przekierowanie jest dozwolone wyłącznie na stronę z listy dozwolonych celów. "
        "Wiadomości wychodzą przez SMTP z autoryzacją OAuth2 (XOAUTH2) — własna implementacja interfejsu "
        "OAuthTokenProvider biblioteki PHPMailer wymienia refresh token na token dostępu bez hasła do "
        "skrzynki w konfiguracji [21]."
    )
    pdf.paragraph(
        "Wiadomość z linkiem do ustawienia hasła w trybie sandbox trafia do skrzynki Mailtrap "
        "(pierwszy rysunek poniżej), a w wersji demonstracyjnej jest dostarczana do rzeczywistej "
        "skrzynki odbiorcy (drugi rysunek). Link jest jednorazowy i ważny 2 godziny, a w bazie "
        "zapisany jest tylko skrót tokenu."
    )
    screenshot(pdf, "mailtrap_mail", "Wiadomość „Ustaw hasło” w skrzynce Mailtrap (sandbox)", 150)
    screenshot(pdf, "gmail_mail", "Ta sama wiadomość dostarczona do rzeczywistej skrzynki odbiorcy (wersja demonstracyjna)", 150)
    screenshot(pdf, "accounts", "Widok „Konta i role” dla administratora")
    pdf.paragraph(
        "Widok „Konta i role” (rysunek powyżej) pozwala administratorowi zakładać konta z rolą, "
        "edytować je, przekazywać rekordy odchodzącego pracownika innemu kontu, wysyłać link do ustawienia "
        "hasła oraz wyłączać konta. System nie pozwala wyłączyć własnego konta ani ostatniego aktywnego "
        "administratora."
    )


# --------------------------------------------------------------------------------------------- 6
def chapter6(pdf) -> None:
    pdf.add_page()
    pdf.heading("6. Moduł zadań, zaproszeń i przypomnień e-mail", 14)

    pdf.subheading("6.1. Założenia procesu odnowień i zadań ToDo")
    pdf.paragraph(
        "Proces odnowień jest sercem systemu i realizuje wymagania F11, F12, F18 z opisu pracy. "
        "Skaner (klasa RenewalScanner) przegląda bieżące certyfikaty i dla każdego, który wszedł w "
        "margines odnowienia, zakłada zadanie ToDo przypisane opiekunowi. Margines to wymagany czas "
        "odnowienia zapisany przy certyfikacie (renewal_lead_days), a gdy go brak — domyślny próg z "
        "ustawień (30 dni). Priorytet zadania zależy od liczby dni do wygaśnięcia: „wygasłe” po "
        "dacie, „krytyczne” przy progu krytycznym (domyślnie 7 dni), „do odnowienia” w marginesie."
    )
    code_block(
        pdf,
        "Warunek wyboru certyfikatów przez skaner (fragment zapytania SQL)",
        "WHERE c.archived_at IS NULL\n"
        "  AND c.expiry_date <= DATE_ADD(:today,\n"
        "        INTERVAL COALESCE(c.renewal_lead_days, :default_lead) DAY)\n"
        "  AND (t.id IS NOT NULL OR NOT EXISTS (\n"
        "       SELECT 1 FROM renewal_tasks handled\n"
        "       WHERE handled.certificate_id = c.id\n"
        "         AND handled.status IN ('done', 'abandoned')\n"
        "         AND handled.due_date = c.expiry_date))",
    )
    pdf.paragraph(
        "Skaner jest idempotentny. Certyfikat ma co najwyżej jedno zadanie otwarte (gwarantuje to "
        "unikalny indeks na kolumnie wyliczanej open_marker, opisany w podrozdziale 4.3), a zamknięte "
        "zadanie o terminie równym dacie wygaśnięcia oznacza „cykl już obsłużony”, więc kolejne "
        "uruchomienie tego samego dnia niczego nie powiela. Zadanie certyfikatu, którego opiekun ma "
        "wyłączone konto, pozostaje bez przydziału do czasu decyzji menedżera."
    )
    pdf.data_table(
        "Statusy zadania odnowienia i dozwolone przejścia (klasa TaskService)",
        ["Status", "Znaczenie", "Dozwolone przejścia"],
        [
            ["todo", "do zrobienia — zadanie założone przez skaner lub ręcznie", "w toku, zrobione, porzucone"],
            ["in_progress", "w toku — rozpoczęto prace, np. wysłano zaproszenie", "do zrobienia, zrobione, porzucone"],
            ["done", "zrobione — certyfikat odnowiono", "do zrobienia (tylko menedżer)"],
            ["abandoned", "porzucone — wymagane uzasadnienie", "do zrobienia (tylko menedżer)"],
        ],
        widths=(1.6, 5.0, 3.4),
        size=8.5,
    )
    pdf.paragraph(
        "Ekran „Lista ToDo” (rysunek poniżej) pokazuje liczniki (otwarte, po terminie, moje otwarte, "
        "bez przydziału, zamknięte w ostatnich 30 dniach), filtry statusu, osoby i priorytetu oraz "
        "tabelę zadań z akcjami „Rozpocznij” i „Wyślij zaproszenie”. Zakończenie zadania akcją "
        "„Odnów certyfikat” tworzy nowy certyfikat z tymi samymi powiązaniami (osoba, firma, opiekun) "
        "i nową datą wygaśnięcia, archiwizuje poprzedni i łączy oba w łańcuch odnowień widoczny w "
        "historii. Administrator może zmienić progi (margines, próg krytyczny, odstęp i limit "
        "przypomnień) w widoku „Ustawienia”; każda zmiana trafia do historii zdarzeń."
    )
    screenshot(pdf, "todo", "Lista ToDo: zadanie o priorytecie „Krytyczne” po terminie")
    screenshot(pdf, "settings", "Widok „Ustawienia” — progi procesu odnowień", 140)

    pdf.subheading("6.2. Szablony wiadomości, załączniki i renderowanie treści")
    pdf.paragraph(
        "Zaproszenia i przypomnienia powstają z szablonów edytowanych przez administratora. Szablon "
        "ma kod (renewal_invitation lub renewal_reminder), język, temat oraz treść HTML i tekstową z "
        "polami w nawiasach klamrowych: {imie}, {nazwisko}, {typ_certyfikatu}, {numer_seryjny}, "
        "{data_waznosci}, {dni_do_wygasniecia} i {platnik}. System dostarcza cztery szablony "
        "domyślne (zaproszenie i przypomnienie, po polsku i po angielsku). Klasa TemplateRenderer "
        "podstawia pola, przy czym w treści HTML wartości są escapowane, a w temacie usuwane są "
        "znaki sterujące, co zapobiega wstrzyknięciu kodu i nagłówków przez dane certyfikatu."
    )
    pdf.paragraph(
        "Załączniki przechowuje biblioteka plików (PDF, DOC/DOCX, ODT, XLSX, TXT, CSV, PNG, JPG, ZIP "
        "do 10 MB). Aplikacja sprawdza rozszerzenie i rzeczywistą zawartość pliku, zapisuje go pod "
        "losową nazwą w katalogu storage/attachments niedostępnym z przeglądarki i zapamiętuje skrót "
        "SHA-256; plik użyty w szablonie lub wysłany w zaproszeniu nie może zostać usunięty."
    )
    screenshot(pdf, "templates", "Ekran „Szablony i załączniki” — cztery szablony domyślne (PL i EN)", 150)

    pdf.subheading("6.3. Mechanizm cron, wysyłka zaproszeń i przypomnień")
    pdf.paragraph(
        "Okno wysyłki zaproszenia pozwala wybrać odbiorcę (użytkownik certyfikatu albo firma — odbiorca "
        "bez adresu e-mail jest wyszarzony), szablon, załączniki (domyślnie przypisane do szablonu) "
        "i wyświetla podgląd treści wypełnionej danymi certyfikatu. Wysyłka zapisuje wiersz w rejestrze "
        "invitations z treścią z chwili wysyłki, ustawia zadanie w stan „w toku” (a gdy zadania nie "
        "było — zakłada je) i planuje termin pierwszego przypomnienia. Wiadomość wysyła klasa Mailer "
        "(PHPMailer) za pośrednictwem interfejsu InvitationMailer, który w testach zastępuje "
        "rejestrujący zamiennik — żaden test nie wysyła prawdziwej poczty. Nieudana wysyłka zostaje "
        "w rejestrze ze statusem „failed” i treścią błędu SMTP, a przycisk „Ponów” pozwala ją powtórzyć. "
        "Darmowy plan usługi Mailtrap przyjmuje jedną wiadomość na sekundę, dlatego Mailer po "
        "odrzuceniu z tego powodu czeka dwie sekundy i ponawia wysyłkę raz, a każdy błąd jest zapisywany "
        "z przyczyną w logs/mail-errors.log."
    )
    pdf.data_table(
        "Statusy zaproszenia (tabela invitations)",
        ["Status", "Znaczenie"],
        [
            ["queued", "zaplanowane, jeszcze niewysłane"],
            ["sent", "wysłane; cron wysyła kolejne przypomnienia w ustalonym odstępie do limitu"],
            ["failed", "wysyłka nieudana (treść błędu w rejestrze), możliwe ponowienie"],
            ["responded", "odbiorca odpowiedział — automatyczne przypomnienia wstrzymane"],
            ["closed", "zamknięte ręcznie albo przez zakończenie lub porzucenie zadania"],
        ],
        widths=(2.0, 8.0),
        size=8.5,
    )
    pdf.paragraph(
        "Zadania okresowe uruchamia jeden skrypt, cron/renewals.php (raz na dobę, np. o 7:00): "
        "najpierw skaner odnowień (zakłada zadania i podnosi priorytety), potem wysyłka zaległych "
        "przypomnień według ustawień (domyślnie co 7 dni, nie więcej niż 3 na zaproszenie; wartość 0 "
        "wyłącza przypomnienia automatyczne). Wynik trafia do logs/renewals.log i do historii zdarzeń, "
        "a skrypt uruchomiony z przeglądarki zwraca 403. Skaner może też uruchomić ręcznie menedżer lub "
        "administrator przyciskiem „Uruchom skaner”."
    )
    screenshot(pdf, "invitation_compose", "Okno „Wyślij zaproszenie”: wybór odbiorcy, szablon i podgląd treści z podstawionymi danymi certyfikatu", 130)
    screenshot(pdf, "invitations", "Rejestr „Zaproszenia” z filtrami statusu i zaległych przypomnień", 150)
    screenshot(pdf, "invitation_mail", "Zaproszenie dostarczone do skrzynki odbiorcy z wersji demonstracyjnej na hostingu", 150)

    pdf.subheading("6.4. Integracja modułu z aplikacją webową i rejestr zdarzeń")
    pdf.paragraph(
        "Moduł jest zintegrowany z resztą aplikacji przez wspólne usługi. Każde zdarzenie procesu — "
        "założenie zadania, zmiana statusu lub priorytetu, przydział, wysyłka, przypomnienie, odnowienie "
        "— zapisuje wiersz w tabeli events z identyfikatorami kontekstu, dlatego ta sama historia "
        "pojawia się w szczegółach certyfikatu, na karcie użytkownika certyfikatu, na karcie firmy i "
        "w dzienniku administratora. Oś czasu pokazuje zdarzenia pogrupowane według dni z filtrem "
        "rodzaju (ewidencja, zadania, zaproszenia), a TimelineService ukrywa zdarzenia certyfikatów "
        "spoza zakresu konta. Przydzielenie zadania wysyła komunikat systemowy do wskazanej osoby."
    )
    pdf.paragraph(
        "Wymianę danych obsługuje moduł App\\Exchange. Eksport CSV (UTF-8 z BOM, separator średnik, "
        "apostrof przed treścią zaczynającą się od =, +, - lub @, aby arkusz nie wykonał jej jako "
        "formuły) i XML obejmuje wyłącznie rekordy w zakresie danych konta, a samo pobranie zapisuje "
        "zdarzenie w dzienniku. Import (tylko administrator) wykonuje dokładnie ten sam zapis przez "
        "usługi co ręczne dodawanie, wewnątrz transakcji z punktem zapisu na każdy wiersz, a w trybie "
        "podglądu wycofuje całość — podgląd pokazuje więc rzeczywisty wynik, a nie prognozę, i nie "
        "może się rozjechać z importem. Import wiadomości EML (własny parser MIME, bez rozszerzenia "
        "mailparse) dopasowuje nadawcę do osoby lub firmy, certyfikaty po numerach seryjnych i "
        "oznacza odpowiedzi na zaproszenia."
    )
    screenshot(pdf, "exchange", "Widok „Import i eksport” — eksport zbiorów i kreator importu", 150)
    screenshot(pdf, "events", "Dziennik zdarzeń administratora z filtrami", 150)

    pdf.subheading("6.5. Wnioski o certyfikat przesłane e-mailem")
    pdf.paragraph(
        "W Etapie 10 dodano moduł skracający rejestrację certyfikatów zamawianych pocztą. Wiadomość "
        "z wnioskiem trafia do aplikacji jedną z czterech dróg, które przechodzą przez wspólną metodę "
        "RegistrationIntakeService::ingest(): przez przesłanie pliku .eml w panelu, ze skrzynki IMAP "
        "(cron/mail-intake.php, własny klient IMAP z autoryzacją hasłem lub OAuth2) [19], przez webhook "
        "api/inbound-mail.php chroniony tokenem w nagłówku X-Intake-Token oraz potokiem serwera "
        "pocztowego (scripts/mail-pipe.php)."
    )
    pdf.paragraph(
        "Klasa RegistrationExtractor jest czystą funkcją wiadomości: rozpoznaje etykiety „Etykieta: "
        "wartość” po polsku i angielsku, nagłówki sekcji („Dane firmy”, „Dane wnioskodawcy”), wiersze "
        "tabel HTML, pary rozdzielone tabulatorem lub kreską pionową oraz NIP wpisany w zdaniu "
        "(zawsze sprawdzany sumą kontrolną). Ponieważ skrzynka przyjmuje pocztę od obcych, ekstraktor "
        "ogranicza ilość czytanych danych (tekst 200 kB, HTML 300 kB, 4000 linii, 500 wierszy tabeli), "
        "a analiza tabel ma złożoność liniową; testy sprawdzają, że wrogi dokument przetwarza się "
        "szybko, a nadmiar jest obcinany. Wynik jest propozycją, nie danymi zaufanymi: każde pole "
        "zapisuje źródło (etykieta, nagłówek, treść, wartość domyślna), a wątpliwości trafiają na "
        "listę ostrzeżeń."
    )
    pdf.paragraph(
        "Dane firmy uzupełnia klient Białej Listy podatników VAT Ministerstwa Finansów [18] — "
        "publiczne API bez klucza, odpytywane po NIP-ie; firma, której nie ma w rejestrze "
        "(subject = null), błąd rejestru albo limit zapytań nie blokują wniosku, tylko dodają "
        "ostrzeżenie. Wynik (tabela registration_drafts) trafia do kolejki operatora, który widzi "
        "treść wiadomości obok wypełnionego formularza firmy, osoby i certyfikatu oraz listę uwag. "
        "Zatwierdzenie jednym przyciskiem zakłada w jednej transakcji firmę (jeśli jej nie było), "
        "osobę w tej firmie i certyfikat z operatorem jako opiekunem; błąd w dowolnym kroku wycofuje "
        "całość i podświetla pole w odpowiedniej sekcji. Do rejestru ewidencji nic nie trafia przed "
        "decyzją człowieka, a ta sama wiadomość (Message-ID lub skrót treści) nie powstaje drugi raz."
    )
    screenshot(pdf, "registrations", "Kolejka „Wnioski e-mail” (zakładki: do sprawdzenia, zatwierdzone, odrzucone)", 150)
    screenshot(pdf, "registration_review", "Ekran sprawdzenia wniosku: treść wiadomości i lista uwag obok formularza — w tej wiadomości nie było NIP-u, więc danych firmy nie dało się pobrać z rejestru", 150)

    pdf.subheading("6.6. Dane firm z otwartych rejestrów publicznych")
    pdf.paragraph(
        "Dane firmy po numerze podatkowym pobiera osobna warstwa, wspólna dla całej aplikacji. Każde "
        "źródło implementuje interfejs CompanyRegistry z dwiema metodami: supports() rozstrzyga, czy "
        "potrafi odpowiedzieć na dany numer, a lookupNip() zwraca rekord firmy albo null, gdy numeru "
        "nie ma w rejestrze. CompositeCompanyRegistry pyta wyłącznie te źródła, które obsługują dany "
        "numer, więc polski NIP trafia do Białej Listy podatników VAT [18], a numer VAT z kodem kraju "
        "do unijnego systemu VIES [24]; awaria jednego źródła nie przerywa łańcucha, a gdy nie "
        "odpowiedziało żadne, operator widzi komunikat o awarii, a nie informację o braku firmy. Oba "
        "interfejsy są publiczne i nie wymagają klucza, co było warunkiem wdrożenia na bezpłatnym "
        "hostingu."
    )
    pdf.paragraph(
        "Zakres danych z każdego źródła jest różny. Biała Lista zwraca nazwę, status VAT, REGON, numer "
        "KRS i adres, VIES — nazwę i adres, przy czym część państw członkowskich (m.in. Niemcy, "
        "Hiszpania i Włochy) potwierdza wyłącznie ważność numeru i zamiast danych podatnika zwraca "
        "znacznik „---”; aplikacja zamienia go na puste pole, a operator dostaje potwierdzenie numeru "
        "wraz z ostrzeżeniem, że dane trzeba wpisać ręcznie. Jeżeli Biała Lista poda numer KRS, "
        "odpytywany jest dodatkowo rejestr sądowy [25], który uzupełnia formę prawną i adres rozbity "
        "na ulicę, kod pocztowy i miejscowość. Dział drugi odpisu, zawierający dane osób "
        "reprezentujących spółkę, nie jest czytany: ewidencja certyfikatów go nie potrzebuje, a "
        "ograniczenie zakresu pobieranych danych osobowych wynika z zasady minimalizacji [2]."
    )
    pdf.paragraph(
        "Ponieważ rejestry są usługami zewnętrznymi o ograniczonej przepustowości, zapytania "
        "przechodzą przez dwa dekoratory. CachedCompanyRegistry trzyma odpowiedzi w pamięci podręcznej "
        "przez siedem dni i zapamiętuje także odpowiedź „nie ma takiego numeru”, natomiast awarii nie "
        "zapamiętuje — chwilowa niedostępność rejestru ma prawo minąć przed kolejną próbą. "
        "LookupThrottle ogranicza liczbę zapytań do dwudziestu na konto na godzinę, co chroni zarówno "
        "przed limitem po stronie rejestru, jak i przed pętlą w interfejsie; brak katalogu do zapisu "
        "licznika nie blokuje funkcji, bo ograniczenie ma charakter ochronny, a nie kontroli dostępu."
    )
    pdf.paragraph(
        "Z tej samej warstwy korzystają trzy miejsca w aplikacji. Pierwsze to opisana wyżej kolejka "
        "wniosków z poczty. Drugie to formularz firmy, w którym przycisk „Pobierz z rejestru” przy polu "
        "NIP wypełnia nazwę i adres, a pod polami pokazuje dane rejestrowe (status VAT, REGON, KRS, "
        "datę rejestracji) oraz ostrzeżenia: firmy nie ma w rejestrze, status VAT jest nieczynny, adres "
        "jest niepełny albo firma jest już w ewidencji. Pola wypełnione wcześniej przez operatora "
        "nadpisywane są dopiero po potwierdzeniu. Trzecie to import plików CSV i XML: gdy wiersz ma "
        "numer podatkowy, a komórka z nazwą lub adresem jest pusta, brakująca wartość pochodzi z "
        "rejestru — danych z pliku aplikacja nigdy nie nadpisuje, bo plik jest deklaracją operatora. "
        "Uzupełnienie działa już na etapie podglądu importu (inaczej podgląd pokazywałby błędy "
        "walidacji, których przy zapisie nie będzie), a jeden plik może wywołać najwyżej sto zapytań. "
        "We wszystkich trzech ścieżkach obowiązuje ta sama zasada: dane z rejestru są propozycją i nie "
        "trafiają do ewidencji bez decyzji człowieka."
    )

    pdf.subheading("6.7. Powiadomienia wewnętrzne między kontami")
    pdf.paragraph(
        "Powiadomienia służą głównie do komunikacji administratora z operatorami. Jeden wiersz "
        "w tabeli notifications to jedna wiadomość dla jednego odbiorcy (wiadomość do wielu osób to "
        "kilka wierszy o wspólnym kluczu batch_key); odpowiedzi tworzą wątek (thread_id, parent_id). "
        "Rodzaje: wiadomość, prośba o uzupełnienie danych, zgłoszenie błędu oraz komunikat systemowy "
        "bez nadawcy (np. „przydzielono Ci zadanie odnowienia”, „nowy wniosek z e-maila”). Przycisk "
        "„Zgłoś / poproś o uzupełnienie” w szczegółach certyfikatu, osoby lub firmy powiązuje wiadomość "
        "z rekordem, który odbiorca otwiera jednym kliknięciem; prośbę można oznaczyć jako załatwioną, "
        "co powiadamia nadawcę."
    )
    screenshot(pdf, "notifications", "Widok „Powiadomienia” — prośby o uzupełnienie danych", 150)


# --------------------------------------------------------------------------------------------- 7
def chapter7(pdf) -> None:
    pdf.add_page()
    pdf.heading("7. Testowanie i ocena systemu", 14)

    pdf.subheading("7.1. Scenariusze testowe")
    pdf.paragraph(
        "Strategia testowania opiera się na trzech poziomach. Testy jednostkowe (katalog tests/Unit) "
        "sprawdzają logikę bez bazy danych albo na bazie SQLite w pamięci. Testy integracyjne "
        "(tests/Integration) uruchamiają prawdziwe usługi na jednorazowej bazie MySQL "
        "assistent_subscriptions_test, tworzonej od zera ze schematu i migracji przy każdym przebiegu, "
        "więc nigdy nie dotykają danych użytkownika; są pomijane, dopóki nie ustawi się zmiennej "
        "RUN_INTEGRATION_TESTS. Scenariusz end-to-end przechodzi przez warstwę API, czyli przez to samo "
        "jądro, które obsługuje żądania HTTP (metoda, CSRF, sesja, kody odpowiedzi). Poczta w testach "
        "jest zastępowana rejestrującym zamiennikiem, a rejestr VAT i serwer IMAP — atrapami, dzięki "
        "czemu testy są powtarzalne i nie wymagają sieci."
    )
    pdf.paragraph(
        "Scenariusze zostały dobrane tak, aby pokryć wymagania F1–F19 i N1–N6 oraz najbardziej "
        "ryzykowne miejsca: uprawnienia, spójność danych, uwierzytelnianie i przetwarzanie danych "
        "z zewnątrz."
    )
    pdf.data_table(
        "Wybrane scenariusze testowe i ich wynik oczekiwany",
        ["ID", "Scenariusz", "Wynik oczekiwany", "Test"],
        SCENARIOS,
        widths=(0.8, 4.0, 4.2, 2.2),
        size=8,
    )

    pdf.subheading("7.2. Testy funkcjonalne")
    pdf.paragraph(
        "Pełny zestaw liczy 394 testy: 209 jednostkowych i 185 integracyjnych, w sumie 52 klasy "
        "testowe. Tabele poniżej grupują je tematycznie wraz z liczbą przypadków i zakresem "
        "weryfikacji."
    )
    pdf.data_table(
        "Testy jednostkowe (209 przypadków)",
        ["Klasa testowa", "Liczba", "Co sprawdza"],
        UNIT_TESTS,
        widths=(3.0, 0.9, 6.1),
        size=8,
    )
    pdf.data_table(
        "Testy integracyjne na jednorazowej bazie MySQL (185 przypadków)",
        ["Klasa testowa", "Liczba", "Co sprawdza"],
        INTEGRATION_TESTS,
        widths=(3.0, 0.9, 6.1),
        size=8,
    )
    pdf.paragraph(
        "Jedyny test przechodzący całą aplikację od początku do końca (EndToEndFlowTest) wykonuje "
        "kolejno: założenie firmy, osoby i certyfikatu, uruchomienie skanera, wysłanie zaproszenia, "
        "odnowienie certyfikatu, otwarcie karty raportowej, eksport do CSV, import pliku z podglądem "
        "oraz odczyt dziennika zdarzeń i wyszukiwarki — każdy krok jako żądanie do API z tokenem CSRF "
        "i sesją konkretnej roli."
    )

    pdf.subheading("7.3. Testy integracyjne i bezpieczeństwa logowania")
    pdf.paragraph(
        "Bezpieczeństwo sprawdzano trzema metodami: testami automatycznymi, przeglądem kodu pod kątem "
        "listy OWASP Top 10 [5] oraz kontrolą przez HTTP na uruchomionym serwerze Apache. Moduł "
        "logowania ma największy pojedynczy zestaw (31 przypadków w AuthManagerTest), który obejmuje "
        "m.in.: konto niepotwierdzone nie loguje się, a poprawne hasło na takim koncie nie wlicza się do "
        "limitu; link potwierdzający działa tylko raz i wygasa; wymyślony token jest odrzucany; "
        "rejestracja na zajęty adres niczego nie zmienia i wygląda tak samo; jednakowa odpowiedź dla "
        "błędnego hasła i nieznanego adresu; blokada po pięciu nieudanych próbach i jej zdjęcie po "
        "ustawieniu nowego hasła; wyłączone konto i konto bez hasła nie zalogują się; nowe hasło "
        "unieważnia pozostałe linki i uniemożliwia użycie starego hasła; przekierowanie po logowaniu "
        "akceptuje tylko znane cele; hasła są zapisywane wyłącznie jako skróty."
    )
    pdf.data_table(
        "Kontrole bezpieczeństwa według OWASP Top 10:2021 [5]",
        ["Ryzyko", "Zastosowane zabezpieczenie", "Weryfikacja"],
        OWASP,
        widths=(2.6, 4.6, 2.8),
        size=8,
    )
    pdf.paragraph(
        "Kontrola przez HTTP (przeprowadzona na serwerze Apache z rzeczywistą dostawą wiadomości do "
        "skrzynki Mailtrap) obejmowała cały przebieg: rejestracja, zablokowane logowanie przed "
        "potwierdzeniem, link pobrany ze skrzynki, logowanie, pulpit, wylogowanie, limit prób, brak "
        "tokenu CSRF, „zapomniałem hasła”, nowe hasło i odrzucenie starego. Ścieżki wewnętrzne "
        "(classes, config, storage, tests, .git) zwracają 403, strony wejściowe i zasoby — 200, "
        "a API bez sesji — 401."
    )

    pdf.subheading("7.4. Ocena użyteczności interfejsu dashboardu")
    pdf.paragraph(
        "Ocenę użyteczności przeprowadzono dwiema metodami. Pierwszą jest ekspercka ocena heurystyczna "
        "według dziesięciu heurystyk Nielsena [7, 23], wykonana przez autora na działającej aplikacji; "
        "ma ona charakter subiektywny i nie zastępuje badania z użytkownikami. Wyniki zawiera tabela."
    )
    pdf.data_table(
        "Ocena heurystyczna interfejsu (ocena własna autora)",
        ["Heurystyka", "Obserwacja w aplikacji", "Ocena"],
        HEURISTICS,
        widths=(2.6, 6.2, 1.2),
        size=8,
    )
    pdf.paragraph(
        "Drugą metodą jest ankieta System Usability Scale (SUS) [8], zawierająca dziesięć "
        "stwierdzeń oceniane na skali od 1 („zdecydowanie się nie zgadzam”) do 5 („zdecydowanie się "
        "zgadzam”). Wynik jednej osoby oblicza się tak: dla stwierdzeń nieparzystych odejmuje się 1 "
        "od odpowiedzi, dla parzystych odejmuje się odpowiedź od 5, sumę mnoży się przez 2,5, a wynik "
        "(0–100) uśrednia po respondentach; wartość powyżej około 68 punktów uznaje się za wynik "
        "powyżej przeciętnej."
    )
    pdf.data_table(
        "Stwierdzenia ankiety SUS (wersja polska)",
        ["Nr", "Stwierdzenie"],
        [[str(i + 1), item] for i, item in enumerate(SUS_ITEMS)],
        widths=(0.6, 9.4),
        size=8.5,
    )
    pdf.data_table(
        "Wyniki ankiety SUS — do uzupełnienia po przeprowadzeniu badania",
        ["Respondent", "Rola w badaniu", "Wynik SUS (0–100)"],
        [["R1", "[do uzupełnienia]", "[do uzupełnienia]"],
         ["R2", "[do uzupełnienia]", "[do uzupełnienia]"],
         ["R3", "[do uzupełnienia]", "[do uzupełnienia]"],
         ["Średnia", "—", "[do uzupełnienia]"]],
        widths=(2.0, 4.0, 4.0),
        size=8.5,
    )

    pdf.subheading("7.5. Analiza uzyskanych wyników")
    pdf.data_table(
        "Podsumowanie wskaźników jakości",
        ["Wskaźnik", "Wartość", "Źródło / data"],
        [
            ["Liczba testów", "394 (209 jednostkowych + 185 integracyjnych)", "PHPUnit 11.5, 26.10.2026"],
            ["Wynik pełnego przebiegu z testami integracyjnymi", "394 z 394 zaliczonych, 19 486 asercji, czas 42 s", "przebieg z 26.10.2026"],
            ["Przebieg bez testów integracyjnych", "209 wykonanych, 18 369 asercji, 185 pominiętych", "PHPUnit, 26.10.2026"],
            ["Analiza statyczna PHPStan (poziom 5)", "brak błędów", "composer stan, 26.10.2026"],
            ["Styl kodu PHP-CS-Fixer", "0 uwag w 210 plikach", "composer cs, 26.10.2026"],
            ["Zgodność schematu", "190 kolumn, 87 pozycji indeksów, 32 klucze obce, 2 ograniczenia CHECK — identyczne w bazie aplikacji i bazie testowej", "porównanie information_schema, 2.10.2026"],
            ["Pokrycie wymagań funkcjonalnych", "19 z 19 (F1–F19)", "macierz w docs/MAPA_PROJEKTU.md"],
            ["Czas odpowiedzi API kart i wyszukiwarki", "poniżej 50 ms na danych demonstracyjnych", "pomiar lokalny, 17.09.2026"],
        ],
        widths=(3.4, 4.4, 2.2),
        size=8.5,
    )
    pdf.paragraph(
        "Wyniki potwierdzają, że wszystkie wymagania funkcjonalne z opisu pracy zostały zrealizowane, a "
        "krytyczne reguły (uprawnienia, spójność danych, uwierzytelnianie) są chronione testami "
        "automatycznymi. Największą wartość przyniosły testy integracyjne na prawdziwej bazie — "
        "ujawniły m.in. ograniczenie MySQL, które nie pozwala łączyć klauzuli CHECK z akcją CASCADE "
        "na tej samej kolumnie klucza obcego (błąd 3823), co wymusiło zmianę reguły aktualizacji "
        "klucza beneficiary_id na RESTRICT. Z kolei rozjazd stref czasowych PHP i MySQL, który "
        "przesuwał priorytety o jeden dzień, wykryto przy przygotowywaniu danych demonstracyjnych "
        "i usunięto ustawieniem strefy Europe/Warsaw w bootstrap.php."
    )
    pdf.paragraph(
        "Ograniczenia obecnego stanu testów: scenariusz end-to-end przechodzi przez API, a nie przez "
        "przeglądarkę (brak testów Selenium/Playwright); nie przeprowadzono testów obciążeniowych ani "
        "badania użyteczności z użytkownikami (tabela wyników SUS czeka na uzupełnienie); testy nie "
        "obejmują wysyłki przez prawdziwy serwer SMTP, bo żaden test nie może wysyłać prawdziwych "
        "wiadomości — tę ścieżkę sprawdzono ręcznie w środowisku sandbox Mailtrap."
    )
    pdf.paragraph(
        "Osobnej uwagi wymaga klasa błędów, których zestaw testów nie mógł wykryć, bo leżą poza kodem "
        "aplikacji — w jej konfiguracji na konkretnym serwerze. Przy pierwszym wdrożeniu wersji "
        "demonstracyjnej narzędziem pobierającym kod z repozytorium (26.10.2026) nadpisany został plik "
        "config/database.php: w repozytorium zawiera on ustawienia lokalne, a na hostingu przechowywał "
        "dane logowania do bazy. Strona startowa, która nie korzysta z bazy, działała dalej, natomiast "
        "logowanie i całe API zaczęły zwracać kod 500. Wszystkie 394 testy przechodziły, bo każdy z nich "
        "otrzymuje połączenie z bazą testowej konfiguracji — żaden nie opisuje tego, co wolno nadpisać "
        "przy wdrożeniu. Poprawka objęła trzy elementy: plik trafił na listę chronionych, dane "
        "różniące się między serwerami wyniesiono do pliku config/database.local.php poza "
        "repozytorium, a regułę zabezpieczono testem CodeDeployerTest, który sprawdza, że wdrożenie "
        "zostawia produkcyjną konfigurację nietkniętą. Wniosek ogólny: granica między kodem a "
        "konfiguracją środowiska jest częścią interfejsu systemu i powinna być opisana testem tak samo "
        "jak reguły biznesowe."
    )
    screenshot(pdf, "tests_run", "Zapis pełnego przebiegu testów automatycznych (docs/testy/przebieg-testow-2026-10-26.txt)", 105)


# --------------------------------------------------------------------------------------------- 8
def chapter8(pdf) -> None:
    pdf.add_page()
    pdf.heading("8. Dokumentacja użytkownika", 14)
    pdf.paragraph(
        "Rozdział opisuje instalację i obsługę aplikacji. Pełna instrukcja wraz ze scenariuszem "
        "demonstracji (ok. 30 minut) znajduje się w repozytorium w pliku docs/INSTRUKCJA_OBSLUGI.md, "
        "a uruchomienie na bezpłatnym hostingu — w docs/DEPLOY_INFINITYFREE.md."
    )

    pdf.subheading("8.1. Wymagania i uruchomienie")
    pdf.paragraph("Wymagania wstępne środowiska lokalnego:")
    for item in [
        "PHP 8.1 lub nowszy z rozszerzeniami PDO i MySQL, MySQL 8 lub MariaDB 10.5+, Composer,",
        "Apache z modułem mod_rewrite i AllowOverride All (np. Laragon); katalog aplikacji jest "
        "jednocześnie katalogiem głównym serwera,",
        "Node.js — opcjonalnie, tylko do przebudowy arkusza stylów (zbudowany plik jest w repozytorium).",
    ]:
        pdf.bullet(item)
    pdf.paragraph("Kroki instalacji:")
    for item in [
        "composer install — instaluje zależności,",
        "config/database.php — dane połączenia z MySQL (domyślnie localhost, baza assistent_subscriptions, "
        "użytkownik root),",
        "config/mail.local.php (wzorzec: config/mail.local.php.example) — poczta; sterownik sandbox kieruje "
        "wiadomości wyłącznie do skrzynki Mailtrap, a sterownik log zapisuje je do logs/mail.log,",
        "php scripts/migrate.php — tworzy i aktualizuje schemat (nową bazę można utworzyć opcją --fresh, "
        "która usuwa wszystkie tabele — wyłącznie dla bazy tymczasowej),",
        "php scripts/seed-demo-data.php — opcjonalne dane demonstracyjne (konta demo mają ustawione hasła),",
        "adres aplikacji: http://localhost/assistent_subscription/.",
    ]:
        pdf.bullet(item)
    pdf.paragraph(
        "Zadania okresowe: raz na dobę php cron/renewals.php (skaner i przypomnienia), a co kilka "
        "minut — jeśli włączono odbiór wniosków ze skrzynki — php cron/mail-intake.php."
    )

    pdf.subheading("8.2. Logowanie, konta i role")
    pdf.paragraph(
        "Logowanie odbywa się adresem e-mail i hasłem na stronie login.php. Konto założone przez "
        "administratora nie ma hasła — osoba dostaje wiadomość z linkiem (ważnym 2 godziny) i sama "
        "je ustawia. Przy włączonej rejestracji publicznej nowe konto trzeba potwierdzić linkiem z "
        "wiadomości (ważnym 24 godziny). Zapomniane hasło odzyskuje się linkiem „Nie pamiętasz hasła?”. "
        "Menu i dostępne akcje zależą od roli (tabela w podrozdziale 3.4 i załącznik B)."
    )

    pdf.subheading("8.3. Praca z ewidencją")
    for item in [
        "Certyfikaty: lista z wyszukiwarką i filtrami, szczegóły z historią i łańcuchem odnowień, "
        "formularz dodawania. Certyfikat kwalifikowany wymaga użytkownika certyfikatu, a ten — firmy. "
        "Rabat wpisuje się jako procent (np. -5%). Archiwizacja (menedżer, administrator) ukrywa "
        "rekord z bieżących list i zamyka otwarte zadanie jako porzucone; z archiwum rekord można "
        "przywrócić.",
        "Użytkownicy certyfikatów i firmy: osoby nie można zarchiwizować, dopóki ma bieżące "
        "certyfikaty, a firmy — dopóki ma osoby lub certyfikaty. NIP jest sprawdzany sumą kontrolną "
        "i musi być unikalny.",
        "Wyszukiwarka globalna (Ctrl+K lub /): wpisanie co najmniej dwóch znaków podpowiada certyfikaty, "
        "osoby i firmy z podaniem powodu dopasowania; można szukać po nazwie, numerze seryjnym, NIP-ie "
        "(kreski i spacje nie mają znaczenia), telefonie lub nazwisku opiekuna.",
        "Filtr firm (pasek „Zakres firm”): zawęża pracę do wszystkich firm, jednej firmy lub wybranej "
        "listy; nie jest kontrolą dostępu, a eksport go pomija.",
    ]:
        pdf.bullet(item)

    pdf.subheading("8.4. Proces odnowienia krok po kroku")
    for item in [
        "Skaner (codziennie przez cron albo przyciskiem „Uruchom skaner”) zakłada zadanie dla "
        "certyfikatu w marginesie odnowienia — pojawia się na liście ToDo z priorytetem.",
        "Osoba prowadząca sprawę klika „Rozpocznij” (status „w toku”) i „Wyślij zaproszenie”: wybiera "
        "odbiorcę, szablon i załączniki, sprawdza podgląd i wysyła.",
        "W rejestrze „Zaproszenia” widać status wysyłki; przyciski „Przypomnij”, „Ponów”, "
        "„Odpowiedziano” i „Zamknij” obsługują dalszy kontakt, a cron wysyła kolejne przypomnienia "
        "(domyślnie co 7 dni, do 3).",
        "Po załatwieniu sprawy w panelu zadania klika się „Odnów certyfikat”, podając nową datę "
        "wygaśnięcia (późniejszą od poprzedniej), numer seryjny i płatność; stary certyfikat trafia "
        "do archiwum, a zadanie otrzymuje status „zrobione”. Zadanie można też porzucić, podając powód.",
    ]:
        pdf.bullet(item)

    pdf.subheading("8.5. Raporty, wymiana danych i wnioski e-mail")
    for item in [
        "Raporty: karta użytkownika certyfikatu i karta firmy (daty odnowienia, certyfikaty z "
        "archiwum, zadania, zaproszenia, oś czasu), harmonogram wygaśnięć na 3–24 miesiące; "
        "przycisk „Drukuj” tworzy wydruk bez menu.",
        "Eksport (menedżer i wyżej, szef, księgowa): przyciski CSV i XML przy listach, kartach i "
        "w widoku „Import i eksport”; CSV otwiera się w Excelu bez kreatora.",
        "Import (administrator): kolejność firmy → użytkownicy certyfikatów → certyfikaty; krok "
        "„Sprawdź plik” pokazuje wynik dla każdego wiersza (nowy, aktualizacja, bez zmian, pominięty, "
        "błąd), a „Importuj” zapisuje poprawne wiersze; do pobrania są wzory plików.",
        "Wnioski e-mail (operator, menedżer, administrator): wczytanie pliku .eml lub odbiór ze "
        "skrzynki tworzy gotowy formularz; operator sprawdza dane, w razie potrzeby prosi o "
        "uzupełnienie lub odrzuca, a „Zatwierdź i utwórz” zakłada firmę, osobę i certyfikat.",
        "Powiadomienia: wiadomości między kontami, prośby o uzupełnienie danych powiązane z "
        "rekordem i komunikaty systemowe; liczba nieprzeczytanych widoczna jest w menu.",
    ]:
        pdf.bullet(item)

    pdf.subheading("8.6. Administracja")
    for item in [
        "Konta i role: dodawanie kont z rolą, edycja, przekazanie rekordów innemu kontu (np. przed "
        "odejściem pracownika), wysłanie linku do hasła, wyłączanie i włączanie kont; nie można "
        "wyłączyć własnego konta ani ostatniego administratora.",
        "Szablony i załączniki: edycja treści z podglądem, język, aktywność; biblioteka plików do 10 MB.",
        "Ustawienia: margines odnowienia (30 dni), próg krytyczny (7 dni), odstęp między "
        "przypomnieniami (7 dni), limit przypomnień (3).",
        "Dziennik zdarzeń: wszystkie operacje z filtrami (szukaj, obszar, zdarzenie, autor, daty); "
        "tylko do odczytu.",
    ]:
        pdf.bullet(item)

    pdf.subheading("8.7. Rozwiązywanie problemów")
    pdf.data_table(
        "Najczęstsze problemy i ich rozwiązanie",
        ["Objaw", "Przyczyna i rozwiązanie"],
        [
            ["Strona się nie otwiera lub brak połączenia z bazą", "Apache lub MySQL nie działa — uruchomić usługi (Laragon: Start All), potem php scripts/migrate.php"],
            ["Błąd o brakującej tabeli lub kolumnie", "kod i baza pochodzą z różnych etapów — php scripts/migrate.php"],
            ["Panel pusty, wszędzie zera", "brak danych — php scripts/seed-demo-data.php --force"],
            ["Nie widzę menu Archiwum lub Konta i role", "rola konta nie ma tych uprawnień (archiwum od menedżera, konta tylko administrator) — administrator zmienia rolę"],
            ["Zaproszenie ma status „Nieudane”", "błąd serwera pocztowego (treść w rejestrze) — sprawdzić config/mail.local.php, potem „Ponów”"],
            ["Nie przychodzi wiadomość z linkiem", "przy sterowniku sandbox sprawdzić skrzynkę Mailtrap; sprawdzić, czy konto nie jest wyłączone i czy nie przekroczono limitu wysyłek"],
            ["Skaner nie zakłada zadania", "certyfikat jest poza marginesem, w archiwum, ma otwarte zadanie albo zadanie dla tej daty zostało zamknięte"],
            ["Pulpit pokazuje stare liczby", "wskaźniki liczone są raz na 5 minut, a zapis danych je odświeża; sprawdzić prawa zapisu do storage/cache"],
            ["Import: „Nie znaleziono płatnika”", "najpierw zaimportować firmy, a w pliku osób i certyfikatów podać NIP lub dokładną nazwę"],
            ["Testy integracyjne są pomijane", "ustawić RUN_INTEGRATION_TESTS=1 (wymaga działającego MySQL)"],
        ],
        widths=(3.4, 6.6),
        size=8.5,
    )


# --------------------------------------------------------------------------------------------- 9
def chapter9(pdf) -> None:
    pdf.add_page()
    pdf.heading("9. Zakończenie", 14)

    pdf.subheading("9.1. Podsumowanie pracy")
    pdf.paragraph(
        "Celem pracy było zaprojektowanie i zaimplementowanie aplikacji webowej wspierającej ewidencję "
        "certyfikatów (w tym kwalifikowanych) wraz z ich użytkownikami i płatnikami, nadzorującej "
        "terminy odnowień i obsługującej korespondencję z beneficjentami. Cel został osiągnięty: "
        "powstał działający system CertiSub Assistant, który realizuje wszystkie dziewiętnaście "
        "wymagań funkcjonalnych z opisu pracy oraz wymagania niefunkcjonalne dotyczące technologii "
        "(PHP, HTML, JavaScript, MySQL w standardowym układzie LAMP), bezpieczeństwa i możliwości "
        "wdrożenia na zwykłym hostingu."
    )
    pdf.paragraph("Najważniejsze rezultaty pracy:")
    for item in [
        "ewidencja certyfikatów ośmiu rodzajów z użytkownikami i firmami, archiwizacją zamiast usuwania "
        "oraz spójnością danych wymuszaną przez bazę (łańcuch firma → użytkownik → certyfikat kwalifikowany),",
        "automatyczny proces odnowień: skaner, lista ToDo z priorytetami i statusami, zaproszenia z "
        "szablonów z załącznikami, przypomnienia, odnowienie z łańcuchem certyfikatów i statystyki zadań,",
        "raporty perspektyw użytkownika, płatnika i administratora, wyszukiwarka z powiązaniami, oś "
        "czasu i dziennik zdarzeń, import i eksport CSV, XML i EML,",
        "siedem ról stanowisk z zakresem danych liczonym po stronie serwera oraz logowanie hasłem z "
        "potwierdzeniem adresu, limitami prób i jednorazowymi tokenami,",
        "moduł wniosków e-mail z odczytem danych, uzupełnianiem firmy z rejestru VAT i zatwierdzaniem "
        "w jednej transakcji oraz powiadomienia wewnętrzne,",
        "394 testy automatyczne, analiza statyczna na poziomie 5 bez błędów i dokumentacja "
        "(instrukcja obsługi, opis bazy danych, opis API i poleceń CLI),",
        "wersja demonstracyjna wdrożona na bezpłatnym hostingu bez dostępu SSH.",
    ]:
        pdf.bullet(item)
    pdf.paragraph(
        "Wybór stosu PHP/LAMP, uzasadniony studium wykonalności, okazał się trafny: aplikacja działa na "
        "zwykłym hostingu bez kroku budowania, a brak zależności od CDN i rozszerzeń PHP pozwolił "
        "uruchomić ją także w sieci wewnętrznej. Decyzje projektowe podejmowane w trakcie prac — "
        "wydzielenie modułu prywatnego do osobnej aplikacji, zamiana ceny na rabat procentowy, "
        "rozszerzenie ról do profili stanowisk, przejście z kodów OTP na hasła — ilustrują, że wymagania "
        "doprecyzowywały się wraz z poznawaniem dziedziny, a architektura warstwowa pozwoliła je "
        "wprowadzać bez przebudowy."
    )
    pdf.paragraph(
        "Ograniczenia: dostęp do systemu mają wyłącznie konta personelu (użytkownicy certyfikatów i "
        "płatnicy nie logują się, a ich perspektywy są raportami dla personelu — decyzja D2); testy nie "
        "obejmują interfejsu w przeglądarce ani obciążenia; powiadomienia dla użytkowników końcowych "
        "ograniczają się do wiadomości e-mail; odbiór wniosków wymaga skonfigurowania skrzynki IMAP "
        "albo webhooka."
    )
    pdf.paragraph(
        "Wdrożenie wersji demonstracyjnej dostarczyło też wniosku, którego nie dało się wyprowadzić z "
        "samego kodu. Hosting bez dostępu SSH wymusił narzędzie pobierające paczkę z repozytorium "
        "wprost na serwer, a pierwsze uruchomienie tego narzędzia nadpisało konfigurację połączenia z "
        "bazą danych i unieruchomiło logowanie (opis w podrozdziale 7.5). Przyczyną nie była pomyłka w "
        "pojedynczej linii, lecz brak jawnie zapisanej granicy między kodem a konfiguracją środowiska: "
        "plik należał jednocześnie do repozytorium i do ustawień serwera. Po poprawce dane zależne od "
        "środowiska mieszkają w plikach spoza repozytorium, a reguła jest pilnowana testem. "
        "Doświadczenie to potwierdza, że przy aplikacji przeznaczonej na zwykły hosting proces "
        "wdrożenia wymaga takiej samej uwagi projektowej jak sama aplikacja."
    )

    pdf.subheading("9.2. Kierunki dalszego rozwoju")
    for item in [
        "osobne logowanie dla użytkowników certyfikatów i płatników z dostępem tylko do własnej karty "
        "(samoobsługowy wgląd w daty odnowienia i odpowiedź na zaproszenie jednym kliknięciem),",
        "testy interfejsu w przeglądarce (Playwright) i testy obciążeniowe,",
        "uwierzytelnianie dwuskładnikowe dla kont o rolach z szerokim zakresem danych oraz integracja "
        "z zewnętrznym dostawcą tożsamości (SSO),",
        "powiadomienia w dodatkowych kanałach (SMS, komunikatory) i kalendarz wygaśnięć w formacie "
        "iCalendar,",
        "automatyczne pobieranie informacji o statusie certyfikatów SSL z publicznych rejestrów "
        "przejrzystości certyfikatów oraz integracja z interfejsami wystawców,",
        "kolejne źródła danych o firmach, które wymagają wniosku o bezpłatny klucz dostępu: CEIDG "
        "(działalności jednoosobowe) i GUS BIR/REGON — warstwa CompanyRegistry jest na nie "
        "przygotowana, brakuje wyłącznie poświadczeń,",
        "rozszerzenie odczytu wniosków o załączniki PDF (OCR) i uczenie ekstraktora na własnych "
        "wiadomościach,",
        "powiązanie z osobną aplikacją „Menedżer Subskrypcji” (prywatne subskrypcje) na poziomie "
        "wspólnego logowania, przy zachowaniu rozdzielnych baz danych,",
        "zmiana katalogu głównego serwera na osobny katalog public/ — wtedy blokady w pliku .htaccess "
        "przestaną być potrzebne.",
    ]:
        pdf.bullet(item)


# --------------------------------------------------------------------------------------- back matter
def back_matter(pdf) -> None:
    pdf.add_page()
    pdf.start_section("Bibliografia", level=0)
    pdf.heading("Bibliografia", 14)
    pdf.set_font("Arial", "", 10.5)
    for index, entry in enumerate(BIBLIOGRAPHY, start=1):
        pdf.multi_cell(pdf.usable_width(), 5.2, f"[{index}] {entry}")
        pdf.ln(1.2)

    pdf.add_page()
    pdf.start_section("Spis rysunków", level=0)
    pdf.heading("Spis rysunków", 14)
    pdf.set_font("Arial", "", 10)
    for number, caption, page in pdf.figures:
        pdf.multi_cell(pdf.usable_width(), 5.2, f"Rysunek {number}. {caption} ... {page}")
        pdf.ln(0.6)
    # Uwaga o pustych ramkach ma sens tylko wtedy, gdy w pracy rzeczywiście jakaś została.
    if getattr(pdf, "placeholder_count", 0):
        pdf.ln(2)
        pdf.set_font("Arial", "I", 9)
        pdf.multi_cell(
            pdf.usable_width(), 4.6,
            "Pozycje oznaczone w tekście ramką „miejsce na rysunek” wymagają wstawienia zrzutu ekranu przez autora."
        )

    pdf.add_page()
    pdf.start_section("Spis tabel", level=0)
    pdf.heading("Spis tabel", 14)
    pdf.set_font("Arial", "", 10)
    for number, caption, page in pdf.tables:
        pdf.multi_cell(pdf.usable_width(), 5.2, f"Tabela {number}. {caption} ... {page}")
        pdf.ln(0.6)

    pdf.add_page()
    pdf.start_section("Załączniki", level=0)
    pdf.heading("Załączniki", 14)
    pdf.start_section("Załącznik A. Oficjalny opis pracy", level=1)
    pdf.subheading("Załącznik A. Oficjalny opis pracy (dosłownie)", 12)
    pdf.set_font("Arial", "", 10.5)
    pdf.multi_cell(pdf.usable_width(), 5.4, _official_description())
    pdf.ln(3)

    pdf.add_page()
    pdf.start_section("Załącznik B. Macierz uprawnień ról", level=1)
    pdf.subheading("Załącznik B. Macierz uprawnień ról", 12)
    pdf.paragraph(
        "Macierz wygenerowano z pliku classes/Rbac.php. Znak ● oznacza, że rola ma dane uprawnienie. "
        "Skróty: ADM — administrator, DIR — szef, MGR — menedżer, ACC — księgowa, IT — informatyk, "
        "OPR — operator, EMP — pracownik.",
        10,
    )
    roles, permissions, lists = rbac_matrix()
    header = ["Uprawnienie"] + ["ADM", "DIR", "MGR", "ACC", "IT", "OPR", "EMP"]
    rows = [[permission] + ["●" if permission in lists[role] else "" for role in roles] for permission in permissions]
    pdf.data_table("Macierz uprawnień ról stanowisk", header, rows, widths=(4.2, 1, 1, 1, 1, 1, 1, 1), size=8)

    pdf.start_section("Załącznik C. Struktura katalogów repozytorium", level=1)
    pdf.subheading("Załącznik C. Struktura katalogów repozytorium", 12)
    pdf.data_table(
        "Najważniejsze katalogi projektu",
        ["Katalog", "Zawartość"],
        [
            ["api/", "20 punktów wejścia API (każdy to jedna linia uruchamiająca kontroler)"],
            ["classes/", "kod aplikacji: Service, Api, Http, Exchange, Intake, Auth, Mail, Migrations"],
            ["assets/", "interfejs: js (core, components, views), css (Tailwind), vendor (Vue), fonts"],
            ["cron/, scripts/", "zadania okresowe i narzędzia CLI (migracje, dane demo, hasła, test poczty)"],
            ["database/", "schema.sql — schemat świeżej instalacji"],
            ["config/", "konfiguracja bazy, autoryzacji, poczty i odbioru wniosków (sekrety w plikach *.local.php poza gitem)"],
            ["lang/", "tłumaczenia (pl, en, es, de, uk)"],
            ["storage/", "załączniki, oryginały wniosków, pamięć podręczna — zablokowane przez HTTP"],
            ["tests/", "testy jednostkowe, integracyjne i klasy pomocnicze"],
            ["docs/", "dokumentacja: mapa projektu, instrukcja, opis bazy, API, CLI, wdrożenie, diagramy ERD"],
        ],
        widths=(2.4, 7.6),
        size=8.5,
    )


def _official_description() -> str:
    text = (REPO / "docs" / "MAPA_PROJEKTU.md").read_text(encoding="utf-8")
    match = re.search(r"## Załącznik A.*?```text\n(.*?)\n```", text, re.S)
    return match.group(1).strip() if match else "[opis pracy dostępny w docs/MAPA_PROJEKTU.md]"
