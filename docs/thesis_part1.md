# PRACA DYPLOMOWA — CZĘŚĆ I (rozdziały wstępne)

> **Dokument historyczny (szkic z lipca 2026).** Opisuje trzy tabele i model sprzed Etapu 1 — nie jest zgodny z kodem. Aktualny zakres, model danych i plan: [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md).

**Tytuł roboczy:** Informatyczny Asystent Zarządzania Certyfikatami i Subskrypcjami (CertiSub Assistant)

*Dokument przeznaczony do skopiowania do pliku .doc — formatowanie nagłówków zachowane w konwencji Markdown.*

---

## WSTĘP

### 1.1. Cel pracy

Niniejsza praca dyplomowa ma na celu zaprojektowanie i zaimplementowanie wersji demonstracyjnej (MVP) systemu informatycznego wspierającego zarządzanie certyfikatami cyfrowymi oraz subskrypcjami usług informatycznych w środowisku przedsiębiorstwa. Opracowany prototyp — **CertiSub Assistant** — umożliwia centralne gromadzenie informacji o datach wygaśnięcia, statusach płatności, właścicielach biznesowych oraz podmiotach rozliczeniowych powiązanych z poszczególnymi pozycjami.

Głównym rezultatem pracy jest działająca aplikacja webowa oparta na stosie technologicznym LAMP (Linux, Apache, MySQL, PHP), uzupełniona o warstwę prezentacji wykorzystującą Vue.js oraz Tailwind CSS. System dostarcza interfejs typu Single Page Dashboard, w którym użytkownik — w zależności od przypisanej roli — uzyskuje dostęp do przejrzystego panelu menedżerskiego obejmującego statystyki, listę priorytetowych odnowień, widok płatności wymagających działania oraz wyszukiwarkę subskrypcji.

### 1.2. Geneza problemu

Współczesna infrastruktura IT organizacji opiera się na szerokim spektrum usług podlegających okresowej odnowie: certyfikatów SSL/TLS zabezpieczających komunikację, licencji oprogramowania typu SaaS, rejestracji domen, planów wsparcia chmurowego czy certyfikatów podpisywania kodu. Każda z tych pozycji wiąże się z konkretną datą wygaśnięcia, kosztem rocznym oraz odpowiedzialnością po stronie wyznaczonego pracownika i podmiotu finansującego.

W praktyce operacyjnej dane te często rozproszone są pomiędzy arkuszami kalkulacyjnymi, skrzynkami pocztowymi, portalami dostawców oraz dokumentacją papierową. Brak jednego źródła prawdy prowadzi do następujących zagrożeń:

- **Utrata ciągłości usług** — nieodnowiony certyfikat SSL powoduje ostrzeżenia przeglądarki, awarie API oraz spadek zaufania klientów;
- **Przerwy w dostępie do oprogramowania** — opóźnione płatności za subskrypcje SaaS skutkują zawieszeniem licencji i spadkiem produktywności zespołów;
- **Ryzyko compliance** — certyfikaty kwalifikowane i podpisy elektroniczne wymagają ścisłej kontroli terminów ważności;
- **Nakłady czasu na wyszukiwanie informacji** — bez scentralizowanego rejestru ustalenie, kto jest właścicielem danej subskrypcji i jaki jest jej koszt, wymaga wielokrotnych konsultacji między działem IT a finansami.

Problem badawczy sprowadza się zatem do następującego pytania: *w jaki sposób zaprojektować lekki, a zarazem funkcjonalny system informatyczny, który w jednym interfejsie ujednolici zarządzanie certyfikatami i subskrypcjami, zapewniając czytelność informacji o terminach oraz płatnościach?*

### 1.3. Zakres pracy

Praca obejmuje:

1. Analizę wykonalności wyboru stosu technologicznego;
2. Projekt relacyjnej bazy danych z encjami: użytkownicy, płatnicy, subskrypcje;
3. Implementację warstwy backendowej w PHP 8 z wykorzystaniem PDO;
4. Implementację interfejsu użytkownika z wykorzystaniem Vue.js i Tailwind CSS;
5. Opracowanie strony prezentacyjnej (landing page) oraz panelu menedżerskiego (dashboard).

Poza zakresem MVP pozostają m.in.: pełna autentykacja użytkowników, integracja z zewnętrznymi API dostawców certyfikatów, moduł importu/eksportu oraz automatyczne wysyłanie powiadomień e-mail (przycisk w interfejsie stanowi jedynie placeholder funkcjonalności przyszłej).

### 1.4. Struktura pracy

Kolejne rozdziały pracy prezentują analizę technologiczną, architekturę bazy danych, szczegóły implementacji backendu i frontendu, a następnie podsumowanie wraz z kierunkami rozwoju systemu.

---

## ROZDZIAŁ 1. ANALIZA WYBORU TECHNOLOGII (STUDIUM WYKONALNOŚCI)

### 1.1. Wymagania niefunkcjonalne

Przy projektowaniu systemu CertiSub Assistant przyjęto następujące wymagania niefunkcjonalne:

| Wymaganie | Opis |
|-----------|------|
| Dostępność | Aplikacja webowa dostępna przez przeglądarkę, bez instalacji klienta |
| Bezpieczeństwo | Odporność na SQL Injection poprzez prepared statements (PDO) |
| Czytelność UI | Intuicyjny dashboard z natychmiastowym dostępem do kluczowych wskaźników |
| Reaktywność | Filtrowanie i wyszukiwanie bez przeładowania strony |
| Koszt wdrożenia | Wykorzystanie technologii open-source i środowiska LAMP |
| Skalowalność MVP | Modularna struktura klas PHP umożliwiająca rozbudowę |

### 1.2. Wybór backendu: PHP i MySQL (stos LAMP)

#### 1.2.1. Uzasadnienie wyboru PHP

Język PHP w wersji 8.x stanowi dojrzałe rozwiązanie do budowy aplikacji webowych renderowanych po stronie serwera. W kontekście niniejszej pracy wybrano PHP z następujących powodów:

1. **Powszechność w hostingu** — PHP jest domyślnie obsługiwane przez większość serwerów Apache i Nginx, co ułatwia wdrożenie prototypu w środowisku Laragon, XAMPP lub produkcyjnym hostingu współdzielonym.
2. **Integracja z MySQL** — rozszerzenie PDO zapewnia jednolity interfejs dostępu do bazy danych z obsługą prepared statements, co jest kluczowe z punktu widzenia bezpieczeństwa.
3. **Programowanie obiektowe** — PHP 8 oferuje pełne wsparcie dla klas, typów skalarnych, strict types oraz wzorców projektowych (np. Singleton dla połączenia z bazą).
4. **Niski próg wejścia** — dla zespołów IT w małych i średnich organizacjach PHP pozostaje technologią dobrze znaną, co obniża koszt utrzymania.

Alternatywy takie jak Node.js (Express), Python (Django/Flask) czy Java (Spring Boot) oferują porównywalne możliwości, lecz wymagają dodatkowej konfiguracji środowiska uruchomieniowego i nie zapewniają w przypadku niniejszego MVP istotnej przewagi funkcjonalnej przy jednoczesnym zwiększeniu złożoności wdrożenia.

#### 1.2.2. Uzasadnienie wyboru MySQL

System zarządzania relacyjną bazą danych MySQL (lub kompatybilna MariaDB) został wybrany ze względu na:

- **Model relacyjny** — encje użytkowników, płatników i subskrypcji naturalnie mapują się na tabele połączone kluczami obcymi;
- **Integralność referencyjną** — ograniczenia FOREIGN KEY zapobiegają powstawaniu subskrypcji bez przypisanego właściciela lub płatnika;
- **Wydajność zapytań JOIN** — metoda `getAllSubscriptions()` łączy trzy tabele w jednym zapytaniu, co jest efektywne przy skali setek rekordów typowej dla SME;
- **Dostępność w LAMP** — MySQL jest standardowym komponentem pakietów Laragon, WAMP i LAMP.

### 1.3. Wybór frontendu: Vue.js i Tailwind CSS

#### 1.3.1. Vue.js — reaktywność bez przeładowania strony

Tradycyjne aplikacje webowe oparte wyłącznie na PHP generują pełny dokument HTML przy każdym żądaniu. W przypadku tabeli subskrypcji z funkcją wyszukiwania i filtrowania prowadziłoby to do:

- opóźnień wynikających z round-trip do serwera przy każdej zmianie filtra;
- utraty stanu interfejsu (pozycja przewijania, aktywna zakładka);
- większego obciążenia serwera przy częstych zapytaniach.

**Vue.js 3** (załadowany przez CDN w warstwie prezentacji) wprowadza reaktywną warstwę po stronie klienta. Dane subskrypcji są osadzane w dokumencie jako JSON podczas pierwszego renderowania PHP, a następnie Vue przejmuje kontrolę nad:

- polem wyszukiwania globalnego (`v-model`);
- filtrami typu, statusu płatności i priorytetu;
- dynamicznym podświetlaniem wierszy (`v-bind:class`);
- przełączaniem widoków nawigacji bez przeładowania strony.

Porównanie z czystym JavaScript: Vue oferuje deklaratywny model powiązań danych i computed properties, co redukuje ilość kodu imperatywnego i ułatwia utrzymanie. Porównanie z React lub Angular: Vue charakteryzuje się łagodniejszą krzywą uczenia i możliwością integracji z istniejącym szablonem PHP bez konieczności budowy pełnego SPA z bundlerem (Webpack/Vite).

#### 1.3.2. Tailwind CSS — spójny i responsywny interfejs

Tailwind CSS to framework utility-first umożliwiający szybkie budowanie nowoczesnego interfejsu bez pisania rozległych arkuszy stylów. Zastosowanie Tailwind przez CDN w MVP eliminuje etap kompilacji assetów, co jest korzystne w kontekście pracy inżynierskiej skupionej na logice biznesowej.

Zalety w projekcie CertiSub Assistant:

- **Responsywność** — klasy takie jak `grid-cols-1 sm:grid-cols-3` zapewniają poprawne wyświetlanie na urządzeniach mobilnych;
- **Spójność wizualna** — paleta kolorów `brand` zdefiniowana w konfiguracji Tailwind jednolicie stylizuje landing page i dashboard;
- **Semantyka statusów** — kolory tła wierszy (czerwony/żółty) komunikują priorytet bez dodatkowych grafik.

### 1.4. Porównanie z tradycyjnym przeładowywaniem stron

| Aspekt | Tradycyjny PHP (POST/GET) | PHP + Vue.js (MVP) |
|--------|---------------------------|---------------------|
| Wyszukiwanie w tabeli | Wymaga submit formularza lub AJAX | Natychmiastowe, po stronie klienta |
| Filtry wielokrotne | Złożona logika parametrów URL | Computed property w Vue |
| UX nawigacji | Pełne przeładowanie dokumentu | Przełączanie sekcji `v-show` |
| Obciążenie serwera | Wyższe przy częstych zapytaniach | Jedno żądanie przy wejściu na dashboard |
| Złożoność implementacji | Niższa | Umiarkowana (CDN, bez build step) |

Przyjęte rozwiązanie hybrydowe — **server-side rendering danych + client-side interactivity** — stanowi kompromis optymalny dla MVP: zachowuje prostotę wdrożenia LAMP, a jednocześnie dostarcza doświadczenie użytkownika zbliżone do nowoczesnych paneli administracyjnych.

### 1.5. Środowisko deweloperskie

Prototyp opracowano w środowisku **Laragon** na systemie Windows, co zapewnia lokalny serwer Apache, interpreter PHP 8 oraz serwer MySQL. Taki wybór odzwierciedla typowy scenariusz pracy studenta lub małego zespołu developerskiego i nie wymaga konteneryzacji (Docker) na etapie demonstracyjnym.

---

## ROZDZIAŁ 2. ARCHITEKTURA BAZY DANYCH

### 2.1. Koncepcja modelu danych

Baza danych `assistent_subscriptions` stanowi centralne repozytorium informacji systemu CertiSub Assistant. Model oparto na trzech głównych encjach biznesowych:

1. **Użytkownicy (users)** — osoby odpowiedzialne za subskrypcje w organizacji;
2. **Płatnicy (payers)** — podmioty rozliczeniowe (firmy) opłacające odnowienia;
3. **Subskrypcje (subscriptions)** — rejestr certyfikatów i usług podlegających odnowie.

Relacje między encjami mają charakter jeden-do-wielu: jeden użytkownik może być właścicielem wielu subskrypcji, jeden płatnik może finansować wiele pozycji, natomiast każda subskrypcja przypisana jest do dokładnie jednego użytkownika i jednego płatnika.

### 2.2. Tabela `users` (użytkownicy)

Tabela przechowuje dane członków zespołu korzystających z systemu.

| Kolumna | Typ | Opis |
|---------|-----|------|
| `id` | INT UNSIGNED, PK, AUTO_INCREMENT | Identyfikator użytkownika |
| `first_name` | VARCHAR(100) | Imię |
| `last_name` | VARCHAR(100) | Nazwisko |
| `role` | ENUM('ADMIN','MANAGER','OPERATOR') | Rola uprawnień w systemie |
| `email` | VARCHAR(255), NULL | Adres e-mail kontaktowy |
| `created_at` | TIMESTAMP | Data utworzenia rekordu |

**Role użytkowników** pełnią funkcję kontekstu prezentacji w interfejsie (MVP nie implementuje jeszcze pełnej kontroli dostępu). Administrator (`ADMIN`) ma dostęp do pełnego dashboardu menedżerskiego; menedżer i operator reprezentują profile o zwężonym zakresie odpowiedzialności operacyjnej.

### 2.3. Tabela `payers` (płatnicy)

Tabela reprezentuje podmioty zewnętrzne lub wewnętrzne jednostki organizacyjne odpowiedzialne za rozliczenia.

| Kolumna | Typ | Opis |
|---------|-----|------|
| `id` | INT UNSIGNED, PK, AUTO_INCREMENT | Identyfikator płatnika |
| `company_name` | VARCHAR(255) | Nazwa firmy |
| `contact_person` | VARCHAR(200) | Osoba kontaktowa |
| `tax_id` | VARCHAR(20), NULL | Identyfikator podatkowy (np. NIP) |
| `created_at` | TIMESTAMP | Data utworzenia rekordu |

Płatnik nie musi być tożsamy z użytkownikiem-właścicielem subskrypcji. Rozdzielenie tych encji odzwierciedla rzeczywistą praktykę, w której dział IT zarządza certyfikatem, natomiast fakturę opłaca centrala finansowa lub spółka-córka.

### 2.4. Tabela `subscriptions` (subskrypcje)

Jest to tabela faktów systemu — przechowuje szczegóły każdej pozycji podlegającej odnowie.

| Kolumna | Typ | Opis |
|---------|-----|------|
| `id` | INT UNSIGNED, PK, AUTO_INCREMENT | Identyfikator subskrypcji |
| `name` | VARCHAR(255) | Nazwa (np. „SSL Wildcard Main”) |
| `subscription_type` | ENUM | Typ: SSL_CERTIFICATE, SAAS, DOMAIN, CLOUD_SUPPORT, CODE_SIGNING, OTHER |
| `expiry_date` | DATE | Data wygaśnięcia |
| `user_id` | INT UNSIGNED, FK → users.id | Właściciel biznesowy |
| `payer_id` | INT UNSIGNED, FK → payers.id | Podmiot płacący |
| `status` | ENUM | pending, active, renewal_in_progress, expired |
| `annual_cost` | DECIMAL(10,2) | Roczny koszt subskrypcji |
| `billing_cycle` | ENUM | monthly, annual, multi_year |
| `currency` | CHAR(3) | Waluta (domyślnie PLN) |
| `payment_status` | ENUM | paid, due_soon, overdue, not_applicable |
| `last_payment_date` | DATE, NULL | Data ostatniej płatności |
| `auto_renew` | TINYINT(1) | Flaga automatycznego odnowienia |
| `notes` | TEXT, NULL | Notatki operacyjne |
| `created_at` | TIMESTAMP | Data utworzenia rekordu |

Rozszerzenie modelu o pola `annual_cost`, `payment_status` i `subscription_type` wykracza poza minimalny schemat certyfikatów, czyniąc system uniwersalnym asystentem subskrypcji IT.

### 2.5. Relacje i klucze obce

```
users (1) ──────< (N) subscriptions (N) >────── (1) payers
```

- **`fk_subscriptions_user`**: `subscriptions.user_id` → `users.id` (ON DELETE RESTRICT, ON UPDATE CASCADE);
- **`fk_subscriptions_payer`**: `subscriptions.payer_id` → `payers.id` (ON DELETE RESTRICT, ON UPDATE CASCADE).

Zastosowanie `ON DELETE RESTRICT` zapobiega usunięciu użytkownika lub płatnika, który posiada przypisane subskrypcje — wymusza to najpierw przeniesienie lub usunięcie powiązanych rekordów, co chroni integralność danych historycznych.

Indeksy na kolumnach `expiry_date`, `status`, `payment_status` i `subscription_type` optymalizują zapytania dashboardu, w tym filtrowanie pozycji wygasających w ciągu 30 dni oraz agregację kwot do zapłaty.

### 2.6. Dane demonstracyjne (mock data)

W celu walidacji interfejsu wprowadzono zestaw danych testowych:

- **3 użytkowników** o rolach ADMIN, MANAGER, OPERATOR;
- **2 płatników** reprezentujących różne firmy;
- **10 subskrypcji** obejmujących:
  - pozycje wygasające w ciągu kilku dni (priorytet krytyczny);
  - pozycje z terminem za około pół roku;
  - pozycje już przeterminowane;
  - pozycje z różnym statusem płatności (paid, due_soon, overdue).

Taki rozkład dat i statusów umożliwia wizualną weryfikację mechanizmów podświetlania wierszy, kart KPI oraz sekcji „Payments Requiring Action”.

### 2.7. Zapytania reprezentatywne

Kluczowe zapytanie backendu łączy wszystkie encje:

```sql
SELECT s.*, u.first_name, u.last_name, p.company_name
FROM subscriptions s
INNER JOIN users u ON s.user_id = u.id
INNER JOIN payers p ON s.payer_id = p.id
ORDER BY s.expiry_date ASC;
```

Zapytanie to jest wykonywane metodą `getAllSubscriptions()` klasy `SubscriptionManager` z wykorzystaniem prepared statement PDO, co eliminuje ryzyko SQL Injection niezależnie od źródła parametrów w przyszłych rozszerzeniach (np. filtrowanie po stronie serwera).

---

## PODSUMOWANIE CZĘŚCI I

W części wstępnej pracy zdefiniowano problem biznesowy związany z rozproszonym zarządzaniem certyfikatami i subskrypcjami IT oraz przedstawiono cel budowy systemu CertiSub Assistant. Przeprowadzono studium wykonalności technologicznej, uzasadniając wybór stosu LAMP po stronie serwera oraz Vue.js z Tailwind CSS po stronie klienta jako optymalnego kompromisu między prostotą wdrożenia a jakością doświadczenia użytkownika.

Szczegółowo opisano architekturę relacyjnej bazy danych, uwzględniając struktury tabel, typy enumeracyjne statusów, relacje kluczy obcych oraz znaczenie poszczególnych encji w procesie odnowień i rozliczeń.

Kolejna część pracy (rozdziały implementacyjne) powinna omówić szczegóły klas PHP (`Database`, `SubscriptionManager`, `SubscriptionHelper`), strukturę plików `index.php` (landing page) i `dashboard.php` (panel aplikacji) oraz scenariusze testowe weryfikujące poprawność działania wyszukiwarki i mechanizmów priorytetyzacji.

---

*Koniec dokumentu — szacunkowa objętość po przeniesieniu do .doc z formatowaniem akademickim (czcionka 12 pt, interlinia 1,5): ok. 10–15 stron.*
