# -*- coding: utf-8 -*-
"""Pomocnicze funkcje do części pisemnej: zrzuty ekranu, listingi, macierz uprawnień, diagram architektury."""

from __future__ import annotations

import re
import tempfile
from pathlib import Path

from PIL import Image

REPO = Path(__file__).resolve().parent.parent
SCREEN_DIR = Path(r"C:\Users\Lumis\Documents\MEGA\PC\UNI US\3 Rok\SDI seminarium\screenshoty aplikacji")

# klucz -> (nazwa pliku, ile pikseli u góry odciąć: pasek przeglądarki z zakładkami i historią)
SHOTS: dict[str, tuple[str, int | tuple[int, int, int, int]]] = {
    "landing_hero": ("{94A2A89E-D4D0-4732-83D9-EA9CB674B4F4}.png", 74),
    "landing_track": ("{33845ACC-A59B-4547-A6CD-2220B124CA77}.png", 75),
    "landing_features": ("{3455A5C5-37CE-4DA3-90AC-7384584CD373}.png", 70),
    "login": ("{8D18B123-4E72-43E2-AF71-B4ACDE1B05F3}.png", 79),
    "dashboard": ("{476658DF-5732-4562-8FFB-94274E07271C}.png", 73),
    "certificates": ("{FE5951B9-9856-4402-933A-8FC358480E02}.png", 76),
    "beneficiaries": ("{47C5C5B8-F036-4830-958E-57684588CB62}.png", 70),
    "reports": ("{7C0FFD76-E595-456C-B102-56A557385745}.png", 75),
    "todo": ("{BCDD60F0-E6DF-4086-937A-2B7B31A4A5F0}.png", 4),
    "accounts": ("{89341204-50ED-4BA2-B481-3BC32B470C82}.png", 77),
    "pma_tables": ("{F4C463CF-5CA1-4803-911A-1463B51F8846}.png", 77),
    "pma_certificates": ("{BA191E7E-FC12-47EB-A0DE-C5D6A05971A9}.png", 73),
    # zrzuty przesłane w czacie 23.10.2026 (skopiowane do podfolderu „z czatu”)
    "form_cert": ("z czatu/01-formularz-certyfikatu.png", (88, 310, 992, 1600)),
    "cert_details": ("z czatu/02-szczegoly-certyfikatu.png", (408, 68, 1074, 1160)),
    "card_top": ("z czatu/03-karta-firmy-gora.png", 74),
    "card_schedule": ("z czatu/04-karta-firmy-harmonogram.png", (0, 136, 1930, 1046)),
    "card_certs": ("z czatu/05-karta-firmy-certyfikaty.png", (0, 106, 1900, 1016)),
    "card_path": ("z czatu/06-karta-firmy-sciezka-os-czasu.png", (0, 80, 1900, 990)),
    "scope_popup": ("z czatu/07-zakres-firm.png", 77),
    "settings": ("z czatu/08-ustawienia.png", 77),
    "templates": ("z czatu/09-szablony.png", 73),
    "invitations": ("z czatu/10-zaproszenia-rejestr.png", 75),
    "mailtrap_mail": ("z czatu/11-mailtrap-ustaw-haslo.png", 75),
    "gmail_mail": ("z czatu/12-gmail-ustaw-haslo.png", (250, 190, 1700, 650)),
    "exchange": ("z czatu/13-import-eksport.png", 71),
    "events": ("z czatu/14-dziennik-zdarzen.png", 77),
    "registrations": ("z czatu/15-wnioski-email.png", 75),
    "notifications": ("z czatu/16-powiadomienia.png", 74),
    # zrzuty do uzupełnienia (opis: docs/ZRZUTY_DO_PRACY.md) — robione wycinkiem ekranu,
    # więc bez obcinania paska przeglądarki
    # Zrzuty z 26.10.2026 obejmują całe okno przeglądarki (pasek zakładek, pasek zadań),
    # dlatego każdy ma wycinek: samo okno dialogowe albo sama treść strony.
    "invitation_compose": ("z czatu/17-wyslij-zaproszenie.png", (535, 268, 1444, 921)),
    "invitation_mail": ("z czatu/18-mailtrap-zaproszenie.png", (0, 105, 1909, 992)),
    "registration_review": ("z czatu/19-wniosek-sprawdzenie.png", (0, 105, 1890, 1001)),
    "tests_run": ("z czatu/20-przebieg-testow.png", (0, 86, 1059, 1177)),
}

_cache: dict[str, str | None] = {}


def shot_path(key: str) -> str | None:
    """Ścieżka do przyciętego zrzutu albo None, gdy pliku nie ma (wtedy w pracy zostaje ramka)."""
    if key in _cache:
        return _cache[key]
    name, crop_top = SHOTS[key]
    source = SCREEN_DIR / name
    if not source.is_file():
        _cache[key] = None
        return None
    out_dir = Path(tempfile.gettempdir()) / "certisub_thesis_shots"
    out_dir.mkdir(parents=True, exist_ok=True)
    target = out_dir / f"{key}.png"
    with Image.open(source) as img:
        img = img.convert("RGB")
        box = crop_top if isinstance(crop_top, tuple) else (0, crop_top, img.width, img.height)
        img.crop(box).save(target, optimize=True)
    _cache[key] = str(target)
    return _cache[key]


def screenshot(pdf, key: str, caption: str, width: float | None = None) -> None:
    path = shot_path(key)
    if path is None:
        pdf.figure_placeholder(caption)
    else:
        pdf.figure_image(path, caption, width)


def code_block(pdf, title: str, code: str) -> None:
    """Listing w czcionce o stałej szerokości (tylko znaki ASCII)."""
    pdf.listing_count = getattr(pdf, "listing_count", 0) + 1
    pdf.set_font("Arial", "I", 10)
    needed = 8 + 3.6 * (code.count("\n") + 1)
    if pdf.get_y() + needed > pdf.page_break_trigger and needed < 200:
        pdf.add_page()
    pdf.multi_cell(pdf.usable_width(), 5, f"Listing {pdf.listing_count}. {title}")
    pdf.ln(1)
    pdf.set_font("Courier", "", 8)
    pdf.set_fill_color(245, 245, 245)
    pdf.set_draw_color(200, 200, 200)
    pdf.multi_cell(pdf.usable_width(), 3.6, code, border=1, fill=True)
    pdf.set_draw_color(0, 0, 0)
    pdf.ln(4)


def rbac_matrix() -> tuple[list[str], list[str], dict[str, set[str]]]:
    """Czyta macierz uprawnień wprost z classes/Rbac.php (jedno źródło prawdy)."""
    text = (REPO / "classes" / "Rbac.php").read_text(encoding="utf-8")
    all_block = re.search(r"private const ALL = \[(.*?)\];", text, re.S).group(1)
    permissions = re.findall(r"'([a-z_]+\.[a-z_]+)'", all_block)
    lists: dict[str, set[str]] = {}
    for name in ("DIRECTOR", "MANAGER", "ACCOUNTANT", "IT", "OPERATOR", "EMPLOYEE"):
        block = re.search(rf"private const {name}_CAN = \[(.*?)\];", text, re.S).group(1)
        lists[name] = set(re.findall(r"'([a-z_]+\.[a-z_]+)'", block))
    lists["ADMIN"] = set(permissions)
    roles = ["ADMIN", "DIRECTOR", "MANAGER", "ACCOUNTANT", "IT", "OPERATOR", "EMPLOYEE"]
    return roles, permissions, lists


def draw_architecture(pdf) -> None:
    """Rysuje schemat warstw aplikacji (szerokość ok. 170 mm, wysokość 104 mm) w bieżącym miejscu strony."""
    x0, y0 = pdf.l_margin, pdf.get_y()
    if y0 + 108 > pdf.page_break_trigger:
        pdf.add_page()
        y0 = pdf.get_y()
    pdf.set_auto_page_break(False)

    def box(x, y, w, h, title, lines, fill=(236, 242, 250), head=(30, 58, 95)):
        pdf.set_fill_color(*fill)
        pdf.set_draw_color(40, 60, 100)
        pdf.set_line_width(0.3)
        pdf.rect(x, y, w, h, style="DF")
        pdf.set_fill_color(*head)
        pdf.rect(x, y, w, 5.5, style="F")
        pdf.set_font("Arial", "B", 8)
        pdf.set_text_color(255, 255, 255)
        pdf.text(x + 2, y + 3.9, title)
        pdf.set_font("Arial", "", 7)
        pdf.set_text_color(25, 25, 25)
        for i, line in enumerate(lines):
            pdf.text(x + 2, y + 9.5 + i * 3.7, line)

    def arrow(x1, y1, x2, y2, label=""):
        pdf.set_draw_color(70, 70, 70)
        pdf.set_line_width(0.35)
        pdf.line(x1, y1, x2, y2)
        # grot
        dx, dy = x2 - x1, y2 - y1
        length = (dx * dx + dy * dy) ** 0.5 or 1
        ux, uy = dx / length, dy / length
        pdf.polygon([(x2, y2), (x2 - ux * 2.2 - uy * 1.1, y2 - uy * 2.2 + ux * 1.1),
                     (x2 - ux * 2.2 + uy * 1.1, y2 - uy * 2.2 - ux * 1.1)], style="F")
        if label:
            pdf.set_font("Arial", "I", 6.5)
            pdf.set_text_color(70, 70, 70)
            pdf.text((x1 + x2) / 2 + 1.5, (y1 + y2) / 2 + 1, label)

    w = pdf.usable_width()
    box(x0, y0, 105, 19, "Przeglądarka — Vue 3 + Tailwind CSS (assets/)",
        ["widoki w assets/js/views, komponenty, hash-routing",
         "dane startowe: window.CERTISUB_BOOT (i18n, CSRF, uprawnienia)"])
    box(x0, y0 + 27, 105, 19, "Warstwa HTTP — api/*.php → ApiKernel → Controllers",
        ["metoda → CSRF (zapis) → sesja → obsługa → JSON",
         "kody 400/401/403/404/409/422, 500 bez szczegółów"])
    box(x0, y0 + 54, 105, 24, "Warstwa usług — App\\Service\\*",
        ["Actor::authorize() → Rbac::can(), Visibility (zakres danych)",
         "Validator, Transaction, EventLogger, Cache",
         "RenewalScanner → TaskService → InvitationService",
         "Registration*, Notification*, Import/Export, Report/Search"])
    box(x0, y0 + 86, 105, 17, "Dane — PDO (prepared statements) → MySQL 8",
        ["18 tabel InnoDB, utf8mb4; migracje idempotentne (MigrationRunner)",
         "pliki: storage/attachments, storage/intake, storage/cache"])
    arrow(x0 + 52, y0 + 19, x0 + 52, y0 + 27, "fetch(), JSON")
    arrow(x0 + 52, y0 + 46, x0 + 52, y0 + 54)
    arrow(x0 + 52, y0 + 78, x0 + 52, y0 + 86, "SQL")

    rx = x0 + 113
    rw = w - 113
    box(rx, y0, rw, 24, "Zadania z wiersza poleceń",
        ["cron/renewals.php (skaner,", "przypomnienia), cron/mail-intake.php,",
         "scripts/*.php (migracje, import, dane demo)"], fill=(243, 246, 236), head=(70, 100, 40))
    box(rx, y0 + 32, rw, 30, "Usługi zewnętrzne",
        ["SMTP + OAuth2 / Mailtrap (PHPMailer)", "skrzynka IMAP (wnioski e-mail)",
         "Biała Lista VAT — API Ministerstwa", "Finansów (wl-api.mf.gov.pl)"],
        fill=(250, 241, 230), head=(150, 85, 20))
    arrow(rx, y0 + 12, x0 + 105, y0 + 66, "te same usługi")
    arrow(x0 + 105, y0 + 66, rx, y0 + 48, "poczta, rejestr")

    pdf.set_text_color(0, 0, 0)
    pdf.set_draw_color(0, 0, 0)
    pdf.set_line_width(0.2)
    pdf.set_auto_page_break(True, margin=20)
    pdf.set_xy(pdf.l_margin, y0 + 108)
