# -*- coding: utf-8 -*-
"""Diagram ERD bazy CertiSub Assistant — jedno źródło geometrii dla PDF (fpdf2) i SVG.

Dane odpowiadają database/schema.sql po Etapie 10. Pomija się klucze obce do `users`, które
tylko wskazują autora albo wykonawcę (created_by_user_id itd.), żeby rysunek pozostał czytelny —
pełna lista kluczy jest w docs/BAZA_DANYCH.md i docs/erd/certisub.dbml.
"""

from __future__ import annotations

from pathlib import Path
from xml.sax.saxutils import escape

ROW_H = 3.2
HEAD_H = 4.6
BOX_W = 50.0

# nazwa -> (x, y, [(znacznik, kolumna)])
TABLES: dict[str, tuple[float, float, list[tuple[str, str]]]] = {
    "payers": (8, 24, [
        ("PK", "id"), ("", "company_name"), ("", "contact_person"), ("UK", "tax_id"),
        ("", "email"), ("", "city"), ("FK", "created_by_user_id"), ("", "archived_at"),
    ]),
    "beneficiaries": (8, 66, [
        ("PK", "id"), ("", "first_name"), ("", "last_name"), ("", "email"),
        ("FK", "payer_id (NOT NULL)"), ("FK", "created_by_user_id"), ("", "archived_at"),
    ]),
    "registration_drafts": (8, 106, [
        ("PK", "id"), ("", "status"), ("", "source"), ("UK", "message_id"), ("UK", "content_hash"),
        ("", "extracted (JSON)"), ("", "form (JSON)"), ("", "company_lookup (JSON)"),
        ("FK", "matched_payer_id"), ("FK", "matched_beneficiary_id"),
        ("FK", "result_certificate_id"), ("FK", "reviewed_by_user_id"),
    ]),
    "certificates": (66, 24, [
        ("PK", "id"), ("", "name"), ("", "certificate_type"), ("UK", "serial_number"),
        ("UK", "issuer"), ("", "valid_from"), ("", "expiry_date"), ("", "renewal_lead_days"),
        ("FK", "user_id"), ("FK", "beneficiary_id"), ("FK", "payer_id"),
        ("FK", "previous_certificate_id"), ("", "status"), ("", "discount_percent"),
        ("", "billing_cycle"), ("", "payment_status"), ("", "archived_at"),
    ]),
    "renewal_tasks": (66, 100, [
        ("PK", "id"), ("FK", "certificate_id"), ("FK", "assigned_user_id"), ("", "status"),
        ("", "priority"), ("", "due_date"), ("UK", "open_marker (wyliczana)"), ("", "closed_at"),
    ]),
    "users": (124, 24, [
        ("PK", "id"), ("", "first_name"), ("", "last_name"), ("", "role"), ("UK", "email"),
        ("", "password_hash"), ("", "email_verified_at"), ("FK", "beneficiary_id"),
        ("", "company_filter (JSON)"), ("", "deactivated_at"),
    ]),
    "notifications": (124, 72, [
        ("PK", "id"), ("", "thread_id"), ("", "parent_id"), ("", "type"),
        ("FK", "sender_user_id"), ("FK", "recipient_user_id"), ("", "related_type"),
        ("", "related_id"), ("", "read_at"),
    ]),
    "email_verifications": (124, 118, [
        ("PK", "id"), ("FK", "user_id"), ("", "purpose"), ("UK", "token_hash"),
        ("", "expires_at"), ("", "used_at"),
    ]),
    "login_attempts": (124, 150, [
        ("PK", "id"), ("", "email"), ("", "request_ip"), ("", "successful"), ("", "created_at"),
    ]),
    "settings": (124, 178, [
        ("PK", "setting_key"), ("", "setting_value"), ("FK", "updated_by_user_id"),
    ]),
    "invitations": (182, 24, [
        ("PK", "id"), ("FK", "certificate_id"), ("FK", "renewal_task_id"), ("FK", "template_id"),
        ("FK", "sent_by_user_id"), ("", "recipient_type"), ("", "recipient_email"), ("", "status"),
        ("", "body_html"), ("", "reminder_count"), ("", "next_reminder_at"),
    ]),
    "invitation_attachments": (182, 72, [
        ("PK", "invitation_id (FK)"), ("PK", "attachment_id (FK)"),
    ]),
    "email_templates": (182, 100, [
        ("PK", "id"), ("UK", "code"), ("UK", "locale"), ("", "name"), ("", "subject"),
        ("", "body_html"), ("", "body_text"), ("", "is_active"),
    ]),
    "email_template_attachments": (182, 140, [
        ("PK", "template_id (FK)"), ("PK", "attachment_id (FK)"),
    ]),
    "attachments": (240, 72, [
        ("PK", "id"), ("", "original_name"), ("UK", "stored_name"), ("", "mime_type"),
        ("", "size_bytes"), ("", "sha256"), ("FK", "uploaded_by_user_id"),
    ]),
    "events": (240, 160, [
        ("PK", "id"), ("", "entity_type"), ("", "entity_id"), ("", "event_type"), ("", "user_id"),
        ("", "certificate_id"), ("", "beneficiary_id"), ("", "payer_id"), ("", "payload (JSON)"),
        ("", "occurred_at"),
    ]),
}

# (punkty łamanej, etykieta na początku, etykieta na końcu); początek = strona „1” (rodzic)
RELATIONS: list[tuple[list[tuple[float, float]], str, str]] = [
    ([(33, 54.2), (33, 66)], "1", "N"),                                             # firma -> osoby
    ([(58, 36), (66, 36)], "1", "N"),                                               # firma -> certyfikaty
    ([(58, 76), (66, 76)], "1", "N"),                                               # osoba -> certyfikaty
    ([(124, 34), (116, 34)], "1", "N"),                                             # opiekun -> certyfikaty
    ([(66, 52), (62.5, 52), (62.5, 60), (66, 60)], "0..1", "N"),                    # łańcuch odnowień
    ([(91, 83), (91, 100)], "1", "N"),                                              # certyfikat -> zadania
    ([(124, 56), (120, 56), (120, 122), (116, 122)], "1", "N"),                     # konto -> zadania
    ([(149, 24), (149, 17), (4, 17), (4, 84), (8, 84)], "N", "0..1"),               # konto -> osoba
    ([(100, 24), (100, 19.5), (207, 19.5), (207, 24)], "1", "N"),                   # certyfikat -> zaproszenia
    ([(116, 111), (177, 111), (177, 52), (182, 52)], "1", "N"),                     # zadanie -> zaproszenia
    ([(182, 118), (179.5, 118), (179.5, 58), (182, 58)], "0..1", "N"),              # szablon -> zaproszenia
    ([(149, 60.6), (149, 72)], "1", "N"),                                           # konto -> powiadomienia
    ([(207, 63.8), (207, 72)], "1", "N"),                                           # zaproszenie -> załączniki
    ([(232, 77.5), (240, 77.5)], "N", "1"),                                         # łącznik -> załącznik
    ([(207, 130.2), (207, 140)], "1", "N"),                                         # szablon -> łącznik
    ([(232, 145.5), (265, 145.5), (265, 99)], "N", "1"),                            # łącznik -> załącznik
    ([(58, 130), (62, 130), (62, 80), (66, 80)], "N", "0..1"),                      # wniosek -> certyfikat
    ([(33, 106), (33, 93)], "N", "0..1"),                                           # wniosek -> osoba
]

GROUP = (121.0, 115.0, 56.0, 80.0, "Logowanie i ustawienia")

LEGEND = [
    "PK — klucz główny, FK — klucz obcy, UK — klucz unikalny",
    "1 — strona „jeden”, N — strona „wiele”, 0..1 — powiązanie opcjonalne",
    "Dla czytelności pominięto klucze obce do users, które wskazują tylko autora lub wykonawcę "
    "(created_by_user_id, sent_by_user_id, uploaded_by_user_id, updated_by_user_id, "
    "user_id w email_verifications), oraz wskazania wniosku na firmy (matched_/result_payer_id).",
    "Tabele events i login_attempts nie mają kluczy obcych celowo.",
]


def table_height(name: str) -> float:
    return HEAD_H + ROW_H * len(TABLES[name][2])


def _label_pos(points: list[tuple[float, float]], at_start: bool, text: str = "") -> tuple[float, float]:
    (x1, y1), (x2, y2) = (points[0], points[1]) if at_start else (points[-1], points[-2])
    dx, dy = x2 - x1, y2 - y1
    length = (dx * dx + dy * dy) ** 0.5 or 1.0
    ux, uy = dx / length, dy / length
    d = 2.2
    px, py = -uy, ux
    x = x1 + ux * d + px * 1.4
    if ux < -0.5:
        x -= 1.7 * len(text)
    return x, y1 + uy * d + py * 1.4


def draw_pdf(pdf) -> None:
    """Rysuje diagram na bieżącej stronie (A4 pozioma)."""
    pdf.set_auto_page_break(False)
    # ramka grupy
    gx, gy, gw, gh, gtitle = GROUP
    pdf.set_draw_color(150, 150, 150)
    pdf.set_dash_pattern(dash=1.5, gap=1.2)
    pdf.rect(gx, gy, gw, gh)
    pdf.set_dash_pattern()
    pdf.set_font("Arial", "I", 6)
    pdf.set_text_color(110, 110, 110)
    pdf.text(gx + 1.5, gy + 3, gtitle)

    # relacje pod tabelami
    pdf.set_draw_color(70, 70, 70)
    pdf.set_line_width(0.28)
    for points, start, end in RELATIONS:
        for (x1, y1), (x2, y2) in zip(points, points[1:]):
            pdf.line(x1, y1, x2, y2)
    pdf.set_font("Arial", "B", 6)
    pdf.set_text_color(20, 20, 20)
    for points, start, end in RELATIONS:
        for text, at_start in ((start, True), (end, False)):
            lx, ly = _label_pos(points, at_start, text)
            pdf.text(lx - 0.8, ly + 0.8, text)

    # tabele
    for name, (x, y, cols) in TABLES.items():
        h = table_height(name)
        pdf.set_fill_color(255, 255, 255)
        pdf.set_draw_color(40, 60, 100)
        pdf.set_line_width(0.3)
        pdf.rect(x, y, BOX_W, h, style="DF")
        pdf.set_fill_color(30, 58, 95)
        pdf.rect(x, y, BOX_W, HEAD_H, style="F")
        pdf.set_font("Arial", "B", 7)
        pdf.set_text_color(255, 255, 255)
        pdf.text(x + 1.5, y + 3.3, name)
        for i, (mark, col) in enumerate(cols):
            ry = y + HEAD_H + i * ROW_H
            if mark == "PK":
                pdf.set_fill_color(255, 244, 205)
                pdf.rect(x + 0.15, ry, BOX_W - 0.3, ROW_H, style="F")
            pdf.set_font("Arial", "B" if mark == "PK" else "", 5.8)
            pdf.set_text_color(60, 60, 60)
            pdf.text(x + 1.2, ry + 2.3, mark)
            pdf.set_font("Arial", "B" if mark == "PK" else "", 6)
            pdf.set_text_color(20, 20, 20)
            pdf.text(x + 7.5, ry + 2.3, col)
    pdf.set_text_color(0, 0, 0)
    pdf.set_draw_color(0, 0, 0)
    pdf.set_line_width(0.2)

    # legenda
    lx, ly, lw = 8.0, 156.0, 50.0
    pdf.set_font("Arial", "I", 6)
    pdf.set_text_color(70, 70, 70)
    pdf.set_xy(lx, ly)
    for line in LEGEND:
        pdf.set_x(lx)
        pdf.multi_cell(lw, 2.9, line)
        pdf.ln(0.8)
    pdf.set_text_color(0, 0, 0)
    pdf.set_auto_page_break(True, margin=20)


def write_svg(path: Path) -> None:
    """Ten sam diagram jako samodzielny, edytowalny plik SVG (draw.io, Inkscape, przeglądarka)."""
    parts: list[str] = [
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 297 210" width="1782" height="1260" '
        'font-family="Arial, Helvetica, sans-serif">',
        '<rect width="297" height="210" fill="#ffffff"/>',
        '<text x="8" y="10" font-size="5" font-weight="bold" fill="#1e3a5f">'
        'CertiSub Assistant — diagram ERD bazy assistent_subscriptions (stan po Etapie 10)</text>',
    ]
    gx, gy, gw, gh, gtitle = GROUP
    parts.append(
        f'<rect x="{gx}" y="{gy}" width="{gw}" height="{gh}" fill="none" stroke="#969696" '
        f'stroke-width="0.3" stroke-dasharray="1.5 1.2"/>'
        f'<text x="{gx + 1.5}" y="{gy + 3}" font-size="2.6" font-style="italic" fill="#6e6e6e">{escape(gtitle)}</text>'
    )
    for points, start, end in RELATIONS:
        pts = " ".join(f"{x},{y}" for x, y in points)
        parts.append(f'<polyline points="{pts}" fill="none" stroke="#464646" stroke-width="0.28"/>')
        for text, at_start in ((start, True), (end, False)):
            lx, ly = _label_pos(points, at_start, text)
            parts.append(
                f'<text x="{lx - 0.8:.2f}" y="{ly + 0.8:.2f}" font-size="2.4" font-weight="bold" '
                f'fill="#141414">{escape(text)}</text>'
            )
    for name, (x, y, cols) in TABLES.items():
        h = table_height(name)
        parts.append(
            f'<rect x="{x}" y="{y}" width="{BOX_W}" height="{h}" fill="#ffffff" stroke="#283c64" stroke-width="0.3"/>'
            f'<rect x="{x}" y="{y}" width="{BOX_W}" height="{HEAD_H}" fill="#1e3a5f"/>'
            f'<text x="{x + 1.5}" y="{y + 3.3}" font-size="2.8" font-weight="bold" fill="#ffffff">{escape(name)}</text>'
        )
        for i, (mark, col) in enumerate(cols):
            ry = y + HEAD_H + i * ROW_H
            if mark == "PK":
                parts.append(f'<rect x="{x + 0.15}" y="{ry}" width="{BOX_W - 0.3}" height="{ROW_H}" fill="#fff4cd"/>')
            weight = "bold" if mark == "PK" else "normal"
            parts.append(
                f'<text x="{x + 1.2}" y="{ry + 2.3}" font-size="2.3" font-weight="{weight}" fill="#3c3c3c">{mark}</text>'
                f'<text x="{x + 7.5}" y="{ry + 2.3}" font-size="2.4" font-weight="{weight}" fill="#141414">{escape(col)}</text>'
            )
    ly = 158.0
    for line in LEGEND:
        words, current, lines = line.split(), "", []
        for word in words:
            if len(current) + len(word) + 1 > 44:
                lines.append(current)
                current = word
            else:
                current = f"{current} {word}".strip()
        lines.append(current)
        for chunk in lines:
            parts.append(f'<text x="8" y="{ly}" font-size="2.3" font-style="italic" fill="#464646">{escape(chunk)}</text>')
            ly += 2.9
        ly += 0.8
    parts.append("</svg>")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(parts), encoding="utf-8")
