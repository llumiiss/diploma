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

## Krok 2 — pobieranie danych firmy po NIP w formularzu „Firmy” ✅ ZROBIONE (2026-10-23)

Największa luka: przy ręcznym dodawaniu firmy operator wpisuje wszystko sam.

- [x] akcja `lookup_tax_id` w `api/payers.php` (wymaga `payers.create` albo `payers.update`; limit 20 zapytań
      na konto na godzinę przez `App\Service\LookupThrottle`)
- [x] `PayerService::lookupTaxId(Actor, string $taxId): array` — korzysta z `CompanyRegistry` z `IntakeConfig`,
      zwraca pola do formularza, dane rejestrowe (status VAT, REGON, KRS, data rejestracji), informację
      o firmie już obecnej w ewidencji i listę ostrzeżeń
- [x] w formularzu firmy (`assets/js/views/payers.js`) przycisk „Pobierz z rejestru” przy polu NIP:
      wypełnia nazwę, ulicę, kod i miasto; pola już wypełnione nadpisuje dopiero po potwierdzeniu
- [x] panel z danymi rejestrowymi: nazwa rejestrowa, status VAT, REGON, KRS, data rejestracji (bez zapisu
      — w bazie nie ma na nie kolumn)
- [x] ostrzeżenia: brak firmy w rejestrze, nieczynny status VAT, niepełny adres, firma już w ewidencji
      (osobno: w archiwum, poza zakresem danych operatora — bez pokazywania nazwy)
- [x] 21 kluczy `payer.registry.*` w **pięciu** plikach językowych (1299 kluczy w każdym)
- [x] 9 testów w `tests/Integration/PayerServiceTest.php`: trafienie, nieczynny VAT, brak firmy, firma
      w ewidencji, firma poza zakresem danych, zły NIP (bez pytania rejestru), awaria rejestru,
      limit zapytań, brak uprawnienia (EMPLOYEE) — **czekają na uruchomienie MySQL**

## Krok 3 — cache i limit zapytań do rejestru ✅ ZROBIONE (2026-10-23)

- [x] `CachedCompanyRegistry` (dekorator `CompanyRegistry`): odpowiedzi w `storage/cache/registry` na 7 dni,
      „nie ma takiego NIP-u” też zapamiętane, błędy rejestru **nie** (awaria ma prawo minąć),
      zapytanie z konkretną datą omija pamięć
- [x] licznik zapytań na konto (`LookupThrottle`, 20/godzinę, pliki w `storage/cache/lookups`) — ochrona
      przed limitem MF i przed pętlą; brak katalogu do zapisu nie blokuje funkcji
- [x] `IntakeConfig::registry()` zwraca dekorator; przełącznik `registry.cache_days` w `config/intake.php` (0 wyłącza)
- [x] 11 testów jednostkowych (`CachedCompanyRegistryTest`, `LookupThrottleTest`): drugie zapytanie z pamięci,
      zapamiętany brak firmy, błąd nie trafia do pamięci, wygaśnięcie wpisu, uszkodzony plik, osobne
      liczniki kont, reset okna

## Krok 4 — kolejne otwarte źródła ✅ CZĘŚCIOWO (2026-10-23)

- [x] `CompositeCompanyRegistry` — pyta tylko te źródła, które obsługują dany numer (`supports()`
      w interfejsie `CompanyRegistry`); awaria jednego nie przerywa łańcucha, a gdy nikt nie
      odpowiedział, wraca ostatni błąd (nie „brak firmy”)
- [x] **VIES** (`ec.europa.eu/taxation_customs/vies/rest-api`, bez klucza) — firmy z UE po numerze
      VAT z kodem kraju; osobnego pola „kraj” nie trzeba, bo `Validator::isValidTaxId` już przyjmuje
      numery unijne, a `supports()` kieruje polski NIP do Białej listy. Sprawdzone na żywo:
      IE6388047V (Google Ireland) wraca z nazwą i adresem, DE811907980 jest ważny, ale Niemcy nie
      ujawniają danych („---” → puste pola), DE000000000 nieważny
- [ ] **KRS** (`api-krs.ms.gov.pl`, bez klucza) — **nie jest źródłem po NIP-ie**: API przyjmuje tylko
      numer KRS, więc nie pasuje do `CompanyRegistry`. Sens ma jako **uzupełnienie** po tym, jak Biała
      lista zwróci KRS (forma prawna, reprezentacja) — osobny interfejs, osobne zadanie
- [ ] **CEIDG v3** — wymaga darmowego tokenu z wnioskiem do ministerstwa; **blokada po stronie autora**:
      bez tokenu nie da się ani wdrożyć, ani przetestować na żywo
- [ ] **GUS BIR/REGON** — wymaga darmowego klucza z rejestracji w GUS; **blokada po stronie autora**
- [x] w `config/intake.php`: `registry.sources` (kolejność pytania, domyślnie `[whitelist, vies]`),
      `registry.vies_base_url`; klucze przyszłych źródeł trafią do `intake.local.php`
- [x] 18 testów: `ViesRegistryTest` (obsługiwane numery, prawdziwa odpowiedź VIES, nieujawnione dane,
      awarie, limit, adres w kilku układach) i `CompositeCompanyRegistryTest` (kierowanie, awaria
      jednego źródła, numer bez obsługi)

## Krok 5 — rejestr w imporcie CSV/XML ✅ ZROBIONE (2026-10-23)

- [x] w `ImportService`: gdy wiersz płatnika ma NIP, a komórka z nazwą lub adresem jest pusta,
      dane idą z rejestru. **Uzupełniamy też w podglądzie** — inaczej podgląd pokazywałby błędy
      walidacji, których przy zapisie nie będzie, a zasada projektu mówi, że podgląd i zapis nie mogą
      się rozjeżdżać; dzięki pamięci pośredniej z kroku 3 zapis nie pyta rejestru drugi raz.
      Danych z pliku **nigdy nie nadpisujemy** (plik to deklaracja operatora), awaria rejestru nie
      przerywa importu, a jeden plik to najwyżej 100 zapytań (`ImportService::MAX_REGISTRY_LOOKUPS`)
- [x] w podglądzie importu pod szczegółami wiersza: „Uzupełnione z rejestru: …” z nazwami pól
      (klucz `exchange.preview.from_registry` w pięciu językach)
- [x] 5 testów w `tests/Integration/ImportServiceTest.php`: uzupełnienie luk, brak nadpisywania danych
      z pliku, awaria rejestru nie zatrzymuje importu, nieznany NIP zostawia wiersz zwykłej walidacji,
      kompletny wiersz wcale nie pyta rejestru

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
