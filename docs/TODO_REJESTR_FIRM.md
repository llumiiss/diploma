# Lista zadań — rejestry firm z otwartych źródeł + wdrożenia na produkcję

Stan na 2026-10-23. Lista powstała po pytaniu autora „czy mamy API do rozpoznawania firm
z otwartych źródeł?”. Odpowiedź: **mamy, ale tylko w jednej ścieżce** (wnioski z poczty) i tylko
jedno źródło. Poniżej kolejność prac — od wdrożenia tego, co już jest, do kolejnych rejestrów.

Produkcja: <https://subscriptionassistent.freedev.app/> (InfinityFree, bez SSH, pliki przez FTP).
Repozytorium: `github.com/llumiiss/diploma`, gałąź `master`.

---

## Co już działa (punkt wyjścia)

| Element | Stan |
|---|---|
| Biała lista podatników VAT (Ministerstwo Finansów), `GET https://wl-api.mf.gov.pl/api/search/nip/{nip}?date=…` — publiczne API bez klucza | ✅ `classes/Intake/WhiteListRegistry.php` |
| Interfejs `CompanyRegistry` + `CompanyRecord` (NIP, nazwa, status VAT, REGON, KRS, adres, data rejestracji) | ✅ `classes/Intake/` |
| Automatyczne odpytanie rejestru przy wniosku z e-maila (NIP z treści listu) | ✅ `RegistrationIntakeService` |
| Przycisk „Pobierz dane z rejestru po NIP” na ekranie przeglądu wniosku | ✅ akcja `refresh_company` w `api/registrations.php` |
| Ostrzeżenia: brak NIP-u, zła suma kontrolna, kilka NIP-ów, firmy nie ma w rejestrze, status VAT nieczynny, nazwa inna niż w rejestrze | ✅ `registration.warning.*` |
| Testy: kody HTTP, rozbiór adresu, pisownia nazwy; atrapa `FakeCompanyRegistry` w testach integracyjnych | ✅ `tests/Unit/WhiteListRegistryTest.php` |
| Sprawdzenie na żywo (2026-10-23): Orlen `7740001454`, Allegro `5252674798` — nazwa, status VAT, REGON, KRS, adres | ✅ |

---

## Krok 0 — wdrożyć obecny stan na produkcję ⏳ W TOKU

Produkcja ma kod Etapu 9 i 10 (sprawdzone: `api/registrations.php`, `api/notifications.php`,
`api/preferences.php` odpowiadają 401, `verify-email.php` i `set-password.php` działają), ale
**pliki językowe są sprzed 2026-10-23** — po przełączeniu na hiszpański sekcja „hero” na stronie
startowej jest po angielsku („Daily renewal scanner”).

- [ ] wgrać przez FTP paczkę `deploy-2026-10-23.zip`: `lang/es.php`, `lang/de.php`, `lang/uk.php`,
      `deploy-pull.php`, `classes/Deploy/` — i dopisać `pull_token` do `config/deploy.local.php`
      (od tego momentu kolejne wdrożenia idą bez FTP)
- [ ] sprawdzić `index.php?lang=es`, `?lang=de`, `?lang=uk` — sekcja „hero” i stopka w wybranym języku
- [ ] sprawdzić po zalogowaniu jeden ekran panelu w każdym języku (np. „Certyfikaty” i „Zadania”)

Hasła FTP nie przechodzą przez agenta — pliki wgrywa autor (FileZilla, `ftpupload.net`, katalog `htdocs`).

## Krok 1 — wdrożenia bez FTP ✅ ZROBIONE (2026-10-23)

Cel: jedno kliknięcie zamiast ręcznego FTP przy każdej poprawce.

`deploy-pull.php` + `classes/Deploy/` (`TarArchive` — czytnik tar.gz w czystym PHP, bo na hostingu
nie ma `ZipArchive` i bywa wyłączony `phar`; `CodeDeployer` — reguły wykluczeń i zapis).
Opis w `docs/DEPLOY_INFINITYFREE.md` §8a. Sprawdzone na prawdziwej paczce z GitHuba
(`?dry=1`: 1,3 MB, 200 plików bez zmian, 78 pominiętych, 5 chronionych).
17 testów w `tests/Unit/TarArchiveTest.php` i `tests/Unit/CodeDeployerTest.php`.

- [x] `deploy-pull.php` w katalogu głównym: token `pull_token` z `config/deploy.local.php`, pobiera
      `https://codeload.github.com/llumiiss/diploma/tar.gz/refs/heads/<ref>`, rozpakowuje własnym
      czytnikiem tar.gz (bez `PharData` i `ZipArchive` — na hostingu bywają wyłączone)
- [x] **nie nadpisuje**: `config/*.local.php`, `storage/`, `logs/`, `vendor/`, `.git/` — wyjątkiem są
      `.htaccess` i `.gitkeep` pilnujące katalogów (blokada załączników musi dać się przywrócić);
      `vendor/` zostaje przy FTP, bo paczka z GitHuba go nie zawiera
- [x] wypisuje listę zmienionych plików, liczbę bez zmian, pominiętych i chronionych; tryb `&dry=1`
      pokazuje zmiany bez zapisu, `&ref=` wybiera gałąź lub tag
- [x] po wdrożeniu czyści `storage/cache/`
- [x] opis w `docs/DEPLOY_INFINITYFREE.md` §8a + przypomnienie o zmianie tokenu
- [x] testy bez sieci: czytnik tar (puste pliki, granica bloku, katalogi, długie nazwy GNU, nagłówek pax,
      uszkodzone archiwum) i wdrożenie (wykluczenia, ścieżki z `..`, pliki bez zmian, podgląd, czyszczenie cache)

## Krok 2 — pobieranie danych firmy po NIP w formularzu „Firmy”

Największa luka: przy ręcznym dodawaniu firmy operator wpisuje wszystko sam.

- [ ] akcja `lookup_tax_id` w `api/payers.php` (uprawnienie `payers.manage`; limit: 20 zapytań na konto na godzinę)
- [ ] `PayerService::lookupRegistry(Actor, string $taxId): array` — korzysta z `CompanyRegistry` z `IntakeConfig`
- [ ] w formularzu firmy (`assets/js/views/payers.js`) przycisk „Pobierz z rejestru” przy polu NIP:
      wypełnia nazwę, ulicę, kod, miasto; nie nadpisuje pól, w których operator już coś wpisał (pyta)
- [ ] pokazanie statusu VAT i REGON/KRS jako informacji (bez zapisu — w bazie nie ma na nie kolumn)
- [ ] ostrzeżenie, gdy firmy nie ma w rejestrze albo status VAT jest nieczynny
- [ ] klucze i18n w **pięciu** plikach językowych (`payer.registry.*`)
- [ ] testy: trafienie, brak firmy, błąd rejestru (atrapa), brak uprawnienia, limit zapytań

## Krok 3 — cache i limit zapytań do rejestru

- [ ] `CachedCompanyRegistry` (dekorator `CompanyRegistry`): odpowiedzi w `storage/cache` na 7 dni,
      osobno zapamiętane „nie ma takiego NIP-u”
- [ ] licznik zapytań na dobę w bazie lub w cache — ochrona przed limitem MF i przed pętlą w imporcie
- [ ] `IntakeConfig::registry()` zwraca dekorator; przełącznik `registry.cache_days` w `config/intake.php`
- [ ] testy: drugie zapytanie nie rusza transportu, wygaśnięcie wpisu, limit dobowy

## Krok 4 — kolejne otwarte źródła za tym samym interfejsem

- [ ] `CompositeCompanyRegistry` — pyta po kolei, pierwsza odpowiedź wygrywa, błąd jednego źródła
      nie przerywa łańcucha
- [ ] **VIES** (`ec.europa.eu/taxation_customs/vies`, REST, bez klucza) — firmy z UE, bo dziś obsługujemy
      tylko polski NIP; pole „kraj” przy NIP-ie
- [ ] **KRS** (`api-krs.ms.gov.pl`, bez klucza) — dane rejestrowe spółki po numerze KRS z Białej listy
      (forma prawna, adres, reprezentacja)
- [ ] **CEIDG v3** (darmowy token) — jednoosobowe działalności, których Biała lista opisuje szczątkowo
- [ ] **GUS BIR/REGON** (darmowy klucz) — PKD i forma prawna
- [ ] w `config/intake.php`: które źródła włączone i w jakiej kolejności; klucze w `intake.local.php`
- [ ] testy każdego klienta na atrapach transportu + test kolejności w `CompositeCompanyRegistry`

## Krok 5 — rejestr w imporcie CSV/XML

- [ ] w `ImportService`: gdy wiersz płatnika ma NIP, a brakuje nazwy lub adresu, uzupełnić z rejestru
      (tylko w trybie zapisu, nie w podglądzie; z cache z kroku 3)
- [ ] w podglądzie importu kolumna „źródło danych” (plik / rejestr)
- [ ] testy: uzupełnienie, brak firmy w rejestrze, rejestr niedostępny (import nie może się wywalić)

## Krok 6 — domknięcie

- [ ] komunikaty wyjątków z `classes/Intake/*` (`WhiteListRegistry`, `ImapClient`, `MailboxConnector`,
      `StreamImapTransport`) przeniesione do plików językowych — dziś wchodzą po polsku w przetłumaczone
      zdania przez `:message` (≈27 komunikatów × 5 języków)
- [ ] sprawdzenie na produkcji, czy wychodzące HTTPS do `wl-api.mf.gov.pl` działa na InfinityFree
      (poczta idzie tam przez HTTPS, więc powinno) — przycisk „Pobierz z rejestru” na żywo
- [ ] `docs/MAPA_PROJEKTU.md` (dziennik zmian), `docs/BAZA_DANYCH.md` (jeśli dojdą kolumny),
      `docs/INSTRUKCJA_OBSLUGI.md` (rozdział o rejestrach), `CLAUDE.md` (zasady)
- [ ] pełna bramka jakości: `composer test` z integracyjnymi, `composer stan`, `composer cs`

---

## Zasady na czas tych prac

- Każdy krok to osobny commit po polsku i push na `master` (autor poprosił o wdrożenia na bieżąco).
- Nowe teksty trafiają do **wszystkich pięciu** plików w `lang/` — pilnuje tego
  `tests/Unit/TranslationFilesTest.php`.
- Rejestry zawsze za interfejsem `CompanyRegistry`, żeby testy nie wychodziły do sieci
  (`Tests\Support\FakeCompanyRegistry`).
- Żadne dane z rejestru nie trafiają do ewidencji bez decyzji operatora — tak jak dziś przy wnioskach.
- Sekrety (tokeny CEIDG, GUS) tylko w `config/intake.local.php` (gitignored), nigdy w repozytorium.
