# Zrzuty ekranu do pracy pisemnej — co zostało do zrobienia

Stan na 2026-10-26. W pracy jest **28 zrzutów na miejscu** i **4 puste ramki**. Ten plik opisuje
dokładnie te cztery: gdzie kliknąć, co ma być widać i pod jaką nazwą zapisać plik.

Po wrzuceniu plików nie trzeba nic zmieniać w kodzie — nazwy są już wpisane w
`docs/thesis_helpers.py`, a praca sama wstawi obrazek zamiast ramki przy ponownym wygenerowaniu.

## Gdzie zapisywać

Wszystkie pliki trafiają do:

```
C:\Users\Lumis\Documents\MEGA\PC\UNI US\3 Rok\SDI seminarium\screenshoty aplikacji\z czatu\
```

Nazwy plików muszą być **dokładnie** takie jak niżej (z numerem i myślnikami).

Zrzuty rób **wycinkiem ekranu** (klawisze `Win` + `Shift` + `S`, zaznacz prostokąt) i zaznaczaj
samą treść strony — bez paska zakładek i paska adresu przeglądarki. Te cztery pliki nie są
przycinane automatycznie, więc to, co zaznaczysz, trafi do pracy jeden do jednego.

## Przygotowanie (raz, przed robieniem zrzutów)

1. Uruchom Laragon (Apache i MySQL).
2. Odśwież dane demonstracyjne — wszystkie ekrany mają wtedy komplet wypełnionych pól:

```bash
cd C:\laragon\www\assistent_subscription && php scripts/seed-demo-data.php --force
```

3. Wejdź na `http://localhost/assistent_subscription/login.php` i zaloguj się **swoim kontem
   administratora** (`mobi.litosh@gmail.com`). Zrzuty mają pokazywać pełny widok administratora.
4. Sprawdź, że w prawym górnym rogu wybrany jest język **PL**.
5. Rozszerz okno przeglądarki na cały ekran — układ dwukolumnowy pokazuje się dopiero od
   szerokości około 1400 pikseli, a dwa z czterech zrzutów właśnie na nim polegają.

---

## Zrzut 1 — okno „Wyślij zaproszenie”

**Plik:** `17-wyslij-zaproszenie.png`
**Trafia do:** rozdział 6 (proces odnowień), obok opisu wysyłki zaproszeń.

**Jak dojść:**

1. Menu po lewej → **Certyfikaty**.
2. Kliknij w wiersz **„Pieczęć kwalifikowana — NovaTech”** (wygasa za 4 dni, priorytet krytyczny).
3. W panelu, który wysunie się z prawej strony, naciśnij **✉️ Wyślij zaproszenie**.

**Co ma być widać:** całe okno zaproszenia — wybór szablonu, odbiorca, temat, podgląd treści
wiadomości z podstawionymi danymi (imię, numer seryjny, data ważności) i lista załączników.

**Ważne:** zrób zrzut **przed** naciśnięciem przycisku wysyłki. Samej wiadomości nie wysyłaj tutaj —
wyślesz ją przy zrzucie 2.

---

## Zrzut 2 — wiadomość z zaproszeniem w skrzynce Mailtrap

**Plik:** `18-mailtrap-zaproszenie.png`
**Trafia do:** rozdział 6, zaraz za zrzutem 1.

**Jak dojść:**

1. W oknie z poprzedniego punktu naciśnij **Wyślij**. Poczta lokalnie idzie do piaskownicy
   Mailtrap, więc żadna wiadomość nie trafi do prawdziwej skrzynki.
2. Otwórz `https://mailtrap.io` → **Email Testing** → skrzynka **sandbox**.
3. Otwórz wiadomość, która właśnie przyszła (temat zaczyna się od „Odnowienie certyfikatu”).

**Co ma być widać:** nagłówek wiadomości (od, do, temat) i treść HTML z danymi certyfikatu oraz
widoczny załącznik. Najlepiej zakładka **HTML** z panelem załączników.

---

## Zrzut 3 — ekran sprawdzenia wniosku z e-maila

**Plik:** `19-wniosek-sprawdzenie.png`
**Trafia do:** rozdział 6, podrozdział o wnioskach z poczty.

**Jak dojść:**

1. Menu po lewej → **Wnioski e-mail**.
2. Zakładka **Do sprawdzenia** → kliknij wniosek **„Wniosek o certyfikat kwalifikowany - Anna
   Nowak”**.

**Co ma być widać:** obie kolumny naraz — po lewej treść wiadomości od Anny Nowak, po prawej
wypełniony formularz. Szczególnie ważny jest niebieski pasek nad sekcją „Firma”: *„Dane pobrano z
Białej Listy podatników VAT … Status VAT: Czynny”* oraz wypełnione pola firmy (Orlen Spółka
Akcyjna, Chemików 7, 09-411 Płock) — to one pokazują działanie rejestru.

**Ważne:** niczego nie zatwierdzaj ani nie odrzucaj — wniosek ma zostać w stanie „do sprawdzenia”.
Jeśli przez pomyłkę coś zatwierdzisz, wystarczy ponownie uruchomić polecenie z punktu 2
przygotowania.

---

## Zrzut 4 — wynik pełnego przebiegu testów

**Plik:** `20-przebieg-testow.png`
**Trafia do:** rozdział 7 (testy), na końcu podrozdziału 7.5.

**Jak dojść:** otwórz PowerShell w katalogu projektu i uruchom:

```bash
cd C:\laragon\www\assistent_subscription && $env:RUN_INTEGRATION_TESTS=1; composer test
```

Przebieg trwa około 45 sekund (MySQL musi być uruchomiony).

**Co ma być widać:** nagłówek PHPUnit z wersją PHP, paski kropek i ostatnia linia
**`OK (394 tests, 19486 assertions)`**. Zaznacz okno terminala od linii z poleceniem do tej
ostatniej linii.

Gdyby wyszła inna liczba testów niż 394 — nie poprawiaj jej ręcznie w pracy, tylko napisz mi;
liczby w rozdziale 7 są brane z tego samego przebiegu i muszą się zgadzać.

---

## Po wrzuceniu plików

Wygeneruj pracę jeszcze raz:

```bash
cd C:\laragon\www\assistent_subscription\docs && python generate_thesis_pdfs.py
```

PDF powstaje w `docs/Szkic pracy Litosh.pdf` i w `Pobrane`. W spisie rysunków cztery nowe pozycje
mają się pojawić bez dopisku o ramce.

## Uwaga na marginesie — konta w bazie

Na liście „Konta i role” poza kontami demonstracyjnymi są jeszcze trzy stare konta testowe:
`mazlee505@gmail.com`, `lee@gmail.com` i `niklates@gmail.com` (nazwa „nikola tesla”). Zrzut tego
ekranu w pracy już jest i je obejmuje. Jeśli wolisz, żeby ich tam nie było, napisz — wyłączę je
albo usuniemy, i zrobisz ten jeden zrzut jeszcze raz.
