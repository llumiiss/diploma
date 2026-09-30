# Mapa projektu — CertiSub Assistant (praca inżynierska)

> Mapa robocza dla agentów i autora. Utworzona 2026-09-16 na podstawie: oficjalnego opisu pracy (Załącznik A), `CLAUDE.md`, `docs/AGENT_HANDOFF.md`, `docs/thesis_part1.md`, treści obu PDF-ów (źródło: `docs/generate_thesis_pdfs.py`), przeglądu całego kodu, bazy danych i wyników testów.
>
> **Hierarchia źródeł:** 1) oficjalny opis pracy → 2) ustalenia z promotorem → 3) ta mapa → 4) `CLAUDE.md` / `docs/AGENT_HANDOFF.md` (roadmapa nieaktualna względem 1) → 5) `docs/thesis_part1.md` (nieaktualny).

Legenda: ✅ zrobione · 🟡 częściowo · ❌ brak · ⚠️ błąd lub ryzyko

---

## 0. Najważniejsze wnioski

1. ✅ **Kod realizuje inny produkt niż opis pracy.** Opis: system ewidencji certyfikatów (np. kwalifikowanych) z użytkownikami certyfikatów, płatnikami, listą zadań, zaproszeniami e-mail, importem/eksportem i raportami. Kod (stan wyjściowy): tracker subskrypcji firmowych (SSL, SaaS, domeny) i prywatnych (Netflix, Spotify). Zamknięte w Etapach 1–8: ewidencja odpowiada opisowi pracy, a część prywatna została wyprowadzona do osobnej aplikacji (§11, Etap 8).
2. **Pokrycie 19 wymagań funkcjonalnych po Etapie 5: 19 kompletnych** (po Etapie 4: 16 / 1 / 2; po Etapie 3: 12 / 5 / 2; po Etapie 2: 7 / 10 / 2; po Etapie 1: 0 / 14 / 5; na starcie 0 / 8 / 11; macierz w §3). Pozostają wymagania niefunkcjonalne: lokalne zasoby frontendu i limit OTP w bazie (N3, N5) oraz scenariusze E2E (N6) — Etap 6.
3. **Cykl życia odnowienia z §2.1 działa od początku do końca (Etapy 2–3):** ewidencja → skaner zakłada zadanie ToDo → zaproszenie e-mail z szablonu i załącznikami → przypomnienia → odnowienie (nowy certyfikat, stary do archiwum) albo porzucenie → statystyki zadań. Każdy krok zapisuje zdarzenie w historii.
4. **`CLAUDE.md` jest spójny z kodem, ale nie z opisem pracy.** Roadmapa pomija m.in. import/eksport, szablony i załączniki, rejestr zaproszeń, archiwizację, zadania ze statusami i statystykami, historię na osi czasu oraz raporty perspektyw (§6).
5. **Harmonogram jest przesunięty:** CRUD (lipiec), RBAC (sierpień), cron + UX (1–15.09) nie są w kodzie. Freeze 15.10.2026 jest nierealny przy pełnym zakresie (§7).
6. **Błędy krytyczne z §5.1 zostały naprawione w Etapie 0 (2026-09-16)** — dziennik zmian w §11. Były to: skrypt z `scripts/` kasujący dane bez logowania, publiczny dostęp do `.git/` i logów, niedziałające wylogowanie, brak kontroli dostępu do danych oraz kasowanie rekordów biznesowych przy usuwaniu konta.
7. **Mocne strony do ponownego użycia (§4):** logowanie OTP z testami, CSRF, migracje, mailer, layout panelu, narzędzia jakości.
8. **Dodatkowe wymagania autora (§2.5):** skeleton loadery i cache'owanie — wykonane w Etapie 7.
9. **Decyzje D1–D5 i D7 podjęte 2026-09-16 (§8):** panel prywatny zamrożony (zmienione 2026-09-18 na wydzielenie do osobnej aplikacji), perspektywy jako raporty dla personelu, rejestracja tylko przez ADMIN, nowe teksty PL+EN z domyślnym PL, `subscriptions` → `certificates`, ogólny model typów certyfikatów. Otwarty zostaje D6 (harmonogram).
10. **Etap 1 (model danych) wykonany 2026-09-16 (§11):** certyfikaty z typami wg D7, użytkownicy certyfikatów, rozszerzeni płatnicy, archiwizacja, zadania ToDo ze statusami, szablony, załączniki, zaproszenia i historia zdarzeń. Struktura po migracji jest identyczna ze świeżą instalacją.
11. **Etap 2 (ewidencja, archiwizacja, RBAC) wykonany 2026-09-17 (§11):** warstwa usług i API z kontrolą ról, nowy panel firmowy (Vue bez kroku budowania, `assets/js`), archiwum, konta i role, rejestracja wyłączona (D3), zakres danych operatora (D8), testy integracyjne na osobnej bazie testowej.
12. **Etap 3 (proces odnowień) wykonany 2026-09-17 (§11):** skaner i cron `cron/renewals.php`, lista ToDo ze statusami, przydziałem i odnowieniem certyfikatu, szablony i załączniki, wysyłka zaproszeń z rejestrem i przypomnieniami, ustawienia progów, statystyki zadań na pulpicie.
13. **Etap 4 (raporty i przegląd) wykonany 2026-09-17 (§11):** karta użytkownika certyfikatu i karta płatnika (perspektywy z opisu pracy) z datami odnowienia, ścieżką realizacji, zaproszeniami i osią czasu; harmonogram wygaśnięć; wyszukiwarka globalna z powiązaniami; dziennik zdarzeń administratora.
14. **Etap 7 (UX i wydajność) wykonany 2026-09-17 (§11):** szkielety treści zamiast pustych ekranów (przed zamontowaniem Vue, w tabelach, kafelkach pulpitu, panelach i formularzach), cache agregatów pulpitu i statystyk zadań z kluczem roli i właściciela oraz unieważnianiem przy każdym zapisie, nagłówki pamięci podręcznej dla zasobów z wersją w adresie. **Plan z §7 jest wykonany w całości.**
15. **Etap 6 (jakość i domknięcie) wykonany 2026-09-17 (§11):** frontend bez CDN (Tailwind budowany lokalnie, Vue i krój Inter w repozytorium), limit wysyłek kodów OTP liczony w bazie, strona główna bez zmyślonych liczb, koszt roczny liczony wg okresu rozliczeniowego, scenariusz E2E przez API.
15. **Etap 5 (wymiana danych) wykonany 2026-09-17 (§11):** eksport list, kart i dziennika do CSV i XML, import płatników, osób i certyfikatów z CSV/XML z podglądem każdego wiersza przed zapisem oraz import wiadomości e-mail (EML) — własny parser MIME, rozpoznanie nadawcy, certyfikatów i załączników.

---

## 1. Mapa systemu — stan dziś vs cel

```
CertiSub Assistant (docelowo)
├── Dostęp
│   ├── ✅ Logowanie OTP (e-mail), sesje, CSRF, whitelist przekierowań
│   ├── ✅ Wylogowanie (naprawione w Etapie 0)
│   ├── ✅ Pliki wewnętrzne i skrypty CLI zablokowane przez HTTP (Etap 0)
│   ├── ✅ Hierarchia ról — macierz uprawnień w Rbac egzekwowana w API, zakres danych operatora (D8, Etap 2)
│   └── ✅ Konta zakłada ADMIN, publiczna rejestracja wyłączona, wyłączanie kont (D3, Etap 2)
├── Ewidencja  (✅ Etap 2)
│   ├── ✅ Certyfikaty / usługi — formularz z walidacją, szczegóły z historią, łańcuch odnowień
│   ├── ✅ Użytkownicy certyfikatów (beneficjenci) — lista, formularz, szczegóły z certyfikatami
│   ├── ✅ Płatnicy — lista z kosztami, formularz z kontrolą NIP, szczegóły z osobami i certyfikatami
│   └── ✅ Archiwizacja — archiwizacja zamiast usuwania, ekran archiwum z przywracaniem
├── Proces odnowień  (✅ Etap 3)
│   ├── ✅ Skaner (cron/renewals.php i przycisk w panelu) — margines z certyfikatu albo z ustawień, priorytety rosną wraz z datą
│   ├── ✅ Lista ToDo — statusy, przydział (MANAGER), porzucenie z powodem, odnowienie certyfikatu
│   ├── ✅ Szablony wiadomości + załączniki — ekran ADMIN-a z podglądem, biblioteka plików z kontrolą typu
│   ├── ✅ Zaproszenia + przypomnienia — wysyłka z podglądem, rejestr, ponowienie, automatyczne przypomnienia wg ustawień
│   └── ✅ Statystyki zadań — wg statusów, priorytetów i osób (pulpit)
├── Przegląd i raporty  (✅ Etap 4)
│   ├── ✅ Pulpit KPI (statusy, płatności, priorytety) i statystyki zadań (Etap 3)
│   ├── ✅ Karta użytkownika certyfikatu — daty odnowienia, certyfikaty z archiwum, zadania, zaproszenia, oś czasu
│   ├── ✅ Karta płatnika — powiązane osoby, harmonogram wygaśnięć, koszt roczny, zadania, zaproszenia, oś czasu
│   ├── 🟡 Panel administratora — konta i role, szablony, ustawienia, dziennik zdarzeń; import w Etapie 5
│   ├── ✅ Wyszukiwarka globalna — certyfikaty, osoby i płatnicy z powiązaniami i powodem dopasowania (Ctrl+K)
│   └── ✅ Oś czasu — historia rekordu z filtrem rodzaju zdarzeń i podziałem na dni; harmonogram wygaśnięć
├── Wymiana danych  (✅ Etap 5)
│   ├── ✅ Eksport CSV / XML — listy, karty raportowe, harmonogram i dziennik zdarzeń; wzory plików importu
│   └── ✅ Import CSV / XML / EML — podgląd wiersz po wierszu, tryb „pomiń” albo „aktualizuj”, wiadomość e-mail z załącznikami
└── Dodatki spoza opisu pracy
    ├── ✅ i18n: PL, EN, ES, DE, UK
    └── ✅ Strona główna (landing)

Subskrypcje prywatne (Netflix, Spotify…) nie są już częścią tej aplikacji — od 2026-09-18
stanowią osobny program „Menedżer Subskrypcji” (katalog `menedzer_subskrypcji`, własna baza).
```

---

## 2. Wizja docelowa

### 2.1. Główny przepływ (cykl życia odnowienia)

```
[Ewidencja]      certyfikat + użytkownik certyfikatu + płatnik
                 (formularz lub import CSV / XML / EML)
     │
     ▼
[Skaner]         cron codziennie: certyfikaty w marginesie odnowienia
     │
     ▼
[ToDo]           zadanie z priorytetem wg zakresu dat, status „do zrobienia”
     │
     ▼
[Zaproszenie]    e-mail z szablonu + załączniki → beneficjent / płatnik
     │
     ▼
[Przypomnienia]  kolejne wysyłki wg reguł, rejestr każdej wysyłki
     │
     ▼
[Wynik]          odnowiono (nowy certyfikat, stary → archiwum) | porzucono
     │
     ▼
[Przegląd]       historia na osi czasu · statystyki zadań · raporty perspektyw · eksport
```

Każdy krok zapisuje zdarzenie w historii (`events`) — z niej powstaje oś czasu i ścieżka realizacji.

### 2.2. Model danych (docelowy)

| Encja (tabela) | Najważniejsze pola | Stan po Etapie 1 |
|---|---|---|
| Konta personelu (`users`) | imię, nazwisko, e-mail, rola | ✅ opiekunowie rekordów (`certificates.user_id`) i przydział zadań |
| Użytkownicy certyfikatów (`beneficiaries`) | imię, nazwisko, e-mail, telefon, `payer_id`, notatki, `archived_at` | ✅ |
| Płatnicy (`payers`) | nazwa, osoba kontaktowa, NIP (unikalny), e-mail, telefon, adres, kod pocztowy, miasto, `archived_at` | ✅ |
| Certyfikaty (`certificates`, dawniej `subscriptions`) | `certificate_type` (D7), numer seryjny (unikalny u wystawcy), wystawca, ważny od / do, `renewal_lead_days`, `user_id`, `beneficiary_id`, `payer_id`, `previous_certificate_id`, koszt i płatność, `archived_at` | ✅ |
| Zadania ToDo (`renewal_tasks`) | `certificate_id`, przypisane konto, status (todo / in_progress / done / abandoned), priorytet, termin, wynik, data zamknięcia; co najwyżej jedno otwarte zadanie na certyfikat | ✅ |
| Szablony (`email_templates`) | kod, język, nazwa, temat, treść HTML i tekstowa z polami `{imie}`, `{nazwisko}`, `{typ_certyfikatu}`, `{numer_seryjny}`, `{data_waznosci}`, `{dni_do_wygasniecia}`, `{platnik}` | ✅ 4 domyślne (zaproszenie i przypomnienie, PL i EN) |
| Załączniki (`attachments` + `email_template_attachments`) | nazwa oryginalna, nazwa pliku w `storage/attachments`, typ MIME, rozmiar, SHA-256 | ✅ |
| Zaproszenia (`invitations` + `invitation_attachments`) | certyfikat, zadanie, szablon, odbiorca (użytkownik certyfikatu lub płatnik), treść z chwili wysyłki, status (queued / sent / failed / responded / closed), przypomnienia, błąd | ✅ |
| Historia (`events`) | typ i id encji, typ zdarzenia, konto, kontekst (certyfikat, użytkownik, płatnik), dane JSON, czas — celowo bez kluczy obcych | ✅ |
| `login_otps`, `schema_migrations` | — | ✅ |

Tabele są gotowe w bazie; ekrany i logika dla nich powstają w Etapach 2–5.

Relacje: płatnik 1–N użytkownik certyfikatu · użytkownik 1–N certyfikat · płatnik 1–N certyfikat · certyfikat 1–N zadanie · zadanie 1–N zaproszenie · szablon N–N załącznik · wszystkie encje → `events`.

`payer_id` zostaje także przy certyfikacie: domyślnie przepisywany z płatnika beneficjenta, ale konkretny certyfikat może opłacać inny podmiot (np. po zmianie pracodawcy).

### 2.3. Role i perspektywy (zatwierdzone 2026-09-16 — D2, D3)

| Rola | Zakres |
|---|---|
| ADMIN | pełny dostęp: konta i role, szablony i załączniki, progi odnowień, import, archiwum, historia zdarzeń |
| MANAGER | wszystko, co OPERATOR, + przydzielanie zadań, statystyki, raporty, eksport, archiwizacja |
| OPERATOR | ewidencja certyfikatów, osób i płatników; zadania ToDo; zaproszenia i przypomnienia |

Perspektywy z opisu jako raporty w panelu:

- **Użytkownik** — karta osoby: certyfikaty, daty odnowienia, szczegóły, historia.
- **Płatnik** — karta płatnika: certyfikaty, daty wygaśnięcia, historia, powiązane osoby.
- **Administrator** — zarządzanie systemem niezależnie od perspektyw.

Opcjonalnie (kierunek rozwoju): logowanie OTP dla użytkowników certyfikatów i płatników z dostępem tylko do własnej karty.

### 2.4. Ekrany docelowe

1. Logowanie OTP (bez publicznej rejestracji)
2. Pulpit: KPI, priorytety, statystyki zadań wg statusów
3. ToDo: zadania z priorytetami, zmiana statusu, przydział, „wyślij zaproszenie”
4. Certyfikaty: lista, filtry, dodaj/edytuj, archiwizuj, szczegóły z osią czasu
5. Użytkownicy certyfikatów: lista, dodaj/edytuj, karta (perspektywa Użytkownika)
6. Płatnicy: lista, dodaj/edytuj, karta (perspektywa Płatnika)
7. Zaproszenia: rejestr wysyłek, statusy, przypomnienia, ponowna wysyłka
8. Szablony i załączniki (ADMIN)
9. Import / eksport (podgląd i walidacja przed zapisem)
10. Wyszukiwarka globalna z powiązaniami
11. Archiwum
12. Administracja: konta, role, progi odnowień, historia zdarzeń

### 2.5. Wymagania dodatkowe autora (poza opisem pracy)

**Skeleton loadery** — ✅ wykonane w Etapie 7:

- szkielet układu (menu, pasek wyszukiwania, kafelki, tabela) jest w HTML strony i znika dopiero po zamontowaniu Vue — nie ma pustego ekranu ani `[v-cloak]`;
- wiersze tabel (`TableState` → `SkeletonRows`) mają tyle kolumn co docelowa tabela, więc treść nie przeskakuje; kafelki KPI, panel statystyk, panele szczegółów, karty raportowe i pola formularzy (`FormSkeleton`) mają własne szkielety;
- realizacja bez bibliotek: klasa `.skeleton` z animacją shimmer w `assets/css/app.src.css`, wyłączaną przy `prefers-reduced-motion`; szkielety są oznaczone `aria-hidden`/`role="status"`, więc czytnik ekranu czyta „Ładowanie…”, a nie puste pola.

**Cache'owanie** — trzy warstwy w kolejności wdrożenia:

1. ✅ **Agregaty KPI i statystyki** (`getStatusStats`, `getPaymentSummary`, `getRenewalSummary`, statystyki zadań) — `App\Cache`: pliki JSON w `storage/cache`, TTL 5 minut, klucz z rolą i właścicielem rekordów, unieważnianie przez wersję podbijaną w `EventLogger` przy każdym zapisie (Etap 7).
2. ✅ **Zasoby statyczne** — `Cache-Control: public, max-age=31536000, immutable` dla plików z wersją w adresie (`?v=czas zmiany`) i `no-store` dla stron oraz API z danymi osobowymi (`.htaccess`, Etap 7).
3. **Drobny cache aplikacyjny** — słowniki i18n i konfiguracja (pliki PHP obsługuje już opcache), raporty per beneficjent i płatnik na czas sesji — świadomie pominięte: raporty czyta jedna osoba naraz, a korzyść byłaby mniejsza niż ryzyko pokazania nieaktualnej historii.

⚠️ Zasada bezpieczeństwa: klucz cache'a musi zawierać rolę i właściciela rekordów. Wspólny cache agregatów bez tego klucza pokazałby OPERATOROWI dane całej organizacji, czyli cofnąłby naprawę z Etapu 0.

---

## 3. Macierz wymagań (opis pracy → stan → dowód → co zrobić)

### 3.1. Funkcjonalne

| ID | Wymaganie z opisu | Stan | Dowód w kodzie | Do zrobienia |
|---|---|---|---|---|
| F1 | Dane certyfikatu: nr seryjny, ważność, wygaśnięcie, wymagania odnowienia | ✅ | formularz i API (`classes/Service/CertificateService.php`): numer seryjny unikalny u wystawcy, daty sprawdzane kalendarzem i kolejnością, wymagany czas odnowienia (Etap 2) | — |
| F2 | Użytkownicy certyfikatu (dane osobowe beneficjentów) | ✅ | ekran „Użytkownicy certyfikatów” z formularzem i szczegółami (`classes/Service/BeneficiaryService.php`, `assets/js/views/beneficiaries.js`) | — |
| F3 | Płatnicy (dane płatnika) | ✅ | ekran „Płatnicy” z kontrolą NIP (suma kontrolna, unikalność) i kosztami (`classes/Service/PayerService.php`) | — |
| F4 | Beneficjent powiązany z płatnikiem | ✅ | powiązania wybierane w formularzach, płatnik podpowiadany z danych osoby, szczegóły pokazują osoby i certyfikaty płatnika (Etap 2); karta płatnika pokazuje także osoby, których certyfikaty płatnik opłaca (Etap 4) | — |
| F5 | Dodawanie, edycja, archiwizacja | ✅ | API `create/update/archive/restore` dla trzech encji, blokada archiwizacji rekordów z aktywnymi powiązaniami, ekran „Archiwum” (Etap 2) | — |
| F6 | Perspektywa Użytkownika: daty odnowienia, szczegóły, historia | ✅ | karta użytkownika certyfikatu `#/beneficiaries/N`: certyfikaty z datą „odnowienie od” i priorytetem, historia certyfikatów z łańcuchem odnowień, zadania, zaproszenia, oś czasu z filtrem; wydruk oraz eksport karty do CSV i XML (`App\Service\ReportService::beneficiaryCard`, `assets/js/views/reports.js`, Etapy 4–5) | — |
| F7 | Perspektywa Płatnika: certyfikaty, wygaśnięcia, historia, osoby | ✅ | karta płatnika `#/payers/N`: powiązane osoby, harmonogram wygaśnięć na 12 miesięcy, certyfikaty z kosztem rocznym, archiwum, zadania, zaproszenia, oś czasu; harmonogram dla całej organizacji w „Raportach”, karty i harmonogram do pobrania w CSV i XML (`ReportService::payerCard`, `ReportService::schedule`, Etapy 4–5) | — |
| F8 | Perspektywa Administratora | ✅ | „Konta i role” (Etap 2); „Szablony i załączniki” oraz „Ustawienia” procesu odnowień (Etap 3); „Dziennik zdarzeń” z filtrami i stronicowaniem (Etap 4); „Import i eksport” z podglądem importu i importem wiadomości EML (Etap 5) | — |
| F9 | Eksport CSV / XML | ✅ | `App\Service\ExportService` + `App\Exchange\CsvWriter`/`XmlExporter`: certyfikaty, osoby, płatnicy (także archiwum), zadania, zaproszenia, dziennik zdarzeń, karty raportowe i harmonogram; CSV z etykietami i wartościami w języku interfejsu (UTF-8 z BOM, średnik, ochrona przed formułami), XML z nazwami pól i kodami; każdy eksport zapisuje zdarzenie (Etap 5) | — |
| F10 | Import CSV / XML / EML | ✅ | `App\Service\ImportService`: płatnicy, osoby i certyfikaty z CSV (UTF-8 i Windows-1250, średnik/przecinek/tabulator) oraz XML; nagłówki po polsku i angielsku, daty i wartości słownikowe w zapisie z arkusza; podgląd wykonuje zapis w transakcji i wycofuje ją, więc pokazuje dokładny wynik; `App\Service\EmlImportService` + `App\Exchange\EmlParser` (własny parser MIME, bez rozszerzenia `mailparse`): nadawca, certyfikaty po numerach seryjnych, płatnicy po NIP-ie, odpowiedzi na zaproszenia i załączniki CSV/XML (Etap 5) | — |
| F11 | Regularne skanowanie i margines odnowienia | ✅ | `App\Service\RenewalScanner` + `cron/renewals.php`: certyfikaty w marginesie (`renewal_lead_days` albo próg z ustawień) dostają zadanie, priorytet rośnie wraz ze zbliżaniem się daty; bez duplikatów przy ponownym uruchomieniu (Etap 3) | — |
| F12 | Lista ToDo z priorytetami wg zakresów dat | ✅ | ekran „Lista ToDo”: priorytety wygasłe / krytyczne / do odnowienia, statusy, przydział, porzucenie z powodem, odnowienie certyfikatu (`App\Service\TaskService`, Etap 3) | — |
| F13 | Zaproszenia e-mail z szablonem i załącznikami | ✅ | okno wysyłki z podglądem, szablony z polami `{imie}`, `{numer_seryjny}`… w PL i EN, załączniki z biblioteki (`App\Service\InvitationService`, `TemplateRenderer`, `Mailer` z `addAttachment`, Etap 3) | — |
| F14 | Zarządzanie wysłanymi zaproszeniami (przypomnienia) | ✅ | rejestr „Zaproszenia”: statusy, ponowienie nieudanej wysyłki, ręczne i automatyczne przypomnienia wg odstępu i limitu z ustawień, oznaczenie odpowiedzi, zamknięcie; zamknięcie zadania kończy przypomnienia (Etap 3) | — |
| F15 | Dostęp oparty o konta użytkowników | ✅ | OTP, sesje, CSRF, wylogowanie (Etap 0); konta zakłada ADMIN, `register.php` przekierowuje do logowania, wyłączone konto nie zaloguje się, a jego sesja kończy się przy następnym żądaniu (Etap 2) | limit wysyłek OTP w bazie zamiast sesji (§5 pkt 9, Etap 6) |
| F16 | Wyszukiwanie usług, osób, płatników i powiązań | ✅ | wyszukiwarka globalna w górnym pasku (Ctrl+K) i widok pełnych wyników: dopasowanie po polach rekordu i przez powiązania (płatnik → jego certyfikaty i osoby, opiekun → certyfikaty), NIP i telefon bez separatorów, powód dopasowania przy każdym wyniku, zakres danych D8 (`App\Service\SearchService`, `assets/js/views/search.js`, Etap 4) | — |
| F17 | Ścieżka realizacji i powiązania na osi czasu | ✅ | zdarzenia ewidencji, kont i procesu odnowień (Etapy 2–3); oś czasu osoby i płatnika z filtrem rodzaju zdarzeń, podziałem na dni i odnośnikami do certyfikatów, bez zdarzeń certyfikatów spoza zakresu operatora; ścieżka realizacji (wszystkie zadania) i łańcuch odnowień w kartach; dziennik zdarzeń administratora (Etap 4) | — |
| F18 | Statystyki zadań wg statusów | ✅ | panel statystyk na pulpicie i liczniki na liście ToDo: statusy z udziałem procentowym, otwarte wg priorytetu, po terminie, skuteczność, średni czas realizacji, rozkład wg osób (`TaskService::stats`, Etap 3) | — |
| F19 | Hierarchiczny plan kont z rolami | ✅ | macierz uprawnień `Rbac::can()` sprawdzana w każdej usłudze, zakres danych operatora (D8, `App\Service\Visibility`), ekran „Konta i role”, ochrona ostatniego administratora (Etap 2) | — |

### 3.2. Niefunkcjonalne i techniczne

| ID | Wymaganie | Stan | Uwagi |
|---|---|---|---|
| N1 | PHP + HTML + JavaScript | ✅ | PHP 8.3, Vue 3, Tailwind |
| N2 | Baza zgodna z MySQL (LAMP), standardowy hosting | ✅ | MySQL 8.4, PDO, prepared statements |
| N3 | Instalacja publiczna lub intranetowa | ✅ | katalogi wewnętrzne zablokowane w `.htaccess` (Etap 0); frontend bez CDN (Etap 6): Tailwind budowany do `assets/css/app.css` (`npm run css`), Vue 3.5.13 w `assets/vendor`, krój Inter w `assets/fonts` — aplikacja działa w sieci bez internetu; do produkcji zostaje `display_errors=Off` w php.ini serwera |
| N4 | Studium wykonalności (punkt wyjścia: Java + Spring) | 🟡 | tylko jedno zdanie o Spring Boot (`docs/thesis_part1.md:74`) — potrzebne porównanie z kryteriami |
| N5 | Ochrona danych osobowych | 🟡 | PDO, CSRF, hash OTP, regeneracja sesji, zakres danych wg roli (D8), historia zmian w `events`, walidacja wejścia; limit wysyłek kodów liczony w bazie per adres i per IP (Etap 6); eksport danych osobowych zapisuje zdarzenie (Etap 5); do zrobienia: punkt o RODO w części pisemnej |
| N6 | Testy | ✅ | 156 testów: 75 jednostkowych (logowanie i limit kodów, role i uprawnienia, walidacja, jądro API, szablony, tłumaczenia, logika certyfikatów i kosztów, harmonogram, poczta, formaty CSV/XML, parser EML) + 79 integracyjnych na osobnej bazie `assistent_subscriptions_test` (migracje, ewidencja, konta, skaner, zadania, zaproszenia z przypomnieniami, szablony, załączniki, ustawienia, raporty, wyszukiwarka, dziennik zdarzeń, eksport, import CSV/XML i EML), w tym scenariusz E2E przez warstwę API: ewidencja → skaner → zaproszenie → odnowienie → karta → eksport → import → dziennik (`tests/Integration/EndToEndFlowTest.php`, Etap 6) oraz cache agregatów z kontrolą zakresu ról (Etap 7) |

---

## 4. Co już jest i nadaje się do ponownego użycia

| Element | Plik | Jak wykorzystać |
|---|---|---|
| Logowanie OTP (TTL 10 min, 5 prób, hash kodów, bez ujawniania istnienia kont) + testy | `classes/AuthManager.php` | bez zmian; dodać zakładanie kont przez ADMIN |
| CSRF dla formularzy i API | `classes/Csrf.php` | wszystkie nowe endpointy |
| Jądro API (metoda → CSRF → auth → obsługa → błąd z kodem HTTP) i kontrolery | `classes/Http/ApiKernel.php`, `classes/Api/*Controller.php` (Etap 2) | każdy nowy endpoint to kontroler + jednolinijkowy plik w `api/` |
| Usługi domenowe z kontrolą ról, walidacją i historią | `classes/Service/*Service.php`, `Validator`, `EventLogger`, `Visibility` (Etap 2) | zadania, zaproszenia, raporty, import i eksport korzystają z tych samych reguł |
| Raporty, wyszukiwarka i dziennik zdarzeń | `ReportService` (karty, harmonogram), `SearchService`, `TimelineService::journal` (Etap 4) | eksport kart i list korzysta z tych samych zapytań i zakresu danych (Etap 5) |
| Formaty wymiany danych | `App\Exchange`: `CsvReader`/`CsvWriter`, `XmlRecordReader`/`XmlExporter`, `EmlParser`, `Columns` (nazwy kolumn i aliasy), `Normalizer` (daty, wartości logiczne, słowniki) — Etap 5 | kolejne zbiory danych wystarczy opisać w `Columns`; parser EML nadaje się też do skrzynki odbiorczej (kierunek rozwoju) |
| Komponenty panelu (okna, panel szczegółów, pola formularzy, oś czasu, powiadomienia) | `assets/js/core.js`, `assets/js/components.js` (Etap 2) | nowe widoki w `assets/js/views/` |
| Testy integracyjne na osobnej bazie | `tests/Support/MysqlTestDatabase.php`, `IntegrationTestCase.php` (Etap 2) | testy usług bez ryzyka dla bazy użytkownika |
| Idempotentne migracje | `classes/MigrationRunner.php`, `classes/Migrations/` | każda zmiana schematu jako nowa migracja; `SchemaInspector` sprawdza stan przed każdym krokiem |
| Progi i priorytety | `classes/CertificateHelper.php` | priorytety zadań ToDo |
| Wysyłka e-mail (PHPMailer) | `classes/Mailer.php` | dodać załączniki i szablony |
| Szkielet crona z logowaniem | `cron/send_reminders.php` | przebudować na skaner certyfikatów |
| Layout panelu, karty KPI, tabele, modale | `includes/dashboard_app.php` | nowe widoki |
| i18n z fallbackiem do EN | `classes/Translator.php` | nowe klucze |
| PHPUnit, PHPStan (poziom 5), PHP-CS-Fixer | `composer.json` | bramka jakości |
| Dane demonstracyjne | `scripts/seed-demo-data.php` | wypełnia bazę pod demo, testy ręczne i zrzuty ekranu do pracy; `--force` odtwarza od zera |
| Instrukcja obsługi | `docs/INSTRUKCJA_OBSLUGI.md` | opis paneli, scenariusz demonstracji, rozwiązywanie problemów; baza rozdziału P5 |

---

## 5. Co wymaga poprawek w istniejącym kodzie

Numeracja ciągła — odwołania w innych miejscach: „§5 pkt N”.

### 5.1. ✅ Krytyczne — naprawione w Etapie 0 (2026-09-16)

> Wszystkie pięć pozycji jest naprawionych i zweryfikowanych. Opisy zostawiam jako historię i uzasadnienie zmian; co dokładnie zrobiono — §11.

1. **Kasowanie danych bez logowania.** Katalog projektu jest katalogiem publicznym serwera, a jedyny `.htaccess` leży w `cron/`. `scripts/cleanup-demo-data.php` nie sprawdza `PHP_SAPI` i wykonuje `DELETE FROM subscriptions` oraz `DELETE FROM payers` (`scripts/cleanup-demo-data.php:21-22`) po zwykłym wejściu na adres. `scripts/migrate.php` przez WWW uruchomi zaległe migracje (bez `--fresh`, bo `register_argc_argv=Off`). *Wniosek z kodu i konfiguracji — skryptu celowo nie uruchamiano.*
   → blokada `PHP_SAPI !== 'cli'` w każdym skrypcie + katalog `public/` jako katalog główny serwera albo `Require all denied` dla `scripts/`, `classes/`, `config/`, `database/`, `logs/`, `tests/`, `docs/`, `vendor/`, `.git/`.
2. **Publiczne pliki wewnętrzne** (sprawdzone przez HTTP, kod 200): `.git/config` i `.git/HEAD` (da się odtworzyć całe repozytorium z historią), `logs/reminders.log` (cron zapisuje tam adresy e-mail odbiorców; przy włączonym `dev_log_codes` także `logs/otp.log` z kodami OTP), `database/schema.sql`, `composer.lock`, `docs/`. `config/mail.local.php` nigdy nie trafił do gita, więc hasła pocztowe tą drogą nie wyciekają.
3. **Wylogowanie nie działa.** `logout.php:12` wywołuje `$auth->logout()`, ale `$auth` nie istnieje. Efekt: fatal error (zapisany w `C:\laragon\tmp\php_errors.log`), sesja nie jest niszczona. PHPStan tego nie wykrył, bo `phpstan.neon` nie obejmuje plików z katalogu głównego.
4. **Każdy może się zarejestrować i zobaczyć wszystkie dane.** `register.php` jest publiczny, a `includes/dashboard_app.php:69-74` ładuje wszystkie subskrypcje, osoby (z e-mailami) i płatników bez sprawdzania roli.
5. **Usunięcie konta kasuje rekordy biznesowe.** `UserManager::deleteAccountCompletely()` usuwa wszystkie subskrypcje osoby (`classes/UserManager.php:123`). To sprzeczne z wymaganiem archiwizacji i z opisanym w pracy `ON DELETE RESTRICT`.

### 5.2. Średnie

6. ✅ **Cron gubi przypomnienia** (`cron/send_reminders.php`): warunek „dokładnie 3 dni”, brak rejestru wysyłek, treść wpisana w kodzie. W ewidencji certyfikatów rozwiązane w Etapie 3 nowym `cron/renewals.php` (margines zamiast dokładnej daty, rejestr w `invitations`, szablony z tłumaczeniami). Stary skrypt zniknął razem z panelem prywatnym w Etapie 8 — nowa aplikacja ma własny `cron/reminders.php` z ustawianym wyprzedzeniem i podglądem `--dry-run`.
7. **`cron/.htaccess`** ma niepełną dyrektywę `Require all` (bez `denied`). Apache zwraca 500 zamiast 403, czyli blokuje dostęp przypadkiem. Poprawnie: `Require all denied`.
8. ✅ **Listy osób i płatników ukrywały rekordy bez subskrypcji** (`HAVING subscription_count > 0`). Naprawione w Etapie 2: panel korzysta z nowych usług, które pokazują wszystkie aktywne rekordy; stare zapytania (`UserManager::getAllUsers`, `PayerManager`) usunięte w Etapie 8 razem z panelem prywatnym, który był ich ostatnim odbiorcą.
9. ✅ **Limit wysyłek kodów OTP trzymany w sesji** — naprawione w Etapie 6: limit liczy historię w tabeli `login_otps` (3 kody na adres e-mail i 10 na adres IP w 15 minutach, kolumna `request_ip` z migracji `otp_rate_limit`), więc usunięcie ciasteczka nic nie daje.
10. ✅ **Walidacja daty tylko wyrażeniem regularnym** — naprawione w Etapie 2: `App\Service\Validator::date()` sprawdza datę kalendarzem; endpoint menedżera osobistego korzysta z tej samej walidacji.
11. ✅ **Zależności z CDN** (N3) — naprawione w Etapie 6: Tailwind budowany poleceniem `npm run css` do `assets/css/app.css`, Vue w `assets/vendor`, krój Inter w `assets/fonts`; strony nie odwołują się już do `cdn.tailwindcss.com`, `unpkg.com` ani Google Fonts.

### 5.3. Drobne i porządki

12. ✅ Model danych: `annual_cost` przechowuje kwotę za okres rozliczeniowy. Wszystkie sumy liczą teraz koszt roczny tym samym przelicznikiem (miesięczny × 12, wieloletni ÷ liczba lat ważności): `CertificateHelper::annualizedCost` w PHP i `CertificateHelper::annualizedCostSql` w zapytaniach pulpitu oraz list płatników (Etapy 4–6). Zmiana nazwy kolumny na `period_cost` zostaje jako kierunek rozwoju. Rozdzielenie kont i użytkowników certyfikatów zrobione w Etapie 1; `manager_subskrypcji` z polskimi nazwami kolumn zniknęła z tej bazy w Etapie 8 (dane przeniesione do osobnej aplikacji).
13. ✅ Oś czasu: „Następna płatność” bez daty — zastąpiona szczegółami certyfikatu z historią zdarzeń (Etap 2). Zmyślone liczby na stronie głównej zastąpione w Etapie 6 opisem trzech funkcji systemu (skaner, zaproszenia, raporty i eksport).
14. ✅ Domyślny język interfejsu — od Etapu 1 polski (`Translator::DEFAULT_LOCALE`), angielski jako drugi (D4).
15. ✅ JSON w `<script>` bez `JSON_HEX_TAG` — panel firmowy przekazuje dane startowe jednym obiektem z flagami `JSON_HEX_*`, a odpowiedzi API też je stosują (Etap 2).
16. ✅ `composer-setup.php` (instalator Composera) usunięty z repozytorium w Etapie 6. Katalog `.claude/` nie jest śledzony, `node_modules/` dopisane do `.gitignore`.
17. ✅ Nieaktualna dokumentacja: `docs/thesis_part1.md` i `docs/AGENT_HANDOFF.md` mają od Etapu 6 nagłówek „Dokument historyczny” z odesłaniem do mapy; README i `CLAUDE.md` są aktualizowane co etap.
18. PHPStan: 1 znana uwaga (`cron/send_reminders.php:120`) — nie blokuje. Uwaga z `MigrationRunner.php:92` zniknęła w Etapie 1, bo metody sprawdzające schemat są poprawnie oznaczone jako nieczyste (`@phpstan-impure`).
19. ✅ PHP-CS-Fixer zgłaszał wszystkie pliki, bo `@PSR12` wymaga końców linii LF, a kopia robocza na Windowsie miała CRLF. Naprawione w Etapie 6 osobnymi commitami: `.gitattributes` z `* text=auto eol=lf` (pliki binarne oznaczone), ponowne pobranie plików do kopii roboczej i `composer cs-fix` na pozostałych 11 plikach (kolejność importów, apostrofy, przecinki). `composer cs` kończy się teraz bez uwag.
21. ✅ Klasa `AddSubscriptionApiTest` nigdy się nie uruchamiała (PHPUnit wykrywa tylko klasę o nazwie pliku, a endpoint kończył się `exit`). Naprawione w Etapie 2: wspólne jądro `App\Http\ApiKernel` bez `exit` i kontrolery w `classes/Api/`; testy w `tests/Unit/ApiKernelTest.php`.

### 5.4. ✅ Wykryte i naprawione po Etapie 0 (2026-09-16)

20. **Różne strefy czasowe PHP i MySQL.** PHP działał w `UTC` (php.ini Laragona), a MySQL w strefie systemowej — o 01:36 czasu lokalnego PHP widział jeszcze poprzedni dzień. Skutek: `days_left` i priorytety liczone w PHP (`classes/SubscriptionHelper.php:36-62`) rozjeżdżały się o jeden dzień z licznikami KPI i cronem opartymi na `CURDATE()`, więc przypomnienie „za 3 dni” nigdy nie trafiało w swoją datę. Wykryte przy przygotowaniu danych demonstracyjnych. Naprawa: `bootstrap.php` ustawia `Europe/Warsaw`, a `scripts/seed-demo-data.php` liczy wszystkie daty od `CURDATE()` z bazy.

---

## 6. Porównanie celów: `CLAUDE.md` / `AGENT_HANDOFF.md` vs opis pracy

| Aspekt | Opis pracy | `CLAUDE.md` / `AGENT_HANDOFF.md` | Zgodność |
|---|---|---|---|
| Dziedzina | certyfikaty (np. kwalifikowane), użytkownicy certyfikatów, płatnicy | certyfikaty kwalifikowane, SSL, SaaS, domeny, płatności | ✅ zgodne po Etapie 8 (część prywatna wyprowadzona do osobnej aplikacji) |
| Kto wprowadza dane | zarządzający (personel) | każdy zarejestrowany użytkownik, dla siebie | ❌ |
| Konta i role | hierarchiczny plan kont z rolami | samorejestracja; RBAC dopiero na 4. miejscu roadmapy | 🟡 |
| Dodawanie, edycja, archiwizacja | wymagane | CRUD w roadmapie, archiwizacji brak | 🟡 |
| Import / eksport CSV, XML, EML | wymagane | wymienione tylko jako brak, **nie ma w roadmapie** | ❌ |
| ToDo z priorytetami | wymagane | widok-filtr; trwałych zadań brak w roadmapie | 🟡 |
| Zaproszenia z szablonem i załącznikami + zarządzanie nimi | wymagane | „rozszerzenie crona”, szablony „opcjonalnie”; załączników i rejestru brak | ❌ |
| Oś czasu ścieżki realizacji | wymagane | statyczna, brak w roadmapie | ❌ |
| Statystyki zadań wg statusów | wymagane | brak | ❌ |
| Raporty perspektyw (Użytkownik, Płatnik, Administrator) | wymagane | brak | ❌ |
| Wyszukiwanie z powiązaniami | wymagane | filtr w przeglądarce, brak w roadmapie | 🟡 |
| Technologia | PHP + HTML + JS, MySQL | PHP 8 + Vue + Tailwind, MySQL | ✅ |
| Studium wykonalności (Java/Spring) | wymagane w pracy | brak (dotyczy części pisemnej) | 🟡 |
| Część pisemna: dokumentacja użytkownika | wymagana | brak w spisie treści szkicu | ❌ |

**Wniosek:** roadmapa z `CLAUDE.md` (5 punktów) obejmowała ok. 5 z 19 wymagań funkcjonalnych (F1, F3, F5 bez archiwizacji, F11, F19). Jej punkt 2 (edycja i usuwanie w prywatnym menedżerze) był poza opisem pracy — trafił do osobnej aplikacji w Etapie 8. Konwencje kodu z `CLAUDE.md` (strict types, `final`, PDO, CSRF, i18n, testy) są dobre i zostają. Do zmiany jest sekcja „What this is” i roadmapa — po decyzjach z §8.

---

## 7. Plan dojścia do celu

Stan na 16.09.2026. Pierwotnie: freeze 15.10.2026, część pisemna 16.10–31.12.2026. Czas to szacunek w dniach roboczych.

| Etap | Zakres | Dni |
|---|---|---|
| 0. Naprawy krytyczne ✅ **wykonane 2026-09-16** | wszystko z §5.1; `cron/.htaccess`; konto ADMIN; PHPStan także dla plików z katalogu głównego (szczegóły w §11) | — |
| 1. Model danych ✅ **wykonane 2026-09-16** | migracje: `subscriptions` → `certificates` z typami wg D7, beneficjenci, rozszerzenie płatników, `archived_at`, `renewal_tasks`, `email_templates`, `attachments`, `invitations`, `events`; język domyślny PL (D4); dane demonstracyjne (szczegóły w §11) | — |
| 2. Ewidencja + archiwizacja + RBAC ✅ **wykonane 2026-09-17** | API i formularze dla certyfikatów, beneficjentów, płatników; archiwum; role w API i UI; wyłączenie publicznej rejestracji i zakładanie kont przez ADMIN (D3); zakres danych operatora (D8); zapis zdarzeń; etykiety „certyfikaty” w panelu (szczegóły w §11) | — |
| 3. Proces odnowień ✅ **wykonane 2026-09-17** | skaner → zadania ToDo (priorytety, statusy, przydział); szablony + załączniki; zaproszenia; rejestr i przypomnienia; statystyki zadań; ustawienia progów (szczegóły w §11) | — |
| 4. Raporty i przegląd ✅ **wykonane 2026-09-17** | karta beneficjenta, karta płatnika, oś czasu z `events`, wyszukiwarka globalna, dziennik zdarzeń administratora, harmonogram wygaśnięć (szczegóły w §11) | — |
| 5. Wymiana danych ✅ **wykonane 2026-09-17** | eksport CSV/XML (listy, karty, harmonogram, dziennik); import CSV/XML z podglądem i trybem aktualizacji; import EML z załącznikami (szczegóły w §11) | — |
| 6. Jakość i domknięcie ✅ **wykonane 2026-09-17** | frontend bez CDN (Tailwind budowany lokalnie, Vue i Inter w repozytorium), limit kodów OTP w bazie, strona główna bez zmyślonych liczb, jednolity koszt roczny, scenariusz E2E przez API, porządki w repozytorium i dokumentacji (szczegóły w §11) | — |
| 7. UX i wydajność (§2.5) ✅ **wykonane 2026-09-17** | szkielety treści w panelu; cache agregatów KPI z kluczem roli i unieważnianiem przy zapisie; nagłówki pamięci podręcznej dla zasobów (szczegóły w §11) | — |
| 8. Rozdzielenie aplikacji ✅ **wykonane 2026-09-18** | panel prywatny wyprowadzony do osobnego programu z własną bazą; z ewidencji firmowej znika `certificates.scope`, `manager_subskrypcji`, typy subskrypcji prywatnych i wszystkie odnośniki między aplikacjami (szczegóły w §11) | — |

**Plan z tej tabeli jest wykonany w całości (Etapy 0–8).** Pozostaje część pisemna (§9) i ustalenie terminu obrony z promotorem (decyzja D6), więc realny freeze mieści się w październiku 2026 (do uzgodnienia z promotorem). Rozdziały 1–3 pracy (wstęp, charakterystyka problemu, analiza rozwiązań, studium wykonalności) można pisać od razu — nie zależą od kodu.

Kolejność etapów = kolejność ważności. Gdy zabraknie czasu, najpierw upraszczać etap 5 (np. import EML ograniczony do jednego formatu wiadomości).

---

## 8. Decyzje (podjęte 2026-09-16) i ich konsekwencje

| ID | Decyzja | Wybór | Co z tego wynika |
|---|---|---|---|
| D1 | Panel prywatny (`dashboard-personal.php`, `manager_subskrypcji`, typy STREAMING, MUSIC…) | **b) zamrożony jako dodatek** (2026-09-16) → **c) osobna aplikacja** (2026-09-18) | Etapy 1–7: kod bez zmian, nowe funkcje wyłącznie dla części certyfikatowej. Etap 8: panel prywatny stał się samodzielnym programem (`menedzer_subskrypcji`) z własną bazą, kontami i interfejsem, a ewidencja firmowa straciła kolumnę `scope`, tabelę `manager_subskrypcji` i typy subskrypcji prywatnych. W pracy: obie aplikacje opisane osobno, rozdzielenie jako decyzja projektowa |
| D2 | Perspektywy Użytkownik / Płatnik | **a) raporty w panelu dla personelu** | ✅ Etap 4: karta użytkownika certyfikatu i karta płatnika jako widoki (`#/beneficiaries/N`, `#/payers/N`) z wydrukiem; osobne logowanie dla beneficjentów i płatników → rozdział „kierunki rozwoju” |
| D3 | Rejestracja | **a) wyłączona, konta zakłada ADMIN** | `register.php` przestaje być publiczny; ADMIN tworzy konto (imię, nazwisko, e-mail, rola), użytkownik loguje się kodem OTP; potrzebny ekran „Konta” w panelu administracyjnym (Etap 2) |
| D4 | i18n dla nowych funkcji | **b) PL + EN** | nowe klucze tylko w `lang/pl.php` i `lang/en.php` (ES/DE/UK dziedziczą EN dzięki fallbackowi w `Translator`); ✅ język domyślny PL (`Translator::DEFAULT_LOCALE`, Etap 1) |
| D5 | Nazwa głównej encji | **a) `subscriptions` → `certificates`** | ✅ Etap 1: tabela, kolumna `certificate_type`, klasy `CertificateManager` i `CertificateHelper`. Zmianę kluczy i18n przeniesiono do Etapu 2 — etykiety panelu zmienią się razem z nowym UI |
| D6 | Harmonogram | **otwarte — do ustalenia z promotorem** | plan §7 zakłada freeze w połowie listopada 2026 |
| D7 | Rodzaje certyfikatów | **b) ogólny model z typem** | ✅ Etap 1: `certificates.certificate_type` — QUALIFIED_SIGNATURE, QUALIFIED_SEAL, SSL_CERTIFICATE, CODE_SIGNING, DOMAIN, SAAS, CLOUD_SUPPORT, OTHER (typy subskrypcji prywatnych usunięte w Etapie 8) oraz pola wspólne: numer seryjny, wystawca, ważny od/do, wymagany czas odnowienia; w pracy przykłady na certyfikatach kwalifikowanych |
| D8 | Zakres danych OPERATORA (przyjęte w Etapie 2, 2026-09-17 — do potwierdzenia z promotorem) | **zasada najmniejszych uprawnień** | OPERATOR widzi certyfikaty, których jest opiekunem, i te z przydzielonym mu zadaniem odnowienia; osoby i płatników, których sam wprowadził (`created_by_user_id`) albo którzy są powiązani z jego certyfikatami. MANAGER i ADMIN widzą całą organizację. Duplikat NIP-u zgłaszany operatorowi bez ujawniania cudzego płatnika. Archiwizacja i archiwum od roli MANAGER. Konta się nie usuwa — ADMIN je wyłącza; system zawsze zachowuje aktywnego administratora |

---

## 9. Część pisemna — wymagania z opisu vs szkic

Szkic: `docs/Szkic pracy Litosh.pdf` (treść w `docs/generate_thesis_pdfs.py`).

| Wymagany element | Szkic | Stan | Uwagi |
|---|---|---|---|
| Wstęp | rozdz. 1 | ✅ | przepisane 2026-09-30 pod certyfikaty (beneficjent/płatnik); segment prywatny opisany jako pierwotna koncepcja wydzielona decyzją D1 |
| Ogólna charakterystyka problemu | 1.1–1.4 | ✅ | j.w. |
| Analiza istniejących rozwiązań | rozdz. 2 | ✅ | 2026-09-30: tabela porównawcza zastąpiona narzędziami Cert. LCM (Keyfactor Command, DigiCert Trust Lifecycle Manager, Sectigo) i polskimi centrami certyfikacji (Certum, KIR Szafir, EuroCert) |
| Studium wykonalności (Java/Spring vs PHP) | 2.5 (nowy podrozdział) | ✅ | dodane 2026-09-30: kryteria — dostępność na hostingu, koszt, złożoność uruchomienia, kompetencje autora; wniosek PHP/LAMP |
| Założenia projektowe (rozdz. 3) | 3.1–3.5 | ✅ | napisane 2026-09-30: cel i zakres (z wyjaśnieniem D1), F1–F19 jako wymagania funkcjonalne, N1–N6 jako niefunkcjonalne, role ADMIN/MANAGER/OPERATOR z D8, architektura warstwowa i proces odnowień; logowanie opisane jako hasło + weryfikacja e-mail (stan kodu po Etapie 9), nie OTP |
| Projekt bazy danych (rozdz. 4) | 4.1–4.4 | ✅ | napisane 2026-09-30: model pojęciowy (11 encji), miejsce na diagram ERD (`figure_placeholder`, autor wkleja sam), struktura tabel `users/payers/beneficiaries/certificates/renewal_tasks/email_templates/attachments/invitations/events` z `database/schema.sql`, integralność (PDO, FK RESTRICT/SET NULL/CASCADE, archiwizacja, unikalne klucze, hashowanie haseł i tokenów, limity w bazie, migracje idempotentne) |
| Projekt i implementacja aplikacji webowej (rozdz. 5) | brak | ❌ | do napisania: technologie, backend PHP, frontend Vue/Tailwind, dashboard i formularze, logowanie hasłem + e-mail; `docs/thesis_part1.md` rozdz. 2 nieaktualny |
| **Dokumentacja użytkownika** | brak w spisie treści | 🟡 | szkic powstał jako `docs/INSTRUKCJA_OBSLUGI.md` (2026-09-16) — do rozdziału dodać zrzuty ekranu i podział na role |
| Opis testów | rozdz. 7 | ❌ | są tylko tytuły |
| Zakończenie, podsumowanie, kierunki rozwoju | rozdz. 8 | ❌ | w spisie są bibliografia i spisy, brak podsumowania i kierunków rozwoju; tu wymienić aplikację prywatną „Menedżer Subskrypcji” jako powiązany projekt |

Zalecane (nie wprost w opisie): krótki punkt o RODO — system przechowuje dane osobowe beneficjentów.

---

## 10. Stan środowiska (zweryfikowany 2026-09-18)

- **Testy:** 187 — wszystkie zaliczone z `RUN_INTEGRATION_TESTS=1` (bez flagi 106 zaliczonych + 81 pominiętych), w tym scenariusz E2E przez warstwę API i testy cache. Testy integracyjne tworzą od zera osobną bazę `assistent_subscriptions_test` (schema.sql + migracje) i nie dotykają bazy aplikacji; wysyłkę poczty zastępuje w nich rejestrujący zamiennik. Przed Etapem 0 było 11 testów.
- **PHPStan (poziom 5):** bez uwag — jedyna znana uwaga dotyczyła `cron/send_reminders.php`, usuniętego razem z panelem prywatnym (Etap 8); analizowane są także pliki wejściowe z katalogu głównego.
- **PHP-CS-Fixer:** `composer cs` bez uwag (0 ze 135 plików) po normalizacji końców linii i poprawkach stylu w Etapie 6 (§5 pkt 19).
- **Baza `assistent_subscriptions`:** 14 tabel, migracje `login_otp`, `certificates_model`, `accounts_and_ownership`, `renewal_process`, `otp_rate_limit`, `split_personal_app`. Struktura po migracji identyczna ze świeżą instalacją z `database/schema.sql` (porównanie `information_schema`: 130 kolumn, 58 pozycji indeksów, 20 kluczy obcych).
- **Frontend bez internetu (Etap 6):** żadna strona nie odwołuje się do CDN — `assets/css/app.css` (Tailwind zbudowany lokalnie), `assets/vendor/vue.global.prod.js`, `assets/fonts/inter-*.woff2`; sprawdzone w przeglądarce (lista żądań tylko do `localhost`) i przez HTTP (kody 200, poprawne typy MIME).
- **Pamięć podręczna (Etap 7):** `assets/**` → `Cache-Control: public, max-age=31536000, immutable`, strony i API → `no-store` (sprawdzone przez HTTP); agregaty pulpitu i statystyki zadań liczone raz na 5 minut na rolę i konto, unieważniane przy każdym zapisie.
- **Cron odnowień:** `php cron/renewals.php` na danych demo — 6 certyfikatów w marginesie, 1 nowe zadanie (Microsoft 365), 0 przypomnień do wysłania; wynik w `logs/renewals.log`.
- **Dane demo (`scripts/seed-demo-data.php`, sprawdzone 2026-09-18 na osobnej bazie):** 3 płatników (NovaTech z poprawnym NIP-em), 5 użytkowników certyfikatów, 2 aktywne i 1 wyłączone konto personelu demo, 12 certyfikatów (10 aktywnych, 2 w archiwum, 1 łańcuch odnowień), 7 zadań (todo 3, in_progress 2, done 1, abandoned 1), 4 zaproszenia, 4 szablony, 1 załącznik, 48 zdarzeń. `scripts/cleanup-demo-data.php` usuwa komplet.
- **Panel (sprawdzony w przeglądarce):** ADMIN — 10 certyfikatów, 3 płatników, archiwum (2 certyfikaty), konta; OPERATOR (Tomasz Wróbel) — 4 certyfikaty, 2 osoby, 2 płatników, a API zwraca 403 dla archiwum, kont, archiwizacji i zapisu bez tokenu CSRF. Panel renderuje się bez ostrzeżeń PHP.
- **Strefa czasowa:** PHP i MySQL liczą w `Europe/Warsaw` (§5.4); cron znajduje zaplanowaną płatność.
- **HTTP:** `/`, `/login.php`, `assets/js/*` → 200; `/register.php` → 302 na `login.php?registration_closed=1`; pulpit → 302 do logowania; `api/*.php` bez sesji → 401; katalogi wewnętrzne (w tym `storage/`, `classes/`, `config/`, `tests/`), `.git/` → 403; `dashboard-personal.php` i `api/add_subscription.php` → 404 (Etap 8).
- **Raporty i wyszukiwarka (sprawdzone w przeglądarce):** ADMIN — karta Jana Kowalskiego (oś czasu 7 zdarzeń z filtrem), karta NovaTech (2 osoby, 4 bieżące certyfikaty, harmonogram, koszt roczny 1819 PLN), harmonogram organizacji (10 certyfikatów), wyszukiwanie „nova” (5 certyfikatów, 2 osoby, 1 płatnik), dziennik 57 zdarzeń; OPERATOR (Tomasz Wróbel) — karta NovaTech w swoim zakresie (1 osoba, 2 certyfikaty), 403 dla dziennika zdarzeń. Odpowiedzi API kart i wyszukiwarki poniżej 50 ms.
- **Wymiana danych (sprawdzona w przeglądarce):** ADMIN — eksport płatników przez HTTP (nagłówki `Content-Disposition`, CSV z BOM i średnikiem), podgląd importu pliku z trzema wierszami (istniejący płatnik → pominięty, poprawny NIP → nowy, błędny NIP → błąd przy polu, nierozpoznana kolumna oznaczona), analiza wiadomości EML na danych demo (nadawca → Jan Kowalski, certyfikat po numerze seryjnym, płatnik po NIP-ie, otwarte zaproszenie, załącznik CSV rozpoznany jako „Użytkownicy certyfikatów”) i podgląd importu tego załącznika. Po podglądach baza pozostała bez zmian (4 płatników, 58 zdarzeń) — podgląd wycofuje transakcję.
- **Git:** Etap 0 = `f9ec9df`, Etap 1 = `cef8e72`, Etap 2 = `6ba9729`, Etap 3 = `1578adf`, Etap 4 = `a0b0b2f`, Etap 5 = `a26fb16`, Etap 6 = `7764780`, Etap 7 = `fbc2237`, Etap 8 — gałąź `etap-8-rozdzielenie-aplikacji` scalona na `master`; remote `origin` = github.com/llumiiss/diploma (nic nie jest wypychane automatycznie).
- **Aplikacja prywatna:** `C:\laragon\www\menedzer_subskrypcji`, własne repozytorium git (pierwszy commit 2026-09-18), własna baza `menedzer_subskrypcji`, 44 testy, PHPStan bez uwag. Obie aplikacje nie mają wspólnych tabel ani odnośników.

---

## 11. Dziennik zmian

### Etap 0 — naprawy krytyczne (2026-09-16)

| Problem (§5.1) | Co zrobiono | Pliki |
|---|---|---|
| 1. Skrypty CLI wykonywalne przez HTTP | blokada `PHP_SAPI !== 'cli'` w każdym skrypcie; w `send_reminders.php` przeniesiona przed `require`, żeby nic się nie inicjowało | `scripts/cleanup-demo-data.php`, `scripts/migrate.php`, `scripts/test-mail.php`, `cron/send_reminders.php` |
| 2. Publiczne pliki wewnętrzne | nowy `.htaccess` w katalogu głównym: katalogi wewnętrzne, pliki ukryte (`.git`) i metadane projektu zwracają 403, plus zapasowy `FilesMatch` na wypadek wyłączonego `mod_rewrite`; poprawiona składnia `cron/.htaccess` (`Require all denied`) | `.htaccess`, `cron/.htaccess` |
| 3. Wylogowanie nie działało | dodane brakujące `new AuthManager()` | `logout.php` |
| 4. Każdy zalogowany widział wszystkie dane | nowa klasa `Rbac` (ADMIN > MANAGER > OPERATOR); zapytania przyjmują `?int $ownerId` i filtrują rekordy; katalog osób i płatników tylko dla MANAGER/ADMIN — również ukryty w nawigacji | `classes/Rbac.php`, `classes/SubscriptionManager.php`, `includes/dashboard_app.php` |
| 5. Usunięcie konta kasowało rekordy biznesowe | konto nie jest usuwane, gdy ma przypisane subskrypcje lub certyfikaty; API zwraca 409 i komunikat `auth.error.delete_blocked` | `classes/UserManager.php`, `classes/AuthManager.php`, `api/delete_account.php`, `lang/pl.php`, `lang/en.php` |
| konto ADMIN | nowy skrypt CLI `scripts/set-role.php` (`--list` oraz `<e-mail> <rola>`); rola ADMIN nadana `mobi.litosh@gmail.com` | `scripts/set-role.php`, `classes/UserManager.php` |
| PHPStan nie widział plików wejściowych | do `paths` dodane `bootstrap.php`, `index.php`, `login.php`, `logout.php`, `register.php`, `dashboard*.php` | `phpstan.neon` |
| testy | +6 testów: `RbacTest` (hierarchia ról) oraz dwa scenariusze usuwania konta; baza testowa SQLite dostała tabelę `subscriptions` | `tests/Unit/RbacTest.php`, `tests/Unit/AuthManagerTest.php`, `tests/Support/SqliteTestDatabase.php` |

Weryfikacja: 16/17 testów zaliczonych (1 integracyjny pominięty), PHPStan bez nowych uwag, `php -l` czysty dla wszystkich zmienionych plików, matryca kodów HTTP i filtrowanie wg roli sprawdzone na działającym serwerze.

**Świadomie nie zrobione w Etapie 0:** publiczna rejestracja nadal działa (decyzja D3), `composer-setup.php` nadal w repozytorium (§5 pkt 16), `composer cs-fix` nieuruchomiony (§5 pkt 19).

Commit: `f9ec9df`, gałąź `etap-0-naprawy-krytyczne`, scalona na `master` 2026-09-16 (fast-forward).

### Po Etapie 0 — demo i instrukcja (2026-09-16)

| Co | Szczegóły | Pliki |
|---|---|---|
| Dane demonstracyjne | skrypt CLI wypełnia bazę: 4 płatników, 3 konta beneficjentów `@example.com`, 10 certyfikatów firmowych i 5 subskrypcji prywatnych rozłożonych na wszystkie priorytety i statusy płatności, 4 pozycje menedżera osobistego; `--force` odtwarza dane, `--owner=` wskazuje konto właściciela części rekordów | `scripts/seed-demo-data.php` |
| Strefa czasowa (nowy błąd — §5.4) | `bootstrap.php` ustawia `Europe/Warsaw`; seed liczy daty od `CURDATE()` z bazy | `bootstrap.php`, `scripts/seed-demo-data.php` |
| Instrukcja obsługi | wszystkie panele i funkcje, scenariusz demonstracji na 10 minut, rozwiązywanie problemów; zalążek rozdziału „dokumentacja użytkownika” (P5) | `docs/INSTRUKCJA_OBSLUGI.md` |

Weryfikacja: 16/17 testów, PHPStan bez nowych uwag, cron po naprawie znajduje 1 płatność na 19.09.2026.

### Etap 1 — model danych (2026-09-16)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Migracja | nowa migracja `certificates_model` (idempotentna, każdy krok sprawdza stan schematu): `subscriptions` → `certificates` z przemianowaniem kluczy obcych i indeksów, kolumna `certificate_type` z typami wg D7, nowe pola certyfikatu, rozszerzenie płatników, tabele `beneficiaries`, `renewal_tasks`, `email_templates`, `attachments`, `email_template_attachments`, `invitations`, `invitation_attachments`, `events`, 4 domyślne szablony PL/EN | `classes/Migrations/CertificatesModelMigration.php`, `classes/Migrations/SchemaInspector.php`, `classes/MigrationRunner.php` |
| Świeża instalacja | `schema.sql` przepisany na model Etapu 1 (struktura identyczna jak po migracji); `migrate.php --fresh` po imporcie uruchamia migracje i pomija fragmenty złożone z samych komentarzy | `database/schema.sql`, `scripts/migrate.php` |
| Integralność w bazie | co najwyżej jedno otwarte zadanie na certyfikat (kolumna generowana + indeks unikalny), numer seryjny unikalny u wystawcy, NIP płatnika unikalny, archiwizacja zamiast usuwania (`ON DELETE RESTRICT`), historia bez kluczy obcych | j.w. |
| Klasy domenowe (D5) | `SubscriptionManager` → `CertificateManager`, `SubscriptionHelper` → `CertificateHelper`; zapytania pomijają rekordy zarchiwizowane i zwracają dane użytkownika certyfikatu; `UserManager::countOwnedCertificates` | `classes/CertificateManager.php`, `classes/CertificateHelper.php`, `classes/UserManager.php`, `classes/PayerManager.php`, `classes/AuthManager.php`, `includes/dashboard_app.php` |
| Język (D4) | domyślny PL (`Translator::DEFAULT_LOCALE`), etykiety nowych typów w PL i EN, nowe typy w filtrze panelu | `classes/Translator.php`, `lang/pl.php`, `lang/en.php`, `includes/dashboard_app.php` |
| Załączniki | katalog `storage/attachments` zablokowany przez HTTP i ignorowany przez git | `.htaccess`, `storage/.htaccess`, `.gitignore` |
| Dane demo | seed pod nowy model: użytkownicy certyfikatów, archiwum z łańcuchem odnowień, zadania we wszystkich statusach, zaproszenia z treścią z szablonu i załącznikiem, 50 zdarzeń; konta personelu demo zamiast kont-beneficjentów | `scripts/seed-demo-data.php`, `scripts/cleanup-demo-data.php` |
| Testy | +11: `TranslatorTest` (5), `CertificateHelperTest` (5), integracyjny test modelu Etapu 1 | `tests/Unit/TranslatorTest.php`, `tests/Unit/CertificateHelperTest.php`, `tests/Integration/MigrationRunnerTest.php`, `tests/Support/SqliteTestDatabase.php` |

Weryfikacja: kopia bazy przed migracją (`mysqldump`); migracja na bazie użytkownika z zachowaniem danych i drugi przebieg bez zmian; świeża instalacja na bazie tymczasowej z logiką `--fresh` i porównanie struktur (identyczne: 131 kolumn, 56 indeksów, 18 kluczy obcych), baza tymczasowa usunięta; próby naruszenia ograniczeń odrzucone przez bazę (duplikat otwartego zadania, duplikat numeru seryjnego); 28/28 testów z integracją; PHPStan: 1 znana uwaga (było 2); render panelu z symulowaną sesją dla ADMIN i OPERATOR bez ostrzeżeń PHP; kody HTTP bez zmian, `storage/` → 403.

**Świadomie nie zrobione:** ekrany i API dla nowych tabel (Etapy 2–5), zmiana kluczy i18n i etykiet „subskrypcje” w panelu (Etap 2), wykorzystanie `renewal_lead_days` w wyliczaniu priorytetów (Etap 3).

Commit: `cef8e72`, gałąź `etap-1-model-danych`, scalona na `master` 2026-09-17 (fast-forward).

### Etap 2 — ewidencja, archiwizacja i role (2026-09-17)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Warstwa usług | usługi z walidacją, kontrolą ról i zapisem historii: certyfikaty, użytkownicy certyfikatów, płatnicy, konta; `Validator` (daty kalendarzowe, NIP z sumą kontrolną i VAT UE, liczby z przecinkiem), `EventLogger` (zmienione pola „z → na”), `Visibility` (zakres danych D8), `Transaction` | `classes/Service/*` |
| API | wspólne jądro `ApiKernel` (metoda → CSRF → sesja → obsługa → kod HTTP 400/401/403/404/409/422/500 z przetłumaczonym komunikatem i błędami pól) i kontrolery; endpointy `certificates`, `beneficiaries`, `payers`, `accounts`, `dashboard`; `add_subscription` przeniesiony na jądro | `classes/Http/*`, `classes/Api/*`, `api/*.php` |
| Uprawnienia (F19) | macierz `Rbac::can()` dla akcji Etapów 2–5; OPERATOR: własne i przydzielone certyfikaty, osoby i płatnicy wprowadzeni przez niego lub powiązani; archiwizacja i archiwum od MANAGER; konta tylko ADMIN; lista uprawnień przekazywana do interfejsu (tylko do ukrywania przycisków) | `classes/Rbac.php`, `classes/Service/Visibility.php`, `classes/CertificateManager.php` |
| Konta (D3, F15) | rejestracja wyłączona (`register.php` → logowanie z komunikatem); ADMIN zakłada konta, zmienia role, wyłącza i włącza konta, przekazuje certyfikaty i otwarte zadania; wyłączone konto nie dostaje kodu, a jego sesja kończy się przy następnym żądaniu; ochrona ostatniego aktywnego administratora | `classes/Service/AccountService.php`, `classes/AuthManager.php`, `classes/UserManager.php`, `register.php`, `login.php` |
| Migracja `accounts_and_ownership` | `users.deactivated_at`, `payers.created_by_user_id`, `beneficiaries.created_by_user_id` z kluczami obcymi `ON DELETE SET NULL`; `schema.sql` zaktualizowany | `classes/Migrations/AccountsAndOwnershipMigration.php`, `classes/MigrationRunner.php`, `database/schema.sql` |
| Reguły ewidencji (F1–F5) | numer seryjny unikalny u wystawcy (409 ze szczegółami tylko dla widzących rekord); NIP unikalny; płatnik podpowiadany z osoby; archiwizacja płatnika i osoby zablokowana, gdy mają aktywne powiązania; archiwizacja certyfikatu zamyka otwarte zadanie jako porzucone; przywrócenie wymaga aktywnego płatnika i osoby; rekord z archiwum jest tylko do odczytu | usługi j.w. |
| Panel firmowy | nowy interfejs: pulpit, lista ToDo, certyfikaty, użytkownicy certyfikatów, płatnicy, archiwum, konta i role; panele szczegółów z powiązaniami, łańcuchem odnowień i historią zdarzeń; formularze z błędami pól i szybkim dodaniem płatnika lub osoby; adresy widoków w `#`; etykiety „certyfikaty” (D5) | `includes/dashboard_app.php`, `assets/js/core.js`, `assets/js/components.js`, `assets/js/app.js`, `assets/js/views/*` |
| Panel prywatny (D1) | poprzedni widok przeniesiony bez rozwijania do osobnego pliku, uproszczony do jednego zakresu | `includes/personal_dashboard_app.php`, `dashboard-personal.php` |
| Język (D4) | ok. 250 nowych kluczy PL i EN (formularze, walidacja, błędy API, zdarzenia historii); tekst strony głównej o zakładaniu kont | `lang/pl.php`, `lang/en.php` |
| Testy (N6) | +42: jednostkowe (`ValidatorTest`, `ApiKernelTest`, uprawnienia w `RbacTest`, wyłączone konta w `AuthManagerTest`) i integracyjne usług na osobnej bazie `assistent_subscriptions_test` (`PayerServiceTest`, `BeneficiaryServiceTest`, `CertificateServiceTest`, `AccountServiceTest`, migracja Etapu 2) | `tests/*`, `tests/Support/MysqlTestDatabase.php`, `tests/Support/IntegrationTestCase.php` |
| Dane demo | poprawny NIP NovaTech; wyłączone konto demo (Adam Nowicki) i zdarzenia kont; czyszczenie zdarzeń kont demo przy `--force` | `scripts/seed-demo-data.php` |

Naprawione przy okazji: §5 pkt 8, 10, 15 i 21; częściowo pkt 11 (przypięta wersja Vue) i 13.

Weryfikacja: kopia bazy przed migracją (`mysqldump`); migracja na bazie użytkownika i drugi przebieg bez zmian; porównanie `information_schema` bazy po migracji ze świeżą instalacją (identyczne: 134 kolumny, 70 pozycji indeksów, 20 kluczy obcych); 70/70 testów z integracją; PHPStan: 1 znana uwaga; `node --check` dla wszystkich plików JS; w przeglądarce jako ADMIN: pulpit, lista i szczegóły certyfikatu z historią, walidacja formularza (data „ważny od” po wygaśnięciu), konflikt NIP z odnośnikiem do istniejącego płatnika, blokada archiwizacji płatnika z certyfikatami, archiwizacja i przywrócenie z archiwum, konta i role; jako OPERATOR: 4 certyfikaty, 2 osoby, 2 płatników i 403 dla akcji spoza roli; render obu paneli z CLI bez ostrzeżeń PHP; matryca kodów HTTP.

**Świadomie nie zrobione:** skaner, zadania ToDo jako osobny ekran, szablony, załączniki i wysyłka zaproszeń (Etap 3); karty raportowe z historią osoby i płatnika, wyszukiwarka globalna, dziennik zdarzeń (Etap 4); import i eksport (Etap 5); limit OTP w bazie, lokalne zasoby frontendu (Etap 6); skeleton loadery i cache (Etap 7).

Commit: `6ba9729`, gałąź `etap-2-ewidencja-rbac`, scalona na `master` 2026-09-17 (fast-forward).

### Etap 3 — proces odnowień (2026-09-17)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Skaner (F11) | certyfikaty firmowe w marginesie odnowienia (`renewal_lead_days` albo domyślny próg) dostają zadanie ToDo przypisane opiekunowi; priorytet: wygasłe / krytyczne (≤ próg krytyczny) / do odnowienia; przy kolejnych uruchomieniach aktualizacja priorytetu i terminu; zamknięte zadanie dla tej samej daty wygaśnięcia oznacza obsłużony cykl; cron i przycisk „Uruchom skaner” (MANAGER+) | `classes/Service/RenewalScanner.php`, `cron/renewals.php` |
| Zadania ToDo (F12) | lista z filtrami (status, przydział, priorytet, wyszukiwanie) i licznikami; przejścia statusów z kontrolą (porzucenie wymaga powodu, ponowne otwarcie tylko MANAGER); przydział tylko do aktywnych kont; status certyfikatu podąża za zadaniem („odnowienie w toku”); zamknięcie zadania kończy przypomnienia | `classes/Service/TaskService.php`, `assets/js/views/tasks.js` |
| Odnowienie (§2.1) | „Odnów certyfikat”: nowy certyfikat z tymi samymi powiązaniami i nową ważnością (`previous_certificate_id`), stary do archiwum, zadanie → zrobione; nowa data musi być późniejsza | `CertificateService::renewFrom`, `TaskService::renew` |
| Szablony i załączniki (F13) | ekran ADMIN-a: szablony PL/EN z polami (dodatkowo `{nazwa_certyfikatu}`, `{wystawca}`, `{opiekun}`, `{opiekun_email}`), podgląd na przykładowym certyfikacie, przypisane załączniki; biblioteka plików: rozszerzenie + typ z zawartości (finfo), limit 10 MB, losowa nazwa na dysku, SHA-256, pobieranie przez API, usuwanie tylko nieużywanych | `classes/Service/TemplateService.php`, `TemplateRenderer.php`, `AttachmentService.php`, `assets/js/views/templates.js` |
| Zaproszenia (F13, F14) | okno wysyłki: odbiorca (użytkownik certyfikatu albo płatnik), szablon, załączniki, podgląd w izolowanej ramce; wysyłka poza transakcją z zapisem statusu i błędu; brak otwartego zadania → zakładane automatycznie; rejestr z filtrem zaległych przypomnień; przypomnij, ponów, odpowiedziano, zamknij; automatyczne przypomnienia wg odstępu i limitu (szablon przypomnienia w języku zaproszenia, kolejna próba po nieudanej wysyłce następnego dnia) | `classes/Service/InvitationService.php`, `InvitationMailer.php`, `MailerInvitationMailer.php`, `classes/Mailer.php`, `assets/js/views/invitations.js` |
| Ustawienia | tabela `settings` (migracja `renewal_process`): domyślny margines, próg krytyczny, odstęp i limit przypomnień; walidacja zakresów i zależności progów; zmiany w historii; progi pulpitu korzystają z ustawień | `classes/Settings.php`, `classes/Service/SettingsService.php`, `classes/Migrations/RenewalProcessMigration.php`, `classes/CertificateHelper.php` |
| Statystyki (F18) | panel na pulpicie: statusy z udziałem procentowym, otwarte wg priorytetu, po terminie, moje, skuteczność, średni czas realizacji, rozkład wg osób (MANAGER+) | `TaskService::stats`, `assets/js/views/dashboard.js` |
| Poczta | załączniki w `Mailer::send`; sterownik `log` (wiadomości do `logs/mail.log`) dla demonstracji i instalacji bez SMTP | `classes/Mailer.php`, `classes/MailConfig.php`, `config/mail.php` |
| API | `api/tasks.php`, `invitations.php`, `templates.php`, `attachments.php` (pobieranie plików), `settings.php` | `classes/Api/*` |
| Panel | nawigacja: Lista ToDo (zadania), Zaproszenia, Szablony i załączniki, Ustawienia; w szczegółach certyfikatu sekcja „Odnowienie” (otwarte zadanie, utworzenie zadania, wysyłka, lista zaproszeń); oś czasu opisuje zdarzenia procesu | `assets/js/*`, `includes/dashboard_app.php` |
| Język (D4) | ok. 230 nowych kluczy PL i EN; etykiety typów w wiadomości w języku szablonu | `lang/pl.php`, `lang/en.php`, `classes/Translator.php` |
| Testy | +21: `TemplateRendererTest`, `RenewalScannerTest`, `TaskServiceTest`, `InvitationServiceTest` (z rejestrującym zamiennikiem poczty), `TemplateAttachmentSettingsTest` | `tests/*` |

Weryfikacja: kopia bazy przed migracją; migracja i drugi przebieg bez zmian; porównanie `information_schema` z świeżą instalacją (138 kolumn, 72 pozycje indeksów, 21 kluczy obcych); 91/91 testów z integracją; PHPStan: 1 znana uwaga; `node --check` wszystkich plików JS; w przeglądarce jako ADMIN: lista ToDo z licznikami, „Uruchom skaner” (1 nowe zadanie), panel zadania z akcjami i historią, okno wysyłki z podglądem wiadomości i załącznikiem (bez faktycznej wysyłki), rejestr zaproszeń z błędem SMTP i przypomnieniami, szablony z podglądem, ustawienia, statystyki na pulpicie, brak błędów w konsoli; `cron/renewals.php` na danych demo.

**Świadomie nie zrobione:** karty raportowe z historią osoby i płatnika, wyszukiwarka globalna, dziennik zdarzeń administratora (Etap 4); import i eksport (Etap 5); limit OTP w bazie, lokalne zasoby frontendu, strona główna bez zmyślonych liczb (Etap 6); skeleton loadery i cache (Etap 7). Stary `cron/send_reminders.php` menedżera osobistego zostaje bez zmian (D1).

Commit: `1578adf`, gałąź `etap-3-proces-odnowien`, scalona na `master` 2026-09-17 (fast-forward).

### Etap 4 — raporty i przegląd (2026-09-17)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Karta użytkownika certyfikatu (F6) | widok `#/beneficiaries/N`: dane osoby, wskaźniki (bieżące certyfikaty, najbliższe wygaśnięcie, wymagające uwagi, otwarte zadania, wysłane zaproszenia, ostatni kontakt), certyfikaty z datą „odnowienie od” (wygaśnięcie minus wymagany czas odnowienia albo domyślny margines), priorytetem, otwartym zadaniem i kontaktem, historia certyfikatów z łańcuchem odnowień (od MANAGER), ścieżka realizacji — wszystkie zadania, zaproszenia, oś czasu; wydruk | `classes/Service/ReportService.php`, `assets/js/views/reports.js` |
| Karta płatnika (F7) | widok `#/payers/N`: dane płatnika, wskaźniki z kosztem rocznym, powiązane osoby (przypisane do płatnika i te, których certyfikaty opłaca), harmonogram wygaśnięć na 12 miesięcy z wykresem, certyfikaty z kosztem, archiwum, zadania, zaproszenia, oś czasu; wydruk | j.w. |
| Raporty | widok „Raporty”: wejście do perspektyw Użytkownika, Płatnika i Administratora; harmonogram wygaśnięć dla zakresu konta (3–24 miesiące, filtr płatnika i typu, zaległe osobno, koszt roczny) | `ReportService::schedule`, `assets/js/views/reports.js` |
| Wyszukiwarka globalna (F16) | pole w górnym pasku (Ctrl+K lub „/”) z podpowiedziami i obsługą klawiatury; widok pełnych wyników; dopasowanie po polach rekordu i przez powiązania (osoba, płatnik, certyfikat, opiekun), NIP i telefon bez separatorów, znaki `%` i `_` traktowane dosłownie; powód dopasowania przy wyniku; dopasowania bezpośrednie wyżej; archiwum tylko od MANAGER | `classes/Service/SearchService.php`, `assets/js/views/search.js` |
| Dziennik zdarzeń (F8, F17) | widok ADMIN-a: wszystkie zdarzenia od najnowszych, filtry (obszar, zdarzenie z liczbą wystąpień, autor lub „System”, zakres dat włącznie, tekst w nazwach rekordów i treści zdarzenia), stronicowanie 25/50/100, odnośniki do certyfikatów, kart osób i płatników, zadań i zaproszeń | `TimelineService::journal`, `assets/js/views/events.js` |
| Oś czasu (F17) | wspólny opis zdarzeń dla osi czasu i dziennika; filtr rodzaju zdarzeń (ewidencja, zadania, zaproszenia, system) i podział na dni; w historii osoby i płatnika operator nie widzi zdarzeń certyfikatów spoza swojego zakresu (D8) | `TimelineService::forContext`, `assets/js/components.js` |
| Koszty (§5 pkt 12) | koszt w przeliczeniu na rok: miesięczny × 12, wieloletni ÷ liczba lat ważności | `CertificateHelper::annualizedCost`, `CertificateHelper::priorityFor` |
| API | `api/reports.php` (karty, harmonogram), `api/search.php`, `api/events.php` — tylko GET | `classes/Api/ReportsController.php`, `SearchController.php`, `EventsController.php` |
| Panel | nawigacja „Raporty” i „Dziennik zdarzeń”; karta dostępna z listy („Karta”) i z panelu szczegółów osoby i płatnika; adres karty w `#` z identyfikatorem; nowy widok zaczyna się od góry strony; styl wydruku bez nawigacji; brakujące odcienie palety `brand-300/400` | `assets/js/app.js`, `assets/js/core.js`, `includes/dashboard_app.php`, `includes/head.php` |
| Język (D4) | ok. 190 nowych kluczy PL i EN | `lang/pl.php`, `lang/en.php` |
| Testy | +23: `ReportServiceTest`, `SearchServiceTest`, `EventJournalTest`, `ReportScheduleTest`, priorytet i koszt roczny w `CertificateHelperTest`; poprawione `tearDown` dwóch testów z Etapu 3, które bez `RUN_INTEGRATION_TESTS` kończyły się błędem zamiast pominięciem | `tests/*` |

Weryfikacja: 114/114 testów z integracją (bez flagi: 53 zaliczone, 61 pominiętych); PHPStan: 1 znana uwaga; `node --check` wszystkich plików JS; kontrola kluczy tłumaczeń; w przeglądarce jako ADMIN i OPERATOR (§10): karty, harmonogram z wykresem, wyszukiwarka z klawiaturą i widok wyników, dziennik z filtrami, brak błędów w konsoli, brak poziomego przewijania przy szerokości 375 px; API operatora: 403 dla dziennika, 405 dla POST na wyszukiwarkę.

**Świadomie nie zrobione:** eksport kart i list do CSV/XML oraz import (Etap 5); skeleton loadery w pozostałych widokach i cache agregatów (Etap 7). Bez zmian w schemacie bazy — raporty korzystają z istniejących tabel i indeksów `events`.

Commit: `a0b0b2f`, gałąź `etap-4-raporty`, scalona na `master` 2026-09-17 (fast-forward).

### Etap 5 — wymiana danych (2026-09-17)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Eksport (F9) | zbiory: certyfikaty, użytkownicy certyfikatów, płatnicy (osobno archiwum), zadania, zaproszenia, dziennik zdarzeń (ADMIN, z filtrami ekranu), karty raportowe osoby i płatnika, harmonogram wygaśnięć; CSV dla arkusza (UTF-8 z BOM, średnik, CRLF, etykiety kolumn i wartości słownikowych w języku interfejsu, apostrof przed znakiem formuły — CSV injection), XML dla systemów (nazwy pól, kody, karty jako struktura zagnieżdżona); wzory plików importu to eksport samych nagłówków; każdy eksport z danymi zapisuje zdarzenie `data_exported` | `classes/Service/ExportService.php`, `classes/Exchange/CsvWriter.php`, `XmlExporter.php`, `api/export.php` |
| Import CSV/XML (F10) | płatnicy, osoby i certyfikaty; nagłówki rozpoznawane po nazwie pola, etykiecie polskiej lub angielskiej i popularnych nazwach z arkusza („NIP”, „Data wygaśnięcia”); CSV w UTF-8 albo Windows-1250, separator wykrywany (średnik, przecinek, tabulator), pola wielolinijkowe; XML z elementami rekordów (DOCTYPE i encje odrzucane — XXE); daty `DD.MM.RRRR`, kwoty z przecinkiem i „zł”, wartości logiczne „tak/nie”, typy i statusy kodem albo etykietą; powiązania po NIP-ie, nazwie płatnika, e-mailu osoby i koncie opiekuna; tryb „pomiń” albo „aktualizuj” (aktualizacja tylko kolumnami obecnymi w pliku) | `classes/Service/ImportService.php`, `classes/Exchange/CsvReader.php`, `XmlRecordReader.php`, `Columns.php`, `Normalizer.php`, `api/import.php` |
| Podgląd importu | podgląd wykonuje dokładnie ten sam zapis co import (usługi ewidencji: walidacja, unikalność, uprawnienia, historia) i wycofuje transakcję na końcu, więc wynik podglądu jest wiążący; każdy wiersz ma własny punkt zapisu (SAVEPOINT), więc błąd nie przerywa pozostałych; wynik wiersza: nowy, aktualizacja (z listą zmienionych pól), bez zmian, pominięty, błąd (komunikaty przy polach, duplikat w pliku ze wskazaniem wiersza) | j.w. |
| Import EML (F10) | własny parser MIME w czystym PHP (bez rozszerzenia `mailparse`): nagłówki łamane i kodowane (RFC 2047), base64 i quoted-printable, zestawy znaków, multipart zagnieżdżony, nazwy załączników RFC 2231, daty z błędną nazwą dnia tygodnia; analiza wiadomości: nadawca (dopasowany do osoby, płatnika albo propozycja nowej osoby z telefonem z treści), certyfikaty po numerach seryjnych, płatnicy po NIP-ie, otwarte zaproszenia do nadawcy, załączniki CSV/XML z rozpoznanym zbiorem danych; zastosowanie zaznaczonych działań w jednej transakcji: dodanie osoby, oznaczenie zaproszeń „z odpowiedzią”, wpis „Zarejestrowano wiadomość e-mail” na osi czasu certyfikatu, osoby albo płatnika | `classes/Service/EmlImportService.php`, `classes/Exchange/EmlParser.php`, `EmlMessage.php` |
| Panel | widok „Import i eksport” (MANAGER — eksport, ADMIN — import): kafelki eksportu, kreator importu (rodzaj danych, tryb, plik, tabela rozpoznanych kolumn, wzory plików), tabela podglądu z filtrem wierszy i podsumowaniem, panel analizy wiadomości EML z zaznaczaniem działań; przyciski „Eksport CSV/XML” na listach certyfikatów, osób, płatników, w archiwum, zadaniach, zaproszeniach, dzienniku, kartach i harmonogramie; pobieranie przez `fetch` z obsługą błędu JSON | `assets/js/views/exchange.js`, `assets/js/components.js`, `assets/js/core.js`, `includes/dashboard_app.php` |
| Uprawnienia i historia | eksport od roli MANAGER (`export.run`) w zakresie danych konta (D8), import tylko ADMIN (`import.run`); zdarzenia `data_exported`, `data_imported`, `eml_imported`, `email_received` z opisem na osi czasu | `classes/Rbac.php`, `lang/*` |
| Język (D4) | ok. 180 nowych kluczy PL i EN (kolumny plików, komunikaty walidacji importu, ekran wymiany danych) | `lang/pl.php`, `lang/en.php` |
| Testy | +31: `ExchangeFormatsTest` (CSV z polskiego Excela, formuły, XML z DOCTYPE, aliasy nagłówków), `EmlParserTest`, `ExportServiceTest` (w tym pełny obieg eksport → import bez duplikatów dla trzech zbiorów w obu formatach), `ImportServiceTest`, `EmlImportServiceTest` | `tests/*` |

Weryfikacja: 145/145 testów z integracją (bez flagi 68 zaliczonych, 77 pominiętych); PHPStan: 1 znana uwaga; `node --check` wszystkich plików JS; kontrola kluczy tłumaczeń; eksport i import sprawdzone w przeglądarce oraz przez HTTP (§10) — po podglądach baza bez zmian. Bez zmian w schemacie bazy.

**Świadomie nie zrobione:** import tworzący brakujące rekordy powiązane „w locie” (płatnik zakładany przy imporcie certyfikatu) — kolejność plików jest prostsza do wyjaśnienia i bezpieczniejsza; harmonogram importu z katalogu albo skrzynki pocztowej (kierunek rozwoju w pracy); eksport do PDF — karty drukuje przeglądarka (Etap 4).

Commit: `a26fb16`, gałąź `etap-5-wymiana-danych`, scalona na `master` 2026-09-17 (fast-forward).

### Etap 6 — jakość i domknięcie (2026-09-17)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Frontend bez CDN (N3, §5 pkt 11) | arkusz Tailwinda budowany lokalnie (`npm install && npm run css` → `assets/css/app.css`, 42 kB) z klasami z plików PHP i szablonów Vue; klasy komponentów (`btn-primary`, `card`, `th`…), style wydruku i `[v-cloak]` przeniesione ze stron do źródła CSS; Vue 3.5.13 w `assets/vendor`, krój Inter (latin i latin-ext, 4 grubości) w `assets/fonts`; gotowe pliki są w repozytorium, więc aplikacja działa bez Node i bez internetu | `package.json`, `tailwind.config.js`, `assets/css/app.src.css`, `assets/css/app.css`, `assets/vendor/*`, `assets/fonts/*`, `includes/head.php`, `includes/dashboard_app.php`, `includes/personal_dashboard_app.php` |
| Limit kodów logowania (§5 pkt 9, N5) | limit liczony z historii w `login_otps`: 3 kody na adres e-mail i 10 na adres IP w 15 minutach; kolumna `request_ip` i indeksy z migracji `otp_rate_limit`; czas utworzenia zapisuje PHP (ta sama strefa co reszta aplikacji), więc wyczyszczenie ciasteczka sesji nie resetuje limitu | `classes/AuthManager.php`, `classes/Migrations/OtpRateLimitMigration.php`, `database/schema.sql`, `login.php` |
| Koszt roczny (§5 pkt 12) | jeden przelicznik w PHP i w SQL: cykl miesięczny × 12, wieloletni ÷ liczba lat ważności; używają go wskaźniki pulpitu, lista płatników i panel szczegółów płatnika (wcześniej tylko raporty z Etapu 4) | `classes/CertificateHelper.php`, `classes/CertificateManager.php`, `classes/Service/PayerService.php`, `assets/js/views/payers.js` |
| Strona główna (§5 pkt 13) | trzy kafelki z wymyślonymi liczbami zastąpione opisem funkcji systemu (skaner odnowień, zaproszenia i przypomnienia, historia i eksport) | `index.php`, `lang/pl.php`, `lang/en.php` |
| Testy E2E (N6) | scenariusz przez warstwę API w jednym teście: płatnik → osoba → certyfikat → skaner → zaproszenie (zamiennik poczty) → odnowienie → karta raportowa → wyszukiwarka → eksport → import → dziennik → pulpit; osobny test kodów odpowiedzi (405, 403 bez tokenu CSRF, 401 bez sesji) i uprawnień operatora (403 dla dziennika, importu i eksportu, 404 dla cudzej karty) | `tests/Integration/EndToEndFlowTest.php` |
| Porządki (§5 pkt 16, 17) | usunięty `composer-setup.php`; `docs/thesis_part1.md` i `docs/AGENT_HANDOFF.md` oznaczone jako dokumenty historyczne z odesłaniem do mapy; `node_modules/` w `.gitignore`; martwe klucze tłumaczeń usunięte z `lang/de|es|uk.php` | j.w. |
| Testy | +4: limit kodów per e-mail i per IP (z nową sesją), scenariusz E2E i kontrola kodów odpowiedzi API | `tests/Unit/AuthManagerTest.php`, `tests/Integration/EndToEndFlowTest.php` |

Weryfikacja: kopia bazy przed migracją (`mysqldump`), migracja `otp_rate_limit` i drugi przebieg bez zmian, porównanie `information_schema` ze świeżą instalacją (139 kolumn, 76 pozycji indeksów, 21 kluczy obcych); 149/149 testów z integracją; PHPStan: 1 znana uwaga; `node --check` wszystkich plików JS; kontrola kluczy tłumaczeń; w przeglądarce pulpit, panel prywatny i strona główna renderują się bez CDN (żądania wyłącznie do `localhost`, brak błędów w konsoli), a `assets/css/app.css`, `assets/vendor/vue.global.prod.js` i pliki `woff2` zwracają 200 z poprawnym typem MIME.

Osobne commity po głównym: `.gitattributes` z końcami linii LF i jednorazowe przepisanie kopii roboczej, a potem `composer cs-fix` na 11 plikach — od tej pory `composer cs` kończy się bez uwag (§5 pkt 19).

Commit: `7764780` (+ `e2e0b41`, `be46d96`, `8917542`), gałąź `etap-6-jakosc`, scalona na `master` 2026-09-17 (fast-forward).

### Etap 7 — UX i wydajność (2026-09-17)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Szkielety treści (§2.5) | układ panelu (menu, wyszukiwarka, kafelki, tabela) jest w HTML strony i znika po zamontowaniu Vue — koniec z pustym ekranem i `[v-cloak]`; `TableState` pokazuje `SkeletonRows` z liczbą kolumn danej tabeli, więc treść nie przeskakuje; własne szkielety mają kafelki KPI i panel statystyk pulpitu, panele szczegółów, karty raportowe i pola formularzy (`FormSkeleton`); klasa `.skeleton` z animacją shimmer wyłączaną przy `prefers-reduced-motion`, szkielety oznaczone `role="status"`/`aria-hidden` | `assets/css/app.src.css`, `assets/js/components.js`, `assets/js/views/dashboard.js`, `assets/js/views/*.js`, `includes/dashboard_app.php` |
| Cache agregatów (§2.5) | `App\Cache`: pliki JSON w `storage/cache`, TTL 5 minut; klucz zawiera rolę i właściciela rekordów, więc OPERATOR nigdy nie dostanie liczb całej organizacji (D8); każdy zapis danych podbija wersję w `EventLogger`, co unieważnia wszystkie wpisy — pulpit nie pokazuje liczb sprzed zmiany; brak katalogu albo brak praw do zapisu nie psuje aplikacji (wartości liczą się wtedy za każdym razem) | `classes/Cache.php`, `classes/Service/EventLogger.php`, `classes/Api/DashboardController.php`, `classes/Service/TaskService.php` |
| Nagłówki pamięci podręcznej | `assets/**` (css, js, woff2, obrazy) → `Cache-Control: public, max-age=31536000, immutable` — adresy mają wersję (`?v=czas zmiany pliku`), więc zmiana pliku od razu omija cache; strony i API z danymi osobowymi → `no-store`; przy okazji blokada `package.json`, `package-lock.json`, `tailwind.config.js` i `node_modules/` przez HTTP | `.htaccess` |
| Testy | +7: `CacheTest` (jednokrotne liczenie, rozdzielne klucze, unieważnianie, wygaśnięcie, brak katalogu) i `DashboardCacheTest` (liczby per rola z cache, unieważnienie po dodaniu certyfikatu i zadania) | `tests/Unit/CacheTest.php`, `tests/Integration/DashboardCacheTest.php` |

Weryfikacja: 156/156 testów z integracją (bez flagi 75 zaliczonych, 81 pominiętych); PHPStan: 1 znana uwaga; `composer cs` bez uwag; `node --check` wszystkich plików JS; w przeglądarce szkielet tabeli i kafelków przy ładowaniu danych, brak błędów w konsoli; nagłówki `Cache-Control` sprawdzone przez HTTP dla arkusza, biblioteki, kroju pisma, stron i API.

**Świadomie nie zrobione:** cache raportów per osoba i płatnik oraz słowników i18n (§2.5 pkt 3) — korzyść mniejsza niż ryzyko pokazania nieaktualnej historii; przeniesienie cache do tabeli w bazie (pliki wystarczą przy jednym serwerze, a przy wielu instancjach wskazany byłby wspólny magazyn — kierunek rozwoju w pracy).

Commit: `fbc2237`, gałąź `etap-7-ux-wydajnosc`, scalona na `master` 2026-09-17 (fast-forward).

**Plan z §7 jest wykonany w całości (Etapy 0–7).** Dalej zostaje część pisemna (§9), zrzuty ekranu do rozdziału z dokumentacją użytkownika i ustalenie terminu z promotorem (decyzja D6). Kod wchodzi w tryb utrzymania: poprawki i drobne uzupełnienia, nowy zakres tylko po uzgodnieniu.

**Świadomie nie zrobione:** skeleton loadery i cache agregatów z nagłówkami dla zasobów statycznych (Etap 7); przemianowanie kolumny `annual_cost` na `period_cost` (zmiana schematu bez korzyści dla obrony); testy przeglądarkowe w Selenium/Playwright — scenariusz E2E idzie przez API, bo to ta sama ścieżka, którą wykonuje panel.

---

### Etap 8 — rozdzielenie aplikacji (2026-09-18)

Zmiana decyzji D1: zamiast zamrożonego dodatku panel prywatny stał się osobnym programem, a ewidencja
firmowa przestała cokolwiek o nim wiedzieć. Powód: jedna aplikacja opisywała dwie różne dziedziny —
certyfikaty firmowe z rolami, płatnikami i zaproszeniami oraz prywatne subskrypcje jednej osoby.
Wspólne były tylko tabela `certificates` z kolumną `scope` i konta, co mieszało dane osobowe
pracowników z prywatnymi rachunkami i utrudniało opis pracy.

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Nowa aplikacja | „Menedżer Subskrypcji” w katalogu `menedzer_subskrypcji`: własna baza (`users`, `login_otps`, `subscriptions`, `subscription_payments`), otwarta rejestracja, logowanie kodem z poczty, pulpit z kosztem miesięcznym i rocznym, podział na kategorie, filtry, dodawanie i edycja, „Opłacone” z historią płatności, ustawienia konta z wyprzedzeniem przypomnień, cron `reminders.php`, 44 testy, PHPStan bez uwag | osobne repozytorium `menedzer_subskrypcji` |
| Przeniesienie danych | `scripts/import-from-certisub.php` w nowej aplikacji czyta tę bazę (nic w niej nie zmienia): certyfikaty o zakresie `personal` stają się subskrypcjami (typ → kategoria, cykl `annual`/`multi_year` → roczny), a wpisy z `manager_subskrypcji` o tej samej nazwie scalają się z nimi zamiast tworzyć duplikaty (Netflix, Spotify, PlayStation i iCloud były w obu listach) | `menedzer_subskrypcji/scripts/import-from-certisub.php` |
| Migracja bazy firmowej | `split_personal_app`: usuwa certyfikaty o zakresie `personal` z ich historią, płatników używanych wyłącznie przez nie (`Budżet domowy`), tabelę `manager_subskrypcji`, kolumnę `certificates.scope` z indeksem i typy STREAMING, MUSIC, GAMING, FITNESS, CLOUD_STORAGE ze słownika | `classes/Migrations/SplitPersonalAppMigration.php`, `classes/MigrationRunner.php`, `database/schema.sql` |
| Kod bez zakresu | zniknęły `dashboard-personal.php`, `includes/personal_dashboard_app.php`, `classes/Api/PersonalManagerController.php`, `api/add_subscription.php`, `classes/ManagerSubscriptionManager.php`, `classes/PayerManager.php`, `cron/send_reminders.php` oraz martwe po nich metody (`CertificateManager::getAllCertificates`, `UserManager::getAllUsers`); parametr `$scope` zniknął z `CertificateHelper` i `CertificateManager`, a warunek `scope = 'corporate'` ze wszystkich zapytań usług; `PayerService::notPersonalOnly()` przestał być potrzebny | `classes/*`, `classes/Service/*` |
| Interfejs | strona główna opisuje jedną aplikację (jeden przycisk logowania, sekcja „wybierz asystenta” usunięta, nowe teksty w pięciu językach), nagłówek panelu bez przełącznika, `AuthManager` wraca po zalogowaniu wyłącznie na `dashboard.php`, oś czasu i dziennik bez rozróżniania zakresu | `index.php`, `includes/dashboard_app.php`, `assets/js/components.js`, `assets/js/views/events.js`, `lang/*.php` |
| Testy i dokumentacja | −3 testy panelu prywatnego (153/153 z integracją), pomocnicze klasy testowe bez kolumny `scope`; README, CLAUDE.md, mapa i instrukcja opisują jedną dziedzinę | `tests/*`, `README.md`, `CLAUDE.md`, `docs/*` |

Weryfikacja: kopia bazy przed migracją (`mysqldump`), migracja i drugi przebieg bez zmian, porównanie
`information_schema` ze świeżą instalacją (130 kolumn, 58 pozycji indeksów, 20 kluczy obcych);
153/153 testów z integracją; PHPStan bez uwag; `composer cs` bez uwag; `node --check` wszystkich
plików JS; kontrola kluczy tłumaczeń (tylko znane prefiksy budowane dynamicznie); `seed-demo-data.php`
i `cleanup-demo-data.php` sprawdzone na osobnej bazie; w przeglądarce strona główna i panel (pulpit,
płatnicy, raporty, dziennik) bez błędów w konsoli, `dashboard-personal.php` i `api/add_subscription.php`
zwracają 404. Dane prywatne (6 subskrypcji) przeniesione do nowej aplikacji i sprawdzone w jej panelu
przed usunięciem z bazy firmowej.

**Świadomie nie zrobione:** przemianowanie kluczy `corporate.*` w plikach językowych (etykiety panelu
są poprawne, a zmiana ruszyłaby pięć plików bez zysku dla użytkownika) oraz `docs/AGENT_HANDOFF.md`,
który zostaje jako dokument historyczny z adnotacją o stanie sprzed Etapu 1.

Commit: `51662d7`, gałąź `etap-8-rozdzielenie-aplikacji`, scalona na `master` 2026-09-18 (fast-forward).
Aplikacja prywatna ma własne repozytorium (`menedzer_subskrypcji`, pierwszy commit `4b44479`).

**Plan z §7 jest wykonany w całości (Etapy 0–8).**

---

### Etap 9 — logowanie hasłem z potwierdzeniem adresu e-mail (2026-09-30)

Zmiana schematu uwierzytelniania na życzenie autora: z logowania bezhasłowego (jednorazowy kod OTP
w e-mailu) na **e-mail + hasło z potwierdzeniem adresu linkiem**, przy wysyłce wiadomości przez
SMTP z autoryzacją **OAuth2 (XOAUTH2)** zamiast hasła do skrzynki.

**Model danych** (`PasswordAuthMigration`, migracja `password_auth`, idempotentna):
- `users.password_hash` (NULL = konto bez hasła), `users.email_verified_at` (NULL = konto nieaktywne),
  `users.last_login_at` (wcześniej liczone z `login_otps`), indeks `idx_users_verified`;
- nowa tabela `email_verifications`: jednorazowe tokeny `EMAIL_VERIFY` i `PASSWORD_SET` — w bazie
  wyłącznie skrót SHA-256 (`token_hash`, klucz unikalny), `expires_at`, `used_at`, `request_ip`, FK CASCADE;
- nowa tabela `login_attempts`: historia prób logowania jako podstawa limitu (bez FK — zapisujemy
  też próby na adresy, których nie ma w `users`);
- konta sprzed migracji zostają potwierdzone (odbierały kody na ten adres), ale **bez hasła** —
  ustawiają je linkiem z wiadomości albo `scripts/set-password.php`;
- `login_otps` zostaje jako historia logowań sprzed zmiany; aplikacja z niej nie korzysta.

**Kod**: `classes/Auth/` (`PasswordPolicy` — min. 10 znaków wg NIST SP 800-63B, `VerificationTokens` —
wystawianie/zużywanie tokenów, `LoginThrottle` — 5 prób na adres i 20 na IP w 15 minut),
`classes/Mail/OAuth2TokenProvider` (implementuje `PHPMailer\PHPMailer\OAuthTokenProvider`: wymiana
`refresh_token` → `access_token`, cache w `storage/cache/oauth2`, bez nowych zależności Composera),
`AuthManager` przepisany (`register`, `verifyEmail`, `resendVerification`, `attemptLogin`,
`requestPasswordSetLink`, `setPasswordWithToken`), `AuthMailer` + `EmailService` (wiadomości HTML
z przyciskiem), `AppUrl` (bezwzględne linki z konfiguracji, nie z nagłówka `Host`),
`config/auth.php` (przełącznik rejestracji publicznej — D3 do wyboru), nowe strony `register.php`,
`verify-email.php`, `set-password.php`, przebudowane `login.php` i wspólna oprawa `includes/auth_layout.php`,
akcja API `accounts.send_password_link` + przycisk w widoku „Konta”.

**Bezpieczeństwo**: hasła tylko jako skrót `password_hash()` z automatycznym przeliczeniem przy
zmianie kosztu; jednakowy komunikat dla złego hasła i nieznanego adresu (plus porównanie na
sztucznym skrócie, żeby czas odpowiedzi nie zdradzał istnienia konta); rejestracja na zajęty adres
nie rusza cudzego konta i nie wysyła wiadomości; limity wysyłek (3/adres, 10/IP na 15 minut) i prób
logowania liczone w bazie; tokeny jednorazowe, zahaszowane, z terminem ważności; wyłączenie konta
unieważnia jego tokeny; pomyłka w haśle nie zużywa linku „ustaw hasło”.

**Poprawki wykryte podczas sprawdzania na działającej aplikacji**:
- druga rejestracja na adres, którego jeszcze nie potwierdzono, wysyła link ponownie (wcześniej
  odpowiedź była neutralna i nic nie wychodziło, więc po nieudanej wysyłce adres był zablokowany);
  dane konta z pierwszej rejestracji nie są nadpisywane;
- odmowa z powodu niepotwierdzonego adresu albo wyłączonego konta **nie liczy się** do limitu prób —
  hasło było poprawne, więc to nie zgadywanie (wcześniej kilka prób przed potwierdzeniem blokowało konto);
- ustawienie nowego hasła zeruje licznik nieudanych prób, bo tak brzmi komunikat o blokadzie.

**Jakość**: 187/187 testów zielonych z włączonymi integracyjnymi (nowe: `AuthManagerTest` — 30
przypadków, `PasswordPolicyTest`, `OAuth2TokenProviderTest`, `MailerRateLimitTest`, rozszerzony
`MailConfigTest`),
PHPStan level 5 bez błędów, PHP-CS-Fixer 0/146. Migracja `password_auth` wykonana na bazie
`assistent_subscriptions` (kopia przed migracją: `mysqldump`, 14 tabel); porównanie
`information_schema` bazy aplikacji z bazą testową zbudowaną z `database/schema.sql` + migracje:
**147 kolumn / 85 wpisów indeksów / 21 kluczy obcych — identyczne**. `last_login_at` przeniesione
z `login_otps` (2 konta z historią logowań).

**Sprawdzenie na działającej aplikacji (Apache + MySQL, konta testowe usunięte po testach)**:
rejestracja przez formularz → blokada logowania przed potwierdzeniem → link potwierdzający (302 na
`login.php?verified=1`) → ten sam link drugi raz „już użyty” → logowanie (302 na pulpit) → pulpit i
`api/dashboard.php` z sesją → wylogowanie → 5 nieudanych prób i blokada na szóstej → jednakowy
komunikat dla nieistniejącego adresu → POST bez tokenu CSRF odrzucony → `set-password.php`: złe
hasło nie zużywa linku, poprawne loguje od razu, link jednorazowy → ADMIN zakłada konto przez
`api/accounts.php` i wychodzi link „ustaw hasło” (`password_link_sent: true`), ponowna wysyłka
unieważnia poprzedni token → `config/auth.php`, `storage/cache/oauth2`, `classes/`, `logs/` zwracają
403 po HTTP.

**Poczta w środowisku autora (2026-09-30)**: `config/mail.local.php` uzupełniony danymi skrzynki
Mailtrap „diploma” (sterownik `sandbox`, dane pobrane z API Mailtrapa, plik jest poza repozytorium);
`app_url` ustawiony na `http://localhost/assistent_subscription`, żeby linki w wiadomościach nie
zależały od nagłówka `Host`. Nowy `scripts/mailtrap-inbox.php` czyta skrzynkę z terminala (lista
wiadomości, link z najnowszej, treść wskazanej) — na pokazie zastępuje otwieranie mailtrap.io.
Cały przepływ sprawdzony z prawdziwą wysyłką: rejestracja → link **wzięty ze skrzynki** →
potwierdzenie → logowanie → „nie pamiętam hasła” → link ze skrzynki → nowe hasło → logowanie
(stare hasło odrzucone); konta testowe usunięte z bazy.

Przy okazji wyszły dwie rzeczy warte poprawy i poprawione:
- darmowy plan Mailtrapa przyjmuje **jedną wiadomość na sekundę** (`550 5.7.0 Too many emails per
  second`), więc druga wysyłka w tej samej sekundzie przepadała — `App\Mailer` rozpoznaje teraz tę
  odmowę (i jej odpowiedniki u Gmaila i Outlooka), zamyka połączenie SMTP i ponawia wysyłkę raz po
  dwóch sekundach; ponawiamy wyłącznie ten błąd, bo serwer odrzuca wiadomość przed przyjęciem,
  więc nie grozi to duplikatem (test: `MailerRateLimitTest`);
- nieudana wysyłka jest **zawsze** zapisywana w `logs/mail-errors.log` razem z odpowiedzią serwera
  (`PHPMailer::ErrorInfo`, nie samo „data not accepted”). Wcześniej przepływy z neutralną
  odpowiedzią („nie pamiętam hasła”, ponowna wysyłka linku) gubiły błąd po cichu, bo nie mogą go
  pokazać użytkownikowi — administrator nie miał jak zauważyć awarii poczty.

**Zostaje do zrobienia przez autora**: konta sprzed migracji nie mają hasła — ustawić je
(`php scripts/set-password.php --email=… --send-link` albo `--password='…'`); do wysyłki na
prawdziwe adresy uzupełnić blok `oauth2` w `config/mail.local.php` (`php scripts/oauth2-token.php`)
i przełączyć `driver` na `oauth2`.

### Część pisemna — poprawki rozdz. 1–2 i nowy rozdz. 3 (2026-09-30)

Na wyraźną prośbę autora poprawiono `docs/generate_thesis_pdfs.py` (źródło
`docs/Szkic pracy Litosh.pdf`) i zregenerowano oba PDF-y (`docs/` i `Downloads/`):

- **Rozdz. 1–2 poprawione pod Załącznik A** (§ poniżej): usunięto ramowanie „subskrypcje
  prywatne / zmęczenie subskrypcjami” jako rdzeń projektu; 1.2 i 3.1 opisują to teraz jako
  pierwotną koncepcję wydzieloną decyzją D1 do osobnej aplikacji „Menedżer Subskrypcji”;
  2.2 — tabela porównawcza zastąpiona narzędziami klasy Cert. LCM (Keyfactor, DigiCert,
  Sectigo) i polskimi centrami certyfikacji kwalifikowanej (Certum, KIR Szafir, EuroCert)
  zamiast aplikacji mobilnych do budżetu domowego.
- **Nowy podrozdział 2.5 „Studium wykonalności: PHP/LAMP a Java/Spring Boot”** — Załącznik A
  wprost wymaga studium wykonalności przed wyborem technologii („Przewiduje się że
  narzędziem realizacji będzie Java i Spring… po studium wykonalności zostanie wyłonione
  narzędzie programistyczne”); dawne 2.5 „Wnioski” przesunięte na 2.6.
- **Rozdział 3 „Założenia projektowe aplikacji” napisany w całości** (3.1–3.5): cel i zakres
  (z wyjaśnieniem D1), wymagania funkcjonalne wprost z macierzy F1–F19 (§3.1), niefunkcjonalne
  z N1–N6 (§3.2), role ADMIN/MANAGER/OPERATOR z zakresem D8, architektura warstwowa
  (prezentacja/HTTP/usługi/dane) i przebieg procesu odnowień.
- ToC rozdziału 6 zmieniony z „Moduł powiadomień i menedżera subskrypcji” na „Moduł zadań,
  zaproszeń i przypomnień e-mail” (6.1–6.4) — stara nazwa opisywała funkcję, która od Etapu 8
  jest osobną aplikacją.
- Naprawiono błąd w generatorze: własna tabela wielokomórkowa (`multi_cell` w pętli) w 2.5
  gubiła wyrównanie wierszy przy przełamaniu strony; zastąpiona bezpieczną listą
  podpunktów (ten sam wzorzec co reszta dokumentu).
- Stan części pisemnej wg §9: z ok. 25% (rozdz. 1–2 z 8) do ok. 40% (rozdz. 1–3 z 8,
  licząc podrozdziałami). Pozostają rozdziały 4–8 (baza danych, implementacja, moduł
  zadań/zaproszeń, testy, zakończenie) — nie napisane, bo wymagają zrzutów ekranu i
  szczegółów implementacyjnych z kodu, których nie dotyczyła ta prośba.

### Część pisemna — logowanie hasłem zamiast OTP i nowy rozdz. 4 (2026-09-30, później tego samego dnia)

Autor poprosił o dociągnięcie do 40% bez zdjęć (z miejscem na wklejenie własnych
zrzutów ekranu). Przy okazji ujawniła się niespójność: rozdz. 1–3 opisywały logowanie
kodem OTP (zgodnie z `CLAUDE.md` i Załącznikiem A), a kod ma od Etapu 9 (niescalony,
opisany wyżej w tym dzienniku) logowanie hasłem z weryfikacją e-mail. Zapytany, autor
wybrał opisanie **stanu aktualnego kodu** (hasło + e-mail), a nie stanu z Załącznika A:

- Wszystkie wzmianki o „logowaniu OTP / bez hasła / passwordless” w `docs/Szkic pracy
  Litosh.pdf` zastąpiono opisem logowania hasłem (polityka NIST SP 800-63B) z
  jednorazowym, haszowanym tokenem e-mail do potwierdzenia adresu i ustawienia hasła
  (1.4, 2.3.4, tabela 2.2, 3.2, 3.4, ToC 5.5 i 7.3). **Nie dotyczy** `docs/założenia i
  daty Litosh.pdf` — to osobny, wcześniej zatwierdzony dokument, którego treści ta
  prośba nie obejmowała.
- Przy okazji poprawiono 2.3.3: dashboard opisany jako Vue.js z CDN — nieaktualne od
  Etapu 6 (frontend bez CDN, Vue w repozytorium).
- Dodano metodę `ThesisPDF.figure_placeholder()` — szare obramowanie z podpisem
  „[miejsce na rysunek — …]”, automatycznie łamie stronę, jeśli nie ma miejsca. Autor
  wklei zrzuty/diagramy sam.
- **Rozdział 4 „Projekt bazy danych” napisany w całości** (4.1–4.4): model pojęciowy
  (11 encji i ich relacje), 4.2 z placeholderem na diagram ERD, struktura tabel
  `users/payers/beneficiaries/certificates/renewal_tasks/email_templates/attachments/
  invitations/events` opisana wprost z `database/schema.sql` (typy, klucze unikalne,
  klucze obce z regułami `ON DELETE`), integralność i bezpieczeństwo (PDO, wybór
  RESTRICT/SET NULL/CASCADE, archiwizacja, unikalne klucze jako reguły biznesowe w
  bazie, hashowanie haseł i tokenów, limity w bazie, migracje idempotentne).
- Stan części pisemnej wg §9: rozdz. 1–4 z 8 napisane (ok. 51% licząc podrozdziałami,
  ponad prośbę „do 40%” — rozdział 4 to spójna całość, nie zatrzymano się w połowie).
  Pozostają rozdziały 5–8.

---

## Załącznik A — Oficjalny opis pracy (dosłownie, przekazany 2026-09-16)

```text
Opis:
W bezpośredniej perspektywie jest to aplikacja webowa (lub stanowiskowa) do której możemy dodać informację o użytkownikach (dane osobowe) certyfikatu (powiązanego z usługą), certyfikacie (dane usługi), płatnikach (dane płatnika). System wiąże pozyskane informacje razem umożliwiając wykonanie raportowania w zależności od wymaganej perspektywy: Użytkownik – daty odnowienia, szczegóły certyfikatu oraz historia; Płatnik – lista certyfikatów, daty ich wygasania, historia, osoby powiązanel; Administrator – zarządzanie systemem niezależnie od perspektyw; System powinien składować dane w bazie, umożliwiać dodawanie nowych rekordów, edycję i archiwizację danych; eksport danych do pliku csv/xml oraz import takich danych z plików csv/xml/eml.

Składowe systemu:
System składać się będzie z:
– aplikacji webowej umożliwiającej wprowadzenie przez zarządzającego danych:
° certyfikatu (np. danych certyfikatów kwalifikowanych w postaci numeru seryjnego, daty ważności, wygasania, wymagań co do czasu odnowienia etc.),
° użytkowników certyfikatu (dane osobowe beneficjentów),
° płatników (beneficjent nie jest płatnikiem za usługę, ale jest z płatnikiem powiązany).

System ma za zadanie nadzorować składowane w Bazie Danych dane certyfikatów i poprzez ich regularne skanowanie wyszukiwać te, które winny być w założonym marginesie czasowym odnowione.

System winien:

– tworzyć listę “ToDo” (do zrobienia) z priorytetyzowaniem w zależności od założonych zakresów dat wygaśnięcia certyfikatów;
– umożliwiać wysłanie zaproszeń do odnowienia poprzez e-mail z wykorzystaniem szablonu wiadomości i załączników;
– zarządzanie wysłanymi zaproszeniami (przypomnienia).

i ponadto:
– implementować system dostępowy oparty o konta użytkowników,
– umożliwiać wyszukiwanie usług/użytkowników/płatników i istniejących powiązań pomiędzy nimi,
– umożliwiać tworzenie i przegląd ścieżki realizacji i powiązań na osi czasu pomiędzy elementami systemu,
– prowadzić statystyki realizacji zadań przy grupowaniu do ustalonych statusów, np: do zrobienia, zrobiony, porzucony etc.

Założenia dodatkowe:
System opierać się będzie na zarządzaniu dostępem do niego poprzez zhierarchizowany plan kont z podziałem na role.

Element badawczy pracy:
Praca zawierać będzie wstęp, ogólną charakterystykę problemu, analizę istniejących rozwiązań, projekt ogólny i techniczny, dokumentację użytkownika, opis testów, zakończenie i podsumowanie pracy wraz z przedstawieniem ewentualnych przyszłych zmian rozwojowych.

Część implementacyjna:
Przewidywaną formą realizacji jest aplikacja webowa korzystająca z Bazy stworzonej jako system bazodanowy ulokowany w publicznie (lub intranetowo w przypadku instalacji w chronionym i ograniczonym dostępie) dostępnych zasobach internetowych (serwer Bazy Danych w ramach standardowej usługi hostingu). Bazodanowa warstwa serwerowa oparta będzie o środowisko zgodne z MySQL (LAMP).

Przewiduje się że narzędziem realizacji będzie Java i Spring. Jednak na etapie wstępnym nastąpi analiza dostępnych opcji z dyskusją rozwiązań i badaniem tematu, po studium wykonalności zostanie wyłonione narzędzie programistyczne z wykorzystaniem którego aplikacja zostanie zrealizowana. Webowa aplikacja bazodanowa stanowiąca jądro systemu przewidziana jest do realizacji z wykorzystaniem PHP, HTML i JavaScript.
```
