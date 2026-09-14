#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generate thesis PDF documents for Maksym Litosh — CertiSub Assistant."""

from __future__ import annotations

from pathlib import Path

from fpdf import FPDF

FONT_REGULAR = r"C:\Windows\Fonts\arial.ttf"
FONT_BOLD = r"C:\Windows\Fonts\arialbd.ttf"
FONT_ITALIC = r"C:\Windows\Fonts\ariali.ttf"

OUTPUT_DIR = Path(__file__).resolve().parent
DOWNLOADS_DIR = Path(r"C:\Users\Lumis\Downloads")


class ThesisPDF(FPDF):
    def __init__(self) -> None:
        super().__init__()
        self.set_margins(20, 20, 20)
        self.add_font("Arial", "", FONT_REGULAR)
        self.add_font("Arial", "B", FONT_BOLD)
        self.add_font("Arial", "I", FONT_ITALIC)
        self.set_auto_page_break(auto=True, margin=20)

    def usable_width(self) -> float:
        return self.w - self.l_margin - self.r_margin

    def body_text(self, size: int = 12) -> None:
        self.set_font("Arial", size=size)

    def heading(self, text: str, size: int = 14) -> None:
        self.set_font("Arial", "B", size)
        self.multi_cell(self.usable_width(), 8, text)
        self.ln(2)

    def subheading(self, text: str, size: int = 12) -> None:
        self.set_font("Arial", "B", size)
        self.multi_cell(self.usable_width(), 7, text)
        self.ln(1)

    def paragraph(self, text: str, size: int = 12) -> None:
        self.body_text(size)
        self.multi_cell(self.usable_width(), 6, text)
        self.ln(2)

    def bullet(self, text: str, size: int = 12) -> None:
        self.body_text(size)
        self.multi_cell(self.usable_width(), 6, f"- {text}")
        self.ln(1)


def save_pdf(pdf: ThesisPDF, filename: str) -> list[Path]:
    paths: list[Path] = []
    for directory in (OUTPUT_DIR, DOWNLOADS_DIR):
        directory.mkdir(parents=True, exist_ok=True)
        path = directory / filename
        pdf.output(str(path))
        paths.append(path)
    return paths


def generate_zalozenia() -> list[Path]:
    pdf = ThesisPDF()
    pdf.add_page()
    pdf.heading("Założenia przygotowywanej pracy", 16)

    pdf.subheading("Cel projektu")
    pdf.paragraph(
        "Celem projektowanej aplikacji jest stworzenie systemu webowego wspierającego "
        "zarządzanie certyfikatami cyfrowymi, subskrypcjami usług IT oraz płatnościami "
        "cyklicznymi w środowisku firmowym i prywatnym. System ma umożliwiać centralne "
        "gromadzenie informacji o datach wygaśnięcia, statusach płatności, właścicielach "
        "biznesowych oraz podmiotach rozliczeniowych powiązanych z poszczególnymi pozycjami."
    )
    pdf.paragraph(
        "Projekt zakłada stworzenie rozwiązania CertiSub Assistant integrującego w jednym "
        "miejscu dwa asystenty: firmowy (certyfikaty SSL, licencje SaaS, domeny, wsparcie "
        "chmurowe) oraz prywatny (Netflix, Spotify, streaming, subskrypcje aplikacji). "
        "Użytkownik uzyskuje dostęp do przejrzystego panelu menedżerskiego z alertami "
        "terminów, wyszukiwarką, listą zadań oraz modułem osobistego menedżera subskrypcji."
    )

    pdf.subheading("Wymagania funkcjonalne")
    pdf.paragraph("Projektowany system powinien umożliwiać:")
    for item in [
        "rejestrację i logowanie użytkowników bez hasła (kod OTP wysyłany na e-mail),",
        "zarządzanie profilem użytkownika oraz usuwanie konta,",
        "przeglądanie panelu firmowego i prywatnego w jednej aplikacji,",
        "dodawanie subskrypcji do osobistego menedżera użytkownika,",
        "prezentację statystyk KPI (liczba subskrypcji, wygasające pozycje, zaległe płatności, wydatki),",
        "wyszukiwanie i filtrowanie subskrypcji według typu, statusu płatności i priorytetu,",
        "wyświetlanie listy zadań (To-Do) z pozycjami wymagającymi działania,",
        "prezentację osi czasu odnowienia wybranej subskrypcji,",
        "przypisywanie właściciela i płatnika do subskrypcji firmowych,",
        "wysyłanie przypomnień e-mail o zbliżających się płatnościach (cron),",
        "obsługę wielu języków interfejsu (PL, EN, ES, DE, UK),",
        "obsługę panelu administracyjnego z podziałem ról użytkowników.",
    ]:
        pdf.bullet(item)

    pdf.subheading("Wymagania niefunkcjonalne")
    pdf.paragraph("System powinien spełniać następujące wymagania niefunkcjonalne:")
    for item in [
        "zapewnienie bezpieczeństwa danych użytkowników,",
        "autoryzacja i uwierzytelnianie dostępu do systemu (OTP, sesje PHP, CSRF),",
        "odporność na SQL Injection poprzez prepared statements (PDO),",
        "responsywność interfejsu użytkownika,",
        "możliwość korzystania z systemu przy użyciu popularnych przeglądarek internetowych,",
        "możliwość dalszej rozbudowy funkcjonalności aplikacji,",
        "czytelny i intuicyjny interfejs użytkownika,",
        "wydajna komunikacja pomiędzy modułami systemu,",
        "niski koszt wdrożenia dzięki technologiom open-source (stos LAMP).",
    ]:
        pdf.bullet(item)

    pdf.subheading("Role użytkowników")
    pdf.paragraph("W systemie przewiduje się następujące role użytkowników:")
    pdf.subheading("Administrator", 11)
    pdf.paragraph(
        "Administrator odpowiada za pełny dostęp do panelu firmowego, zarządzanie "
        "użytkownikami, płatnikami i subskrypcjami oraz nadzór nad poprawnym działaniem systemu."
    )
    pdf.subheading("Menedżer", 11)
    pdf.paragraph(
        "Menedżer przegląda subskrypcje przypisane do swojego zakresu odpowiedzialności, "
        "monitoruje terminy odnowień i statusy płatności w segmencie firmowym."
    )
    pdf.subheading("Operator", 11)
    pdf.paragraph(
        "Operator korzysta z panelu prywatnego lub firmowego w zakresie operacyjnym: "
        "dodaje własne subskrypcje do menedżera, śledzi terminy płatności i otrzymuje przypomnienia."
    )

    pdf.add_page()
    pdf.subheading("Założenia modułu powiadomień i menedżera subskrypcji")
    pdf.paragraph(
        "Jednym z kluczowych elementów projektowanego systemu będzie moduł przypomnień "
        "oraz osobisty menedżer subskrypcji powiązany z kontem użytkownika."
    )
    pdf.paragraph("Moduł będzie analizował między innymi:")
    for item in [
        "nazwę usługi i typ subskrypcji,",
        "datę następnej płatności lub wygaśnięcia,",
        "koszt miesięczny lub roczny,",
        "status płatności (opłacone, wkrótce, zaległe),",
        "priorytet odnowienia (krytyczne, ostrzeżenie, wygasłe),",
        "powiązanie z właścicielem i płatnikiem (segment firmowy).",
    ]:
        pdf.bullet(item)
    pdf.paragraph(
        "Na podstawie zgromadzonych danych system będzie generował alerty w panelu oraz "
        "wysyłał wiadomości e-mail 3 dni przed terminem płatności. W pierwszej wersji "
        "zaimplementowano mechanizm cron oraz integrację z PHPMailer (SMTP / Mailtrap)."
    )

    pdf.subheading("Założenia technologiczne")
    pdf.paragraph("Realizacja projektu planowana jest przy wykorzystaniu następujących technologii:")
    for item in [
        "PHP 8.x – warstwa backendowa systemu,",
        "Vue.js 3 – reaktywna warstwa frontendowa dashboardu,",
        "Tailwind CSS – stylizacja interfejsu użytkownika,",
        "MySQL / MariaDB – relacyjna baza danych,",
        "PDO – bezpieczny dostęp do bazy danych,",
        "PHPMailer – wysyłka kodów OTP i przypomnień e-mail,",
        "REST API (JSON) – komunikacja dashboardu z backendem,",
        "PHPUnit – testy jednostkowe i integracyjne,",
        "Apache (Laragon) – środowisko uruchomieniowe deweloperskie.",
    ]:
        pdf.bullet(item)
    pdf.paragraph(
        "Projektowana architektura będzie oparta na modelu klient–serwer z hybrydowym "
        "podejściem: server-side rendering danych w PHP oraz client-side interactivity w Vue.js."
    )

    pdf.subheading("Oczekiwane rezultaty projektu")
    pdf.paragraph(
        "Rezultatem pracy będzie działający prototyp aplikacji webowej CertiSub Assistant "
        "umożliwiający zarządzanie subskrypcjami firmowymi i prywatnymi, monitorowanie "
        "terminów odnowień, analizę wydatków oraz wysyłanie przypomnień e-mail. "
        "Przewiduje się również przeprowadzenie testów funkcjonalnych, testów bezpieczeństwa "
        "modułu OTP oraz ocenę użyteczności interfejsu dashboardu."
    )

    pdf.subheading("Kamienie milowe")
    pdf.paragraph("Poniżej przedstawiono orientacyjne terminy realizacji prac:")
    for item in [
        "06.2026–07.2026 – dokończenie logiki aplikacji: CRUD subskrypcji firmowych, "
        "edycja i usuwanie w menedżerze osobistym, pełna kontrola dostępu (RBAC), rozbudowa API;",
        "08.2026 – wprowadzanie poprawek, rozszerzenie przypomnień e-mail, dopracowanie "
        "wyglądu programu, poprawa jakości życia (QoL) aplikacji;",
        "09.2026 – testy, konsultacje z promotorem, ostateczne poprawki programu;",
        "10.2026–12.2026 – napisanie części pisemnej pracy i ostateczne przygotowanie do obrony.",
    ]:
        pdf.bullet(item)

    return save_pdf(pdf, "za\u0142o\u017cenia i daty Litosh.pdf")


def generate_szkic() -> list[Path]:
    pdf = ThesisPDF()

    # Cover
    pdf.add_page()
    pdf.ln(35)
    pdf.set_font("Arial", "B", 18)
    pdf.cell(0, 10, "U N I W E R S Y T E T   Ś L Ą S K I", align="C", new_x="LMARGIN", new_y="NEXT")
    pdf.cell(0, 10, "W   K A T O W I C A C H", align="C", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(15)
    pdf.set_font("Arial", "", 14)
    pdf.cell(0, 8, "Informatyka stacjonarna I stopnia", align="C", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(20)
    pdf.set_font("Arial", "B", 14)
    pdf.cell(0, 8, "Maksym Litosh", align="C", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(10)
    pdf.set_font("Arial", "", 13)
    pdf.multi_cell(0, 8, "Informatyczny asystent zarządzania certyfikatami\ni subskrypcjami (CertiSub Assistant)", align="C")
    pdf.ln(15)
    pdf.set_font("Arial", "", 14)
    pdf.cell(0, 8, "Praca inżynierska", align="C", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(8)
    pdf.cell(0, 8, "napisana pod kierunkiem Jarosław Utracki", align="C", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(25)
    pdf.cell(0, 8, "Katowice 2026", align="C")

    # Table of contents
    pdf.add_page()
    pdf.heading("Spis treści", 16)
    toc = [
        "1. Wstęp",
        "1.1. Analiza problemu zarządzania certyfikatami i subskrypcjami",
        "1.2. Charakterystyka problemu w środowisku firmowym i prywatnym",
        "1.3. Proces odnowień i cykl życia subskrypcji IT",
        "1.4. Rola technologii informatycznych w monitorowaniu płatności cyklicznych",
        "2. Badanie rynkowe i przegląd istniejących rozwiązań",
        "2.1. Problem rozproszonego zarządzania subskrypcjami",
        "2.2. Analiza istniejących narzędzi i platform",
        "2.3. Technologie informatyczne stosowane w systemach zarządzania subskrypcjami",
        "2.4. Systemy alertów i powiadomień o terminach płatności",
        "2.5. Wnioski z analizy i uzasadnienie realizacji projektu",
        "3. Założenia projektowe aplikacji",
        "3.1. Cel i zakres projektu",
        "3.2. Wymagania funkcjonalne",
        "3.3. Wymagania niefunkcjonalne",
        "3.4. Role użytkowników i przypadki użycia",
        "3.5. Architektura ogólna systemu",
        "4. Projekt bazy danych",
        "4.1. Model pojęciowy danych",
        "4.2. Diagram ERD",
        "4.3. Struktura bazy danych i relacje między encjami",
        "4.4. Integralność i bezpieczeństwo danych",
        "5. Projekt i implementacja aplikacji webowej",
        "5.1. Wykorzystane technologie i narzędzia",
        "5.2. Implementacja warstwy backendowej (PHP)",
        "5.3. Implementacja warstwy frontendowej (Vue.js, Tailwind CSS)",
        "5.4. Implementacja dashboardu i formularzy",
        "5.5. System autoryzacji OTP i uwierzytelniania użytkowników",
        "6. Moduł powiadomień i menedżera subskrypcji",
        "6.1. Założenia modułu przypomnień",
        "6.2. Analiza danych wejściowych i wyjściowych",
        "6.3. Mechanizm cron i wysyłka e-mail",
        "6.4. Integracja modułu z aplikacją webową",
        "7. Testowanie i ocena systemu",
        "7.1. Scenariusze testowe",
        "7.2. Testy funkcjonalne",
        "7.3. Testy integracyjne i bezpieczeństwa OTP",
        "7.4. Ocena użyteczności interfejsu dashboardu",
        "7.5. Analiza uzyskanych wyników",
        "8. Zakończenie",
        "8.1. Bibliografia",
        "8.2. Spis rysunków",
        "8.3. Spis tabel",
        "8.4. Załączniki",
    ]
    pdf.body_text(12)
    for line in toc:
        pdf.cell(0, 6, line, new_x="LMARGIN", new_y="NEXT")

    # Section 1
    pdf.add_page()
    pdf.heading("1. Wstęp", 14)
    pdf.subheading("1.1. Analiza problemu zarządzania certyfikatami i subskrypcjami")
    pdf.paragraph(
        "W ostatnich latach obserwuje się gwałtowny wzrost liczby usług cyfrowych "
        "opartych na modelu subskrypcyjnym. Zarówno organizacje, jak i użytkownicy "
        "indywidualni korzystają z szerokiego spektrum rozwiązań podlegających "
        "okresowej odnowie: certyfikatów SSL/TLS, licencji SaaS, rejestracji domen, "
        "planów wsparcia chmurowego, a także usług streamingowych i aplikacji "
        "miesięcznych. Każda z tych pozycji wiąże się z konkretną datą wygaśnięcia, "
        "kosztem oraz odpowiedzialnością po stronie wyznaczonej osoby lub podmiotu finansującego."
    )
    pdf.paragraph(
        "Proces monitorowania terminów bywa skomplikowany i czasochłonny. Dane o "
        "subskrypcjach często rozproszone są pomiędzy arkuszami kalkulacyjnymi, "
        "skrzynkami pocztowymi, portalami dostawców oraz dokumentacją papierową. "
        "Powoduje to utratę ciągłości usług, ryzyko zaległych płatności oraz "
        "konieczność wielokrotnych konsultacji między działem IT a finansami."
    )

    pdf.subheading("1.2. Charakterystyka problemu w środowisku firmowym i prywatnym")
    pdf.paragraph(
        "W środowisku firmowym zarządzanie certyfikatami i subskrypcjami wymaga "
        "uwzględnienia wielu czynników: typu usługi, daty wygaśnięcia, właściciela "
        "biznesowego, podmiotu płacącego, statusu płatności oraz priorytetu odnowienia. "
        "Nieodnowiony certyfikat SSL może spowodować awarie API i utratę zaufania klientów, "
        "a opóźniona płatność za SaaS — zawieszenie licencji i spadek produktywności zespołu."
    )
    pdf.paragraph(
        "W segmencie prywatnym użytkownicy coraz częściej mierzą się ze zjawiskiem "
        "„zmęczenia subskrypcjami” (subscription fatigue). Posiadają wiele usług "
        "miesięcznych — streaming, muzyka, chmura, narzędzia AI — bez jednego widoku "
        "łącznych wydatków i zbliżających się terminów płatności. Brak centralnego "
        "narzędzia prowadzi do nieplanowanych obciążeń budżetu domowego."
    )

    pdf.subheading("1.3. Proces odnowień i cykl życia subskrypcji IT")
    pdf.paragraph(
        "Cykl życia subskrypcji obejmuje rejestrację usługi, aktywne użytkowanie, "
        "monitorowanie terminu odnowienia, realizację płatności oraz ewentualne "
        "wygaszenie lub reaktywację. W praktyce organizacje stosują różne cykle "
        "rozliczeniowe: miesięczne (typowe dla usług prywatnych), roczne (certyfikaty, "
        "domeny) oraz wieloletnie (umowy enterprise)."
    )
    pdf.paragraph(
        "Współczesne podejście oparte wyłącznie na arkuszach kalkulacyjnych nie "
        "zapewnia alertów, wersjonowania zmian ani wieloużytkownikowego dostępu. "
        "Konieczne staje się stworzenie narzędzia informatycznego wspierającego "
        "proces podejmowania decyzji i umożliwiającego szybką identyfikację pozycji "
        "wymagających natychmiastowego działania."
    )

    pdf.add_page()
    pdf.subheading("1.4. Rola technologii informatycznych w monitorowaniu płatności cyklicznych")
    pdf.paragraph(
        "Dynamiczny rozwój aplikacji webowych, relacyjnych baz danych oraz systemów "
        "powiadomień e-mail stwarza nowe możliwości centralizacji informacji o "
        "subskrypcjach. Wykorzystanie architektury klient–serwer, prepared statements, "
        "uwierzytelniania passwordless (OTP) oraz reaktywnych interfejsów (Vue.js) "
        "pozwala na integrację rozproszonych danych i usprawnienie procesu monitorowania terminów."
    )
    pdf.paragraph(
        "Celem niniejszej pracy inżynierskiej jest zaprojektowanie i implementacja "
        "aplikacji webowej CertiSub Assistant wspierającej zarządzanie certyfikatami "
        "i subskrypcjami z wykorzystaniem nowoczesnych technologii informatycznych. "
        "Efektem końcowym pracy będzie działający prototyp systemu umożliwiający "
        "zarządzanie subskrypcjami firmowymi i prywatnymi, prezentację alertów w "
        "dashboardzie oraz wysyłanie przypomnień e-mail wspomagających proces odnowień."
    )

    # Section 2
    pdf.add_page()
    pdf.heading("2. Badanie rynkowe i przegląd istniejących rozwiązań", 14)
    pdf.subheading("2.1. Problem rozproszonego zarządzania subskrypcjami")
    pdf.paragraph(
        "Zarządzanie subskrypcjami IT oraz usługami cyfrowymi stanowi istotny problem "
        "zarówno w małych i średnich przedsiębiorstwach, jak i w gospodarstwach domowych. "
        "Każdego roku organizacje odnowiają dziesiątki certyfikatów, licencji i umów "
        "wsparcia, podczas gdy użytkownicy indywidualni opłacają coraz więcej usług "
        "miesięcznych. Jednocześnie informacje o terminach i kosztach pozostają rozproszone."
    )
    pdf.paragraph(
        "Proces monitorowania odnowień jest często rozproszony i opiera się na "
        "korzystaniu z wielu różnych źródeł: Excel, e-mail, portale AWS/Microsoft, "
        "aplikacje bankowe. Powoduje to trudności w szybkiej identyfikacji zaległych "
        "płatności oraz utrudnia szacowanie łącznych zobowiązań finansowych."
    )

    pdf.subheading("2.2. Analiza istniejących narzędzi i platform")
    pdf.paragraph(
        "W celu lepszego zrozumienia problematyki zarządzania subskrypcjami "
        "przeprowadzono analizę wybranych rozwiązań dostępnych obecnie na rynku. "
        "Do badania wybrano kategorie narzędzi umożliwiających śledzenie wydatków "
        "subskrypcyjnych, monitorowanie certyfikatów SSL oraz zarządzanie zasobami IT."
    )
    pdf.paragraph("Analizie poddano następujące rozwiązania:")
    for item in [
        "arkusze kalkulacyjne (Excel / Google Sheets) — powszechne w MŚP,",
        "menedżery subskrypcji osobistych (aplikacje mobilne typu tracker budżetu),",
        "systemy ITSM/CMDB (ServiceNow, Lansweeper) — segment enterprise,",
        "portale dostawców (AWS, Microsoft 365, operatorzy domen) — dane w silosach,",
        "narzędzia monitorowania certyfikatów SSL (SSL Labs, certbot, panele hostingowe).",
    ]:
        pdf.bullet(item)

    pdf.add_page()
    pdf.paragraph(
        "Wybrane rozwiązania zostały porównane pod względem funkcjonalności istotnych "
        "z punktu widzenia projektowanego systemu CertiSub Assistant."
    )
    pdf.ln(2)
    pdf.set_font("Arial", "B", 10)
    headers = ["Funkcjonalność", "Excel", "App mobilna", "ITSM", "Portale", "CertiSub"]
    widths = [48, 20, 24, 20, 22, 26]
    for header, width in zip(headers, widths):
        pdf.cell(width, 8, header, border=1)
    pdf.ln()
    pdf.set_font("Arial", "", 9)
    rows = [
        ("Centralny rejestr subskrypcji", "Tak", "Tak", "Tak", "Nie", "Tak"),
        ("Segment firmowy + prywatny", "Ograniczone", "Nie", "Tak", "Nie", "Tak"),
        ("Alerty terminów odnowień", "Nie", "Tak", "Tak", "Ograniczone", "Tak"),
        ("Przypisanie właściciela i płatnika", "Ręcznie", "Nie", "Tak", "Nie", "Tak"),
        ("Dashboard KPI i priorytety", "Nie", "Ograniczone", "Tak", "Nie", "Tak"),
        ("Wyszukiwarka i filtry", "Ograniczone", "Tak", "Tak", "Nie", "Tak"),
        ("Logowanie OTP (bez hasła)", "Nie", "Rzadko", "Nie", "Nie", "Tak"),
        ("Wielojęzyczny interfejs", "Nie", "Tak", "Tak", "Tak", "Tak"),
        ("Niski koszt wdrożenia", "Tak", "Tak", "Nie", "Tak", "Tak"),
        ("Przypomnienia e-mail (cron)", "Nie", "Tak", "Tak", "Ograniczone", "Tak"),
    ]
    for row in rows:
        for value, width in zip(row, widths):
            pdf.cell(width, 7, value, border=1)
        pdf.ln()
    pdf.ln(3)
    pdf.body_text(11)
    pdf.multi_cell(0, 6, "Tabela przedstawia porównanie najważniejszych funkcji analizowanych rozwiązań.")
    pdf.ln(2)
    pdf.paragraph(
        "Na podstawie przeprowadzonej analizy można zauważyć, że dostępne rozwiązania "
        "albo są zbyt ogólne (arkusze), albo zbyt drogie i złożone (ITSM), albo "
        "skupiają się wyłącznie na segmencie prywatnym (aplikacje mobilne). Żadne z "
        "analizowanych narzędzi nie łączy w jednej platformie certyfikatów firmowych "
        "z subskrypcjami prywatnymi przy zachowaniu niskiego kosztu wdrożenia."
    )

    pdf.subheading("2.3. Technologie informatyczne stosowane w systemach zarządzania subskrypcjami")
    pdf.subheading("2.3.1. Architektura klient–serwer", 11)
    pdf.paragraph(
        "Współczesne aplikacje webowe do zarządzania danymi są realizowane w architekturze "
        "klient–serwer. Frontend odpowiada za prezentację danych i interakcje użytkownika, "
        "backend realizuje logikę biznesową i komunikuje się z bazą danych. W projekcie "
        "CertiSub Assistant zastosowano hybrydę PHP SSR z Vue.js po stronie klienta."
    )
    pdf.subheading("2.3.2. Relacyjne bazy danych", 11)
    pdf.paragraph(
        "Encje użytkowników, płatników, subskrypcji i kodów OTP naturalnie mapują się "
        "na tabele połączone kluczami obcymi. W projekcie wykorzystano MySQL z PDO "
        "i prepared statements, co zapewnia integralność danych i ochronę przed SQL Injection."
    )

    pdf.add_page()
    pdf.subheading("2.3.3. Technologie frontendowe", 11)
    pdf.paragraph(
        "Dashboard wykorzystuje Vue.js 3 (CDN) do reaktywnego wyszukiwania, filtrowania "
        "i przełączania widoków bez przeładowania strony. Tailwind CSS zapewnia responsywny "
        "i spójny interfejs. Takie podejście eliminuje konieczność bundlera na etapie MVP."
    )
    pdf.subheading("2.3.4. Uwierzytelnianie passwordless (OTP)", 11)
    pdf.paragraph(
        "Coraz popularniejszym trendem w aplikacjach webowych jest rezygnacja z haseł "
        "na rzecz kodów jednorazowych wysyłanych e-mailem. W CertiSub Assistant "
        "zaimplementowano moduł OTP z limitami prób, hashowaniem kodów i integracją PHPMailer."
    )

    pdf.subheading("2.4. Systemy alertów i powiadomień o terminach płatności")
    pdf.paragraph(
        "Automatyczne powiadomienia o zbliżających się terminach płatności stanowią "
        "kluczowy element skutecznego zarządzania subskrypcjami. W projekcie zastosowano "
        "zadanie cron uruchamiane codziennie, które wyszukuje subskrypcje z płatnością "
        "za 3 dni i wysyła wiadomości HTML do użytkownika. Mechanizm ten uzupełnia "
        "alerty wizualne w dashboardzie (kolorowe wiersze, karty KPI, lista To-Do)."
    )

    pdf.subheading("2.5. Wnioski z analizy i uzasadnienie realizacji projektu")
    pdf.paragraph(
        "Przeprowadzona analiza problemu zarządzania certyfikatami i subskrypcjami "
        "oraz badanie dostępnych rozwiązań informatycznych pozwoliły zidentyfikować "
        "szereg ograniczeń występujących w obecnie funkcjonujących systemach. Większość "
        "narzędzi koncentruje się na jednym segmencie (firmowym lub prywatnym) albo "
        "wymaga ręcznego utrzymania danych bez alertów i wieloużytkownikowego dostępu."
    )
    pdf.paragraph(
        "Analiza wykazała również, że dostępne rozwiązania rzadko integrują monitoring "
        "certyfikatów SSL, licencji SaaS i subskrypcji streamingowych w ramach jednego "
        "interfejsu. Dane są zazwyczaj rozproszone między arkuszami, portalami dostawców "
        "i skrzynkami pocztowymi, co utrudnia szybkie podejmowanie decyzji."
    )
    pdf.paragraph(
        "Na podstawie przeprowadzonych analiz uzasadniona jest realizacja aplikacji "
        "webowej CertiSub Assistant. Projektowany system będzie łączył funkcjonalności "
        "dashboardu firmowego i prywatnego, uwierzytelniania OTP, menedżera subskrypcji "
        "użytkownika oraz modułu przypomnień e-mail. Takie podejście pozwoli zwiększyć "
        "efektywność monitorowania terminów oraz usprawnić kontrolę wydatków subskrypcyjnych."
    )
    pdf.paragraph(
        "Wnioski przedstawione w niniejszym rozdziale stanowią podstawę do opracowania "
        "założeń projektowych systemu, które zostaną przedstawione w kolejnym rozdziale pracy."
    )

    return save_pdf(pdf, "Szkic pracy Litosh.pdf")


if __name__ == "__main__":
    zalozenia_paths = generate_zalozenia()
    szkic_paths = generate_szkic()
    print("Wygenerowano:")
    for path in zalozenia_paths + szkic_paths:
        print(" ", str(path).encode("ascii", "replace").decode("ascii"))
