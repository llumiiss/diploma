#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generate thesis PDF documents for Maksym Litosh — CertiSub Assistant."""

from __future__ import annotations

import re
from pathlib import Path

from fpdf import FPDF
from fpdf.fonts import FontFace
from PIL import Image

from thesis_late_chapters import chapter3, chapter4, chapters_5_to_8

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
        self.figure_count = 0
        self.table_count = 0
        self.figures: list[tuple[int, str, int]] = []
        self.tables: list[tuple[int, str, int]] = []
        self.numbered_footer = False

    def footer(self) -> None:
        if not self.numbered_footer or self.page_no() < 4:
            return
        self.set_y(-12)
        self.set_font("Arial", "", 9)
        self.set_text_color(90, 90, 90)
        self.cell(0, 6, str(self.page_no()), align="C")
        self.set_text_color(0, 0, 0)

    def usable_width(self) -> float:
        return self.w - self.l_margin - self.r_margin

    def body_text(self, size: int = 12) -> None:
        self.set_font("Arial", size=size)

    def _section(self, text: str, is_chapter: bool) -> None:
        match = re.match(r"^(\d+(?:\.\d+)*)\.?\s", text)
        if match is None:
            return
        level = match.group(1).count(".")
        self.start_section(text, level=min(level, 2))

    def heading(self, text: str, size: int = 14) -> None:
        self._section(text, True)
        self.set_font("Arial", "B", size)
        self.multi_cell(self.usable_width(), 8, text)
        self.ln(2)

    def subheading(self, text: str, size: int = 12) -> None:
        self._section(text, False)
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

    def figure_caption(self, caption: str, y: float | None = None) -> None:
        self.figure_count += 1
        self.figures.append((self.figure_count, caption, self.page_no()))
        if y is not None:
            self.set_xy(self.l_margin, y)
        self.set_font("Arial", "I", 10)
        self.multi_cell(self.usable_width(), 5, f"Rysunek {self.figure_count}. {caption}", align="C")
        self.ln(3)

    def figure_placeholder(self, caption: str, height: float = 80) -> None:
        """Bordered box marking where the author will paste a screenshot/diagram."""
        self.placeholder_count = getattr(self, "placeholder_count", 0) + 1
        if self.get_y() + height + 12 > self.page_break_trigger:
            self.add_page()
        x, y, width = self.l_margin, self.get_y(), self.usable_width()
        self.set_draw_color(150, 150, 150)
        self.rect(x, y, width, height, style="D")
        self.set_draw_color(0, 0, 0)
        self.set_xy(x, y + height / 2 - 6)
        self.set_text_color(120, 120, 120)
        self.set_font("Arial", "I", 11)
        self.multi_cell(width, 6, f"[miejsce na rysunek — {caption}]", align="C")
        self.set_text_color(0, 0, 0)
        self.set_xy(x, y + height + 2)
        self.figure_caption(caption)

    def figure_image(self, path: str, caption: str, width: float | None = None) -> None:
        with Image.open(path) as img:
            ratio = img.height / img.width
        width = min(width or self.usable_width(), self.usable_width())
        height = width * ratio
        if self.get_y() + height + 12 > self.page_break_trigger:
            self.add_page()
        x = self.l_margin + (self.usable_width() - width) / 2
        y = self.get_y()
        self.set_draw_color(190, 190, 190)
        self.image(path, x=x, y=y, w=width)
        self.rect(x, y, width, height)
        self.set_draw_color(0, 0, 0)
        self.set_xy(self.l_margin, y + height + 2)
        self.figure_caption(caption)

    def data_table(self, caption: str, headers: list[str], rows: list[list[str]],
                   widths: tuple[float, ...] | None = None, size: float = 9) -> None:
        self.table_count += 1
        self.tables.append((self.table_count, caption, self.page_no()))
        self.set_font("Arial", "I", 10)
        if self.get_y() + 30 > self.page_break_trigger:
            self.add_page()
        self.multi_cell(self.usable_width(), 5, f"Tabela {self.table_count}. {caption}")
        self.ln(1)
        self.set_font("Arial", "", size)
        with self.table(
            col_widths=widths,
            text_align="LEFT",
            line_height=4.6,
            headings_style=FontFace(emphasis="BOLD", fill_color=(222, 230, 242)),
        ) as table:
            head = table.row()
            for header in headers:
                head.cell(header)
            for row in rows:
                body = table.row()
                for value in row:
                    body.cell(str(value))
        self.ln(4)


def render_toc(pdf: FPDF, outline: list) -> None:
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Arial", "B", 16)
    pdf.cell(0, 10, "Spis treści", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(3)
    for section in outline:
        level = section.level
        indent = (0, 4, 10)[min(level, 2)]
        bold = level == 0
        pdf.set_font("Arial", "B" if bold else "", 10.5 if bold else 9.5)
        name = section.name
        page = str(section.page_number)
        link = pdf.add_link(page=section.page_number)
        width = pdf.epw - indent
        dots_room = width - pdf.get_string_width(name) - pdf.get_string_width(page) - 3
        dots = "." * max(int(dots_room / pdf.get_string_width(".")), 2)
        pdf.set_x(pdf.l_margin + indent)
        pdf.cell(width, 5.2, f"{name} {dots} {page}", link=link, new_x="LMARGIN", new_y="NEXT")
        if bold:
            pdf.ln(0.8)


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

    # Table of contents (page numbers are filled in after all sections are written)
    pdf.add_page()
    pdf.insert_toc_placeholder(render_toc, pages=2)

    # Section 1
    pdf.add_page()
    pdf.heading("1. Wstęp", 14)
    pdf.subheading("1.1. Analiza problemu zarządzania certyfikatami i subskrypcjami")
    pdf.paragraph(
        "W ostatnich latach obserwuje się gwałtowny wzrost liczby usług cyfrowych "
        "opartych na modelu subskrypcyjnym oraz liczby certyfikatów elektronicznych "
        "towarzyszących codziennej działalności organizacji. Przedsiębiorstwa "
        "korzystają z szerokiego spektrum rozwiązań podlegających okresowej odnowie: "
        "kwalifikowanych podpisów i pieczęci elektronicznych, certyfikatów SSL/TLS, "
        "certyfikatów code signing, rejestracji domen, licencji SaaS oraz planów "
        "wsparcia chmurowego. Każda z tych pozycji wiąże się z konkretną datą "
        "wygaśnięcia, numerem seryjnym nadanym przez wystawcę, kosztem oraz "
        "odpowiedzialnością po stronie wyznaczonego użytkownika certyfikatu "
        "(beneficjenta) i podmiotu finansującego (płatnika)."
    )
    pdf.paragraph(
        "Proces monitorowania terminów bywa skomplikowany i czasochłonny. Dane o "
        "certyfikatach i subskrypcjach często rozproszone są pomiędzy arkuszami "
        "kalkulacyjnymi, skrzynkami pocztowymi, portalami dostawców oraz dokumentacją "
        "papierową. Powoduje to utratę ciągłości usług, ryzyko przeoczenia terminu "
        "odnowienia oraz konieczność wielokrotnych konsultacji między działem IT, "
        "działem prawnym a finansami."
    )

    pdf.subheading("1.2. Charakterystyka problemu w środowisku firmowym i prywatnym")
    pdf.paragraph(
        "W środowisku firmowym zarządzanie certyfikatami i subskrypcjami wymaga "
        "uwzględnienia wielu czynników: typu certyfikatu, daty wygaśnięcia, "
        "wymaganego czasu na odnowienie, użytkownika certyfikatu, podmiotu płacącego, "
        "statusu płatności oraz priorytetu odnowienia. Nieodnowiony certyfikat SSL "
        "może spowodować awarie API i utratę zaufania klientów, wygaśnięty certyfikat "
        "kwalifikowany — wstrzymanie podpisywania dokumentów urzędowych, a opóźniona "
        "płatność za SaaS — zawieszenie licencji i spadek produktywności zespołu."
    )
    pdf.paragraph(
        "Pierwotna koncepcja projektu zakładała objęcie jednym systemem także "
        "segmentu prywatnego, w którym użytkownicy indywidualni mierzą się ze "
        "zjawiskiem „zmęczenia subskrypcjami” (subscription fatigue) — wieloma "
        "usługami miesięcznymi (streaming, muzyka, chmura, narzędzia AI) bez "
        "jednego widoku łącznych wydatków. W toku prac analitycznych stwierdzono "
        "jednak, że model danych, krąg odbiorców oraz wymagania bezpieczeństwa "
        "rejestru firmowego (beneficjenci, płatnicy, role personelu, RODO) i "
        "osobistego menedżera subskrypcji są na tyle odmienne, że ich połączenie "
        "w jednej bazie i jednym panelu szkodziłoby przejrzystości obu rozwiązań. "
        "Z tego powodu moduł prywatny został wydzielony do osobnej aplikacji "
        "(„Menedżer Subskrypcji”, decyzja projektowa D1, szerzej w podrozdziale 3.1), "
        "a niniejsza praca opisuje docelowo firmowy rejestr certyfikatów i subskrypcji."
    )

    pdf.subheading("1.3. Proces odnowień i cykl życia subskrypcji IT")
    pdf.paragraph(
        "Cykl życia certyfikatu lub subskrypcji obejmuje rejestrację pozycji, "
        "aktywne użytkowanie, monitorowanie terminu odnowienia, wysyłkę zaproszenia "
        "do beneficjenta lub płatnika, realizację odnowienia (wystawienie nowego "
        "certyfikatu i zarchiwizowanie poprzedniego) albo świadome porzucenie "
        "procesu. W praktyce organizacje stosują różne cykle rozliczeniowe: "
        "miesięczne (licencje SaaS), roczne (certyfikaty SSL, domeny) oraz "
        "wieloletnie (certyfikaty kwalifikowane, umowy enterprise)."
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
        "uwierzytelniania opartego na haśle z weryfikacją adresu e-mail oraz "
        "reaktywnych interfejsów (Vue.js) "
        "pozwala na integrację rozproszonych danych i usprawnienie procesu monitorowania terminów."
    )
    pdf.paragraph(
        "Celem niniejszej pracy inżynierskiej jest zaprojektowanie i implementacja "
        "aplikacji webowej CertiSub Assistant wspierającej ewidencję certyfikatów i "
        "subskrypcji organizacji wraz z ich użytkownikami (beneficjentami) i płatnikami, "
        "z wykorzystaniem nowoczesnych technologii informatycznych. Efektem końcowym "
        "pracy jest działający prototyp systemu umożliwiający prowadzenie rejestru "
        "certyfikatów, automatyczne wykrywanie zbliżających się terminów odnowienia, "
        "prezentację alertów i statystyk w dashboardzie, wysyłkę zaproszeń i przypomnień "
        "e-mail na podstawie szablonów oraz raportowanie i wymianę danych (import/eksport "
        "CSV, XML i wiadomości e-mail)."
    )

    # Section 2
    pdf.add_page()
    pdf.heading("2. Badanie rynkowe i przegląd istniejących rozwiązań", 14)
    pdf.subheading("2.1. Problem rozproszonego zarządzania subskrypcjami")
    pdf.paragraph(
        "Zarządzanie certyfikatami i subskrypcjami IT stanowi istotny problem "
        "organizacyjny zarówno w małych i średnich przedsiębiorstwach, jak i w "
        "dużych firmach z rozbudowaną infrastrukturą. Każdego roku organizacje "
        "odnawiają dziesiątki certyfikatów kwalifikowanych, certyfikatów SSL, "
        "licencji SaaS oraz umów wsparcia, a odpowiedzialność za poszczególne "
        "pozycje bywa rozproszona pomiędzy dział IT, dział prawny i finanse. "
        "Jednocześnie informacje o terminach, kosztach i osobach odpowiedzialnych "
        "pozostają rozproszone i trudne do zestawienia w jednym miejscu."
    )
    pdf.paragraph(
        "Proces monitorowania odnowień jest często rozproszony i opiera się na "
        "korzystaniu z wielu różnych źródeł: arkuszy Excel, skrzynek e-mail, "
        "portali dostawców (AWS, Microsoft 365, urzędów certyfikacji) oraz "
        "papierowej dokumentacji. Powoduje to trudności w szybkiej identyfikacji "
        "pozycji zbliżających się do wygaśnięcia oraz utrudnia przypisanie "
        "odpowiedzialności za konkretne odnowienie."
    )

    pdf.subheading("2.2. Analiza istniejących narzędzi i platform")
    pdf.paragraph(
        "W celu lepszego zrozumienia problematyki zarządzania certyfikatami i "
        "subskrypcjami przeprowadzono analizę wybranych rozwiązań dostępnych "
        "obecnie na rynku. Do badania wybrano kategorie narzędzi umożliwiających "
        "śledzenie wydatków subskrypcyjnych, zarządzanie cyklem życia certyfikatów "
        "oraz zarządzanie zasobami IT, uwzględniając zarówno platformy "
        "międzynarodowe, jak i usługi polskich centrów certyfikacji obsługujących "
        "podpisy i pieczęcie kwalifikowane."
    )
    pdf.paragraph("Analizie poddano następujące rozwiązania:")
    for item in [
        "arkusze kalkulacyjne (Excel / Google Sheets) — powszechne w MŚP,",
        "narzędzia zarządzania cyklem życia certyfikatów (Keyfactor Command, "
        "DigiCert Trust Lifecycle Manager, Sectigo Certificate Manager) — "
        "segment enterprise,",
        "systemy ITSM/CMDB (ServiceNow, Lansweeper) — ewidencja zasobów IT "
        "bez specjalizacji w certyfikatach,",
        "panele polskich centrów certyfikacji kwalifikowanej (Certum, KIR — "
        "Szafir, EuroCert) — ograniczone do własnych certyfikatów danego wystawcy,",
        "portale dostawców subskrypcji (AWS, Microsoft 365, operatorzy domen) "
        "— dane w silosach,",
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
    headers = ["Funkcjonalność", "Excel", "Cert. LCM", "ITSM", "Centra cert.", "CertiSub"]
    widths = [48, 20, 24, 20, 26, 22]
    for header, width in zip(headers, widths):
        pdf.cell(width, 8, header, border=1)
    pdf.ln()
    pdf.set_font("Arial", "", 9)
    rows = [
        ("Centralny rejestr certyfikatów", "Tak", "Tak", "Tak", "Nie", "Tak"),
        ("Ewidencja beneficjentów i płatników", "Ręcznie", "Nie", "Częściowo", "Nie", "Tak"),
        ("Alerty terminów odnowień", "Nie", "Tak", "Tak", "Ograniczone", "Tak"),
        ("Zadania ToDo z priorytetami", "Nie", "Tak", "Tak", "Nie", "Tak"),
        ("Zaproszenia e-mail z załącznikami", "Nie", "Ograniczone", "Tak", "Nie", "Tak"),
        ("Dashboard KPI i priorytety", "Nie", "Tak", "Tak", "Nie", "Tak"),
        ("Wyszukiwarka z powiązaniami", "Ograniczone", "Tak", "Tak", "Nie", "Tak"),
        ("Logowanie hasłem + weryfikacja e-mail", "Nie", "Tak", "Rzadko", "Nie", "Tak"),
        ("Import / eksport CSV, XML, EML", "Tak*", "Ograniczone", "Tak", "Nie", "Tak"),
        ("Niski koszt wdrożenia", "Tak", "Nie", "Nie", "Tak", "Tak"),
    ]
    for row in rows:
        for value, width in zip(row, widths):
            pdf.cell(width, 7, value, border=1)
        pdf.ln()
    pdf.ln(3)
    pdf.body_text(11)
    pdf.multi_cell(
        0,
        6,
        "Tabela przedstawia porównanie najważniejszych funkcji analizowanych rozwiązań "
        "(Cert. LCM — narzędzia klasy Certificate Lifecycle Management, np. Keyfactor "
        "Command, DigiCert Trust Lifecycle Manager; Centra cert. — panele polskich "
        "centrów certyfikacji kwalifikowanej, np. Certum, KIR Szafir, EuroCert; "
        "* Excel eksportuje/importuje wyłącznie CSV, bez XML ani poczty EML).",
    )
    pdf.ln(2)
    pdf.paragraph(
        "Na podstawie przeprowadzonej analizy można zauważyć, że dostępne rozwiązania "
        "albo są zbyt ogólne i wymagają ręcznego utrzymania (arkusze), albo zbyt drogie "
        "i złożone jak na potrzeby małej lub średniej organizacji (Cert. LCM, ITSM), "
        "albo ograniczają się do certyfikatów jednego wystawcy bez ewidencji "
        "beneficjentów, płatników i zadań (panele centrów certyfikacji). Żadne z "
        "analizowanych narzędzi nie łączy w jednej platformie rejestru certyfikatów, "
        "procesu odnowień z zaproszeniami e-mail oraz raportów dla ról personelu przy "
        "zachowaniu niskiego kosztu wdrożenia."
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
        "Encje kont personelu, beneficjentów, płatników, certyfikatów, zadań "
        "odnowieniowych, szablonów wiadomości, zaproszeń i tokenów weryfikacyjnych "
        "naturalnie mapują się na tabele połączone kluczami obcymi. W projekcie wykorzystano "
        "MySQL z PDO i prepared statements, co zapewnia integralność danych i "
        "ochronę przed SQL Injection; tabela historii zdarzeń (`events`) jest "
        "celowo wyłączona z więzów kluczy obcych, aby zachować zapis zdarzeń "
        "nawet po archiwizacji powiązanego rekordu."
    )

    pdf.add_page()
    pdf.subheading("2.3.3. Technologie frontendowe", 11)
    pdf.paragraph(
        "Dashboard wykorzystuje Vue.js 3 do reaktywnego wyszukiwania, filtrowania "
        "i przełączania widoków bez przeładowania strony, uruchamiane bez kroku "
        "budowania — plik biblioteki jest częścią repozytorium, a nie ładowany z "
        "zewnętrznego CDN. Tailwind CSS zapewnia responsywny i spójny interfejs. "
        "Takie podejście eliminuje konieczność bundlera na etapie MVP i pozwala "
        "uruchomić aplikację bez dostępu do Internetu."
    )
    pdf.subheading("2.3.4. Uwierzytelnianie hasłem i weryfikacja adresu e-mail", 11)
    pdf.paragraph(
        "W CertiSub Assistant zaimplementowano logowanie hasłem zgodnym z polityką "
        "haseł NIST SP 800-63B (minimalna długość, brak wymuszonej rotacji), z "
        "limitem prób logowania liczonym w bazie (per adres i per adres IP) oraz "
        "jednorazowymi, haszowanymi tokenami e-mail do potwierdzenia adresu i "
        "ustawienia lub zresetowania hasła. Hasła są przechowywane wyłącznie jako "
        "skrót funkcji `password_hash()` z automatycznym przeliczeniem przy zmianie "
        "kosztu algorytmu, a wysyłkę wiadomości obsługuje PHPMailer."
    )

    pdf.subheading("2.4. Systemy alertów i powiadomień o terminach płatności")
    pdf.paragraph(
        "Automatyczne powiadomienia o zbliżających się terminach płatności stanowią "
        "kluczowy element skutecznego zarządzania subskrypcjami. W projekcie zastosowano "
        "zadanie cron uruchamiane codziennie, które wyszukuje subskrypcje z płatnością "
        "za 3 dni i wysyła wiadomości HTML do użytkownika. Mechanizm ten uzupełnia "
        "alerty wizualne w dashboardzie (kolorowe wiersze, karty KPI, lista To-Do)."
    )

    pdf.add_page()
    pdf.subheading("2.5. Studium wykonalności: PHP/LAMP a Java/Spring Boot")
    pdf.paragraph(
        "Punktem wyjścia do wyboru technologii backendu było porównanie dwóch "
        "dostępnych na uczelni i w praktyce zawodowej podejść: stosu PHP 8 na "
        "serwerze Apache z bazą MySQL (LAMP) oraz aplikacji Java ze szkieletem "
        "Spring Boot. Porównania dokonano według czterech kryteriów istotnych dla "
        "projektu realizowanego jednoosobowo w ramach pracy inżynierskiej: "
        "dostępności na standardowym hostingu, kosztu wdrożenia, złożoności "
        "uruchomienia i utrzymania oraz zgodności z kompetencjami autora "
        "zdobytymi w toku studiów."
    )
    pdf.ln(1)
    fs_rows = [
        (
            "Dostępność na hostingu",
            "standardowy hosting współdzielony (Apache + PHP + MySQL).",
            "wymaga JVM i serwera aplikacyjnego; rzadziej dostępne na tanim hostingu.",
        ),
        (
            "Koszt wdrożenia",
            "niski — stos open-source, brak opłat licencyjnych.",
            "niski licencyjnie, ale wyższe wymagania sprzętowe (JVM, więcej RAM).",
        ),
        (
            "Złożoność uruchomienia",
            "uruchomienie skryptu przez serwer WWW, brak procesu budowania na produkcji.",
            "budowanie artefaktu (Maven/Gradle), proces JVM, konteneryzacja zalecana.",
        ),
        (
            "Kompetencje autora",
            "PHP i MySQL z zajęć oraz praktyki zawodowej — środowisko znane.",
            "podstawy Javy z zajęć, bez doświadczenia ze Spring Boot.",
        ),
    ]
    for label, php_val, java_val in fs_rows:
        pdf.subheading(label, 11)
        pdf.bullet(f"PHP / LAMP: {php_val}")
        pdf.bullet(f"Java / Spring Boot: {java_val}")
    pdf.ln(1)
    pdf.paragraph(
        "Na podstawie porównania wybrano stos PHP 8 / MySQL / Apache. Decydujące "
        "okazały się: dostępność na standardowym hostingu bez dodatkowych usług "
        "(zgodnie z wymaganiem niefunkcjonalnym N3), brak kroku budowania na "
        "produkcji oraz wcześniejsze doświadczenie autora z PHP i PDO, co "
        "ograniczyło ryzyko projektu realizowanego w pojedynkę w ograniczonym "
        "czasie. Warstwę frontendową uzupełniono o Vue.js 3 uruchamiane bez "
        "bundlera (pliki w `assets/vendor`), co pozwoliło zachować prostotę "
        "wdrożenia charakterystyczną dla LAMP przy zachowaniu reaktywnego interfejsu."
    )

    pdf.subheading("2.6. Wnioski z analizy i uzasadnienie realizacji projektu")
    pdf.paragraph(
        "Przeprowadzona analiza problemu zarządzania certyfikatami i subskrypcjami "
        "oraz badanie dostępnych rozwiązań informatycznych pozwoliły zidentyfikować "
        "szereg ograniczeń występujących w obecnie funkcjonujących systemach. Większość "
        "narzędzi koncentruje się albo na certyfikatach jednego wystawcy, albo na "
        "ogólnej ewidencji zasobów IT bez procesu odnowień, albo wymaga ręcznego "
        "utrzymania danych bez alertów i wieloużytkownikowego dostępu."
    )
    pdf.paragraph(
        "Analiza wykazała również, że dostępne rozwiązania rzadko integrują "
        "rejestr certyfikatów i subskrypcji, proces odnowień z zaproszeniami "
        "e-mail oraz raporty dla poszczególnych ról personelu w ramach jednego "
        "interfejsu. Dane są zazwyczaj rozproszone między arkuszami, portalami "
        "dostawców i skrzynkami pocztowymi, co utrudnia szybkie podejmowanie decyzji."
    )
    pdf.paragraph(
        "Na podstawie przeprowadzonych analiz uzasadniona jest realizacja aplikacji "
        "webowej CertiSub Assistant na stosie PHP/LAMP wybranym w studium "
        "wykonalności (podrozdział 2.5). Projektowany system połączy ewidencję "
        "certyfikatów, beneficjentów i płatników, proces odnowień z zadaniami ToDo "
        "i zaproszeniami e-mail, raporty dla ról personelu oraz wymianę danych. "
        "Takie podejście pozwoli zwiększyć efektywność monitorowania terminów oraz "
        "usprawnić kontrolę nad odnowieniami certyfikatów i subskrypcji organizacji."
    )
    pdf.paragraph(
        "Wnioski przedstawione w niniejszym rozdziale stanowią podstawę do opracowania "
        "założeń projektowych systemu, które zostaną przedstawione w kolejnym rozdziale pracy."
    )

    # Sections 3-8 live in a separate module
    chapter3(pdf)
    chapter4(pdf)
    chapters_5_to_8(pdf)
    pdf.numbered_footer = True

    return save_pdf(pdf, "Szkic pracy Litosh.pdf")


if __name__ == "__main__":
    zalozenia_paths = generate_zalozenia()
    szkic_paths = generate_szkic()
    print("Wygenerowano:")
    for path in zalozenia_paths + szkic_paths:
        print(" ", str(path).encode("ascii", "replace").decode("ascii"))
