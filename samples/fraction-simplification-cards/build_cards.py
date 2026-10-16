#!/usr/bin/env python3
"""
Fraction Simplification Cards (Class 6+)

Shows the cancel trap the way teachers mark notebooks:
  diagonal strike-through on the cancelled numbers (wrong in red / legal in green).

Builds diagrams, questions.json, zip, TEACHER_GUIDE.md
"""

from __future__ import annotations

import json
import zipfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent
DIAG = ROOT / "diagrams"
OUT_JSON = ROOT / "questions.json"
OUT_ZIP = ROOT / "fraction-simplification-cards.zip"

W, H = 900, 620


def font(size: int = 22):
    for name in (
        "DejaVuSans.ttf",
        str(ROOT.parents[1] / "storage" / "fonts" / "DejaVuSans.ttf"),
        r"C:\Windows\Fonts\arial.ttf",
        r"C:\Windows\Fonts\seguiemj.ttf",
    ):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def font_bold(size: int = 22):
    for name in (
        "DejaVuSans-Bold.ttf",
        str(ROOT.parents[1] / "storage" / "fonts" / "DejaVuSans-Bold.ttf"),
        r"C:\Windows\Fonts\arialbd.ttf",
        r"C:\Windows\Fonts\arial.ttf",
    ):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return font(size)


# ---------------------------------------------------------------------------
# Card pedagogy
# ---------------------------------------------------------------------------

CARDS = [
    {
        "id": 1,
        "title": "Cancel trap — numbers in a sum",
        "level": "Class 6+",
        "hook_plain": "(4+5)/4",
        "wrong_cancel": "Cancel both 4s → left with 5 (illegal).",
        "why": "The bar means the whole numerator is divided by 4. So (4+5)/4 = 4/4 + 5/4 = 9/4, not 5.",
        "steps": [
            (False, "Cancel 4s: get 5"),
            (True, "Add first: 9/4"),
            (True, "Split: 4/4 + 5/4 = 9/4"),
            (False, "Cancel one digit only"),
        ],
        "options": [
            "Only step (1) is correct",
            "Only step (2) is correct",
            "Steps (2) and (3) are both correct",
            "Steps (1) and (4) are both correct",
        ],
        "correct_index": 2,
        "answer_value": "9/4",
        "difficulty": "Easy",
        "visual": {
            "wrong_num": [("4", True), ("+", False), ("5", False)],
            "wrong_den": ("4", True),
            "wrong_result": "= 5",
            "right_a": "(4 + 5) / 4  =  9 / 4",
            "right_b": "4/4 + 5/4  =  1 + 5/4  =  9/4",
            "checks": [
                ("(1)", None, None, "cut both 4s → left with 5", False),
                ("(2)", None, None, "(4+5)=9, then 9/4", True),
                ("(3)", None, None, "split: 4/4 + 5/4 = 1 + 5/4 = 9/4", True),
                ("(4)", None, None, "partial cut on digits → wrong", False),
            ],
        },
    },
    {
        "id": 2,
        "title": "Matching addend trap",
        "level": "Class 6+",
        "hook_plain": "(44+11)/11",
        "wrong_cancel": "Cancel the two 11s → 44+1 = 45 (wrong).",
        "why": "11 divides the whole sum. Correct: 55/11 = 5, or 44/11 + 11/11 = 5. Never 45.",
        "steps": [
            (False, "Cancel 11s: 44+1 = 45"),
            (True, "Add first: 55/11 = 5"),
            (True, "Split: 44/11 + 11/11 = 5"),
            (False, "Cancel part of 44: 4+11 = 15"),
        ],
        "options": [
            "Steps (1) and (4) are wrong; only (2) is correct",
            "Only step (2) is correct",
            "Steps (2) and (3) are both correct",
            "All four steps are correct",
        ],
        "correct_index": 2,
        "answer_value": "5",
        "difficulty": "Easy",
        "visual": {
            "wrong_num": [("44", False), ("+", False), ("11", True)],
            "wrong_den": ("11", True),
            "wrong_result": "= 45",
            "right_a": "(44 + 11) / 11  =  55 / 11  =  5",
            "right_b": "44/11 + 11/11  =  4 + 1  =  5",
            "checks": [
                ("(1)", None, None, "cut both 11s → 44+1 = 45", False),
                ("(2)", None, None, "(44+11)=55, then 55/11 = 5", True),
                ("(3)", None, None, "split: 44/11 + 11/11 = 4 + 1 = 5", True),
                ("(4)", None, None, "partial cut on 44 → 4+11 = 15", False),
            ],
        },
    },
    {
        "id": 3,
        "title": "Same idea with subtraction",
        "level": "Class 6+",
        "hook_plain": "(15-5)/5",
        "wrong_cancel": "Cancel both 5s → 14 or 1 (both wrong).",
        "why": "(15−5)/5 = 10/5 = 2, or 15/5 − 5/5 = 2.",
        "steps": [
            (False, "Cancel 5s → 14"),
            (True, "Subtract first: 10/5 = 2"),
            (True, "Split: 15/5 − 5/5 = 2"),
            (False, "Cancel 5s → 1"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are both correct",
            "Only step (4) is correct",
            "Steps (1) and (4) are both correct",
        ],
        "correct_index": 1,
        "answer_value": "2",
        "difficulty": "Easy",
        "visual": {
            "wrong_num": [("15", False), ("−", False), ("5", True)],
            "wrong_den": ("5", True),
            "wrong_result": "= 14",
            "right_a": "(15 − 5) / 5  =  10 / 5  =  2",
            "right_b": "15/5 − 5/5  =  3 − 1  =  2",
            "checks": [
                ("(1)", None, None, "cut both 5s → 15−1 = 14", False),
                ("(2)", None, None, "(15−5)=10, then 10/5 = 2", True),
                ("(3)", None, None, "split: 15/5 − 5/5 = 3 − 1 = 2", True),
                ("(4)", None, None, "cut both 5s → claim 1", False),
            ],
        },
    },
    {
        "id": 4,
        "title": "Cut must hit every term",
        "level": "Class 6+",
        "hook_plain": "(12+8)/4",
        "wrong_cancel": "Cancel only 12 with 4 → 11 (wrong).",
        "why": "4 must divide both terms: 12/4 + 8/4 = 5, or 20/4 = 5.",
        "steps": [
            (False, "Cancel 12 with 4 → 11"),
            (True, "Add first: 20/4 = 5"),
            (True, "Split: 12/4 + 8/4 = 5"),
            (False, "Cancel 8 with 4 → 14"),
        ],
        "options": [
            "Steps (1) and (4) are correct",
            "Only step (2) is correct",
            "Steps (2) and (3) are both correct",
            "Only step (3) is correct",
        ],
        "correct_index": 2,
        "answer_value": "5",
        "difficulty": "Easy",
        "visual": {
            "wrong_num": [("12", True), ("+", False), ("8", False)],
            "wrong_den": ("4", True),
            "wrong_result": "= 11",
            "right_a": "(12 + 8) / 4  =  20 / 4  =  5",
            "right_b": "12/4 + 8/4  =  3 + 2  =  5",
            "checks": [
                ("(1)", None, None, "cut 12 with 4 → 3+8 = 11", False),
                ("(2)", None, None, "(12+8)=20, then 20/4 = 5", True),
                ("(3)", None, None, "split: 12/4 + 8/4 = 3 + 2 = 5", True),
                ("(4)", None, None, "cut 8 with 4 → 12+2 = 14", False),
            ],
        },
    },
    {
        "id": 5,
        "title": "Three terms",
        "level": "Class 6–7",
        "hook_plain": "(6+9+3)/3",
        "wrong_cancel": "Cancel the last 3 → 15 (wrong).",
        "why": "Every term is divided by 3: 2+3+1 = 6, or 18/3 = 6.",
        "steps": [
            (False, "Cancel last 3 → 15"),
            (True, "Add first: 18/3 = 6"),
            (True, "Split all three: 2+3+1 = 6"),
            (False, "Random cancel → 5"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are both correct",
            "Only step (4) is correct",
            "All steps are correct",
        ],
        "correct_index": 1,
        "answer_value": "6",
        "difficulty": "Medium",
        "visual": {
            "wrong_num": [("6", False), ("+", False), ("9", False), ("+", False), ("3", True)],
            "wrong_den": ("3", True),
            "wrong_result": "= 15",
            "right_a": "(6 + 9 + 3) / 3  =  18 / 3  =  6",
            "right_b": "6/3 + 9/3 + 3/3  =  2 + 3 + 1  =  6",
            "checks": [
                ("(1)", None, None, "cut last 3 → 6+9 = 15", False),
                ("(2)", None, None, "(6+9+3)=18, then 18/3 = 6", True),
                ("(3)", None, None, "split: 6/3 + 9/3 + 3/3 = 2+3+1 = 6", True),
                ("(4)", None, None, "random cut → claim 5", False),
            ],
        },
    },
    {
        "id": 6,
        "title": "When cutting is allowed",
        "level": "Class 6–7",
        "hook_plain": "2(7+1)/2 vs (2+7)/2",
        "wrong_cancel": "Illegal cancel in a sum vs legal cancel of a factor.",
        "why": "Cancel only a common FACTOR of the whole top. 2(7+1)/2 → 8 is legal. (2+7)/2 cannot cancel the 2.",
        "steps": [
            (True, "2(7+1)/2 → cancel factor 2 → 8"),
            (False, "(2+7)/2 → cancel the 2 → 7"),
            (True, "(2+7)/2 = 9/2"),
            (True, "2(7+1)/2 = 16/2 = 8"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (1), (3) and (4) are correct; (2) is wrong",
            "Steps (1) and (2) are both correct",
            "Only step (2) is correct",
        ],
        "correct_index": 1,
        "answer_value": "8 vs 9/2",
        "difficulty": "Medium",
        "visual": {
            "wrong_num": [("2", True), ("+", False), ("7", False)],
            "wrong_den": ("2", True),
            "wrong_result": "= 7",
            "legal_num": [("2", True), ("(7+1)", False)],
            "legal_den": ("2", True),
            "legal_result": "= 8",
            "right_a": "Factor form: cut the 2 → 7 + 1 = 8",
            "right_b": "Sum form: (2 + 7) / 2  =  9 / 2",
            "checks": [
                ("(1)", None, None, "factor form: cut 2 → (7+1)=8", True),
                ("(2)", None, None, "cut 2 in the sum (2+7)/2 → claim 7", False),
                ("(3)", None, None, "sum first: (2+7)=9, then 9/2", True),
                ("(4)", None, None, "expand: 2(7+1)=16, then 16/2 = 8", True),
            ],
        },
    },
    {
        "id": 7,
        "title": "Algebra — split",
        "level": "Class 7–8",
        "hook_plain": "(4x+2)/2",
        "wrong_cancel": "Cancel only the +2 → 4x+1 (wrong).",
        "why": "Split: 4x/2 + 2/2 = 2x+1. Or factor: 2(2x+1)/2 = 2x+1.",
        "steps": [
            (False, "Cancel the +2 → 4x+1"),
            (True, "Split: 2x+1"),
            (True, "Factor cancel: 2x+1"),
            (False, "Leave as 4x+2"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are both correct",
            "Only step (4) is correct",
            "Steps (1) and (2) are both correct",
        ],
        "correct_index": 1,
        "answer_value": "2x+1",
        "difficulty": "Medium",
        "visual": {
            "wrong_num": [("4x", False), ("+", False), ("2", True)],
            "wrong_den": ("2", True),
            "wrong_result": "= 4x + 1",
            "right_a": "4x/2 + 2/2  =  2x + 1",
            "right_b": "Or factor, then cut the 2:",
            "legal_num": [("2", True), ("(2x+1)", False)],
            "legal_den": ("2", True),
            "legal_result": "= 2x + 1",
            "checks": [
                ("(1)", None, None, "cut only the +2 → claim 4x+1", False),
                ("(2)", None, None, "split: 4x/2 + 2/2 = 2x + 1", True),
                ("(3)", None, None, "factor 2(2x+1)/2, cut 2 → 2x+1", True),
                ("(4)", None, None, "leave as 4x+2 (not simplified)", False),
            ],
        },
    },
    {
        "id": 8,
        "title": "Algebra — do not cut x in a sum",
        "level": "Class 7–8",
        "hook_plain": "(4x+2)/x",
        "wrong_cancel": "Cancel x in 4x → claim 6 (classic trap).",
        "why": "Correct: 4 + 2/x. Check x=2: left is 5, so 6 fails.",
        "steps": [
            (False, "Cancel x → 6"),
            (True, "Split: 4 + 2/x"),
            (False, "Cancel x → 4"),
            (True, "x=2 check shows 6 fails"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (4) are correct; (1) and (3) are wrong",
            "Only step (3) is correct",
            "All four steps are correct",
        ],
        "correct_index": 1,
        "answer_value": "4 + 2/x",
        "difficulty": "Medium",
        "visual": {
            "wrong_num": [("4", False), ("x", True), ("+", False), ("2", False)],
            "wrong_den": ("x", True),
            "wrong_result": "= 6",
            "right_a": "4x/x + 2/x  =  4 + 2/x",
            "right_b": "Check: if x = 2, value is 5  (not 6)",
            "checks": [
                ("(1)", None, None, "cut x → claim 4+2 = 6", False),
                ("(2)", None, None, "split: 4x/x + 2/x = 4 + 2/x", True),
                ("(3)", None, None, "cut x → claim only 4", False),
                ("(4)", None, None, "check x=2: (8+2)/2 = 5, so 6 fails", True),
            ],
        },
    },
    {
        "id": 9,
        "title": "Algebra — subtract then factor",
        "level": "Class 7–8",
        "hook_plain": "(6x-3)/3",
        "wrong_cancel": "Cancel only −3 → 6x−1 (wrong).",
        "why": "Split: 2x−1. Or factor: 3(2x−1)/3 = 2x−1.",
        "steps": [
            (False, "Cancel −3 → 6x−1"),
            (True, "Split: 2x−1"),
            (True, "Factor: 2x−1"),
            (False, "Claim answer is 6x"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are both correct",
            "Only step (4) is correct",
            "Steps (1) and (4) are both correct",
        ],
        "correct_index": 1,
        "answer_value": "2x-1",
        "difficulty": "Medium",
        "visual": {
            "wrong_num": [("6x", False), ("−", False), ("3", True)],
            "wrong_den": ("3", True),
            "wrong_result": "= 6x − 1",
            "right_a": "6x/3 − 3/3  =  2x − 1",
            "right_b": "Or factor, then cut the 3:",
            "legal_num": [("3", True), ("(2x−1)", False)],
            "legal_den": ("3", True),
            "legal_result": "= 2x − 1",
            "checks": [
                ("(1)", None, None, "cut only −3 → claim 6x−1", False),
                ("(2)", None, None, "split: 6x/3 − 3/3 = 2x − 1", True),
                ("(3)", None, None, "factor 3(2x−1)/3, cut 3 → 2x−1", True),
                ("(4)", None, None, "claim answer is 6x", False),
            ],
        },
    },
    {
        "id": 10,
        "title": "Master — factor then cut",
        "level": "Class 8+",
        "hook_plain": "(2x+2)/(x+1)",
        "wrong_cancel": "Piece-cancel +2, or cancel x carelessly.",
        "why": "Factor: 2(x+1)/(x+1) = 2 (x≠−1). Cancel the common factor (x+1), not pieces.",
        "steps": [
            (False, "Piece-cancel → 2x/(x+1)"),
            (True, "Factor → 2"),
            (True, "x=3 check → 2"),
            (False, "Cancel x terms (illegal)"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are correct; (1) wrong; (4) illegal",
            "All four steps use legal methods",
            "Only step (4) is a safe method",
        ],
        "correct_index": 1,
        "answer_value": "2",
        "difficulty": "Hard",
        "visual": {
            "wrong_num": [("2x", False), ("+", False), ("2", True)],
            "wrong_den": ("x+1", False),
            "wrong_result": "?",
            "right_a": "Factor: 2(x + 1) / (x + 1)",
            "right_b": "Then cut (x + 1)  →  2   (x ≠ −1)",
            "legal_num": [("2", False), ("(x+1)", True)],
            "legal_den": ("(x+1)", True),
            "legal_result": "= 2",
            "checks": [
                ("(1)", None, None, "piece-cut the +2 → leave 2x/(x+1)", False),
                ("(2)", None, None, "factor: 2(x+1)/(x+1), cut (x+1) → 2", True),
                ("(3)", None, None, "check x=3: (6+2)/4 = 8/4 = 2", True),
                ("(4)", None, None, "cut x terms (illegal method)", False),
            ],
        },
    },
]


# ---------------------------------------------------------------------------
# Drawing helpers — notebook-style cancel strikes
# ---------------------------------------------------------------------------

def text_size(draw: ImageDraw.ImageDraw, text: str, fnt) -> tuple[int, int]:
    box = draw.textbbox((0, 0), text, font=fnt)
    return box[2] - box[0], box[3] - box[1]


def strike_box(draw: ImageDraw.ImageDraw, x0: int, y0: int, x1: int, y1: int, color: str, width: int = 6):
    """Thick pen slash through a digit — how students cut/cancel in notebooks."""
    # Extend past the digit so the cut is unmistakable.
    pad_x, pad_y = 8, 10
    a = (x0 - pad_x, y1 + pad_y)
    b = (x1 + pad_x, y0 - pad_y)
    # Main stroke + slight parallel stroke (felt-pen look)
    draw.line([a, b], fill=color, width=width)
    draw.line([(a[0] + 3, a[1]), (b[0] + 3, b[1])], fill=color, width=max(2, width - 3))


def draw_fraction_with_cancels(
    draw: ImageDraw.ImageDraw,
    origin: tuple[int, int],
    num_parts: list[tuple[str, bool]] | None,
    den: tuple[str, bool] | None,
    *,
    result: str = "",
    strike_color: str = "#dc2626",
    fnt=None,
    label: str = "",
    strike_width: int = 6,
) -> int:
    """Draw a fraction; True parts get diagonal cancel strikes. Returns right edge x."""
    fnt = fnt or font(34)
    small = font(18)
    x, y = origin

    if label:
        draw.text((x, y - 28), label, fill="#64748b", font=small)
        x += text_size(draw, label + "  ", small)[0]

    if num_parts is None:
        draw.text((x, y + 10), result, fill="#0f172a", font=fnt)
        return x + text_size(draw, result, fnt)[0]

    # measure numerator
    gap = 6
    num_w = 0
    parts_meta = []
    for text, cancel in num_parts:
        tw, th = text_size(draw, text, fnt)
        parts_meta.append((text, cancel, tw, th))
        num_w += tw + gap
    num_w = max(0, num_w - gap)

    den_text, den_cancel = den if den else ("", False)
    den_w, den_h = text_size(draw, den_text, fnt) if den_text else (0, 0)
    bar_w = max(num_w, den_w) + 16

    # numerator
    nx = x + (bar_w - num_w) // 2
    ny = y
    for text, cancel, tw, th in parts_meta:
        draw.text((nx, ny), text, fill="#0f172a", font=fnt)
        if cancel:
            strike_box(draw, nx, ny + 2, nx + tw, ny + th - 2, strike_color, width=strike_width)
        nx += tw + gap

    # bar
    bar_y = y + max(th for *_, th in parts_meta) + 8
    draw.line([(x, bar_y), (x + bar_w, bar_y)], fill="#0f172a", width=4)

    # denominator
    dx = x + (bar_w - den_w) // 2
    dy = bar_y + 10
    if den_text:
        draw.text((dx, dy), den_text, fill="#0f172a", font=fnt)
        if den_cancel:
            strike_box(draw, dx, dy + 2, dx + den_w, dy + den_h - 2, strike_color, width=strike_width)

    rx = x + bar_w + 16
    if result:
        draw.text((rx, y + 18), result, fill="#0f172a", font=fnt)
        rx += text_size(draw, result, fnt)[0] + 8

    return rx


def rounded_rect(draw, box, fill, outline, width=2, radius=16):
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def section_label(draw, x: int, y: int, step: str, title: str, color: str):
    draw.text((x, y), step, fill=color, font=font_bold(18))
    draw.text((x + 70, y), title, fill=color, font=font_bold(20))


def check_line_text(item: tuple) -> str:
    """Plain text for quiz screen — full working, not only the final number."""
    label = item[0]
    result = item[-2] if isinstance(item[-2], str) else ""
    # Avoid double label if result already starts with (n)
    text = result.strip()
    if text.startswith("("):
        return text
    return f"{label}  {text}".strip()


def draw_card_figure(card: dict, path: Path) -> None:
    """
    Teach screen only (Wrong → Right).
    MCQ is a separate next step in the app — not on this figure.
    """
    img = Image.new("RGB", (W, H), "#ffffff")
    draw = ImageDraw.Draw(img)
    v = card["visual"]
    body = font(24)
    math = font(44)
    math_sm = font(32)

    # Header
    draw.text((36, 22), f"Card {card['id']}", fill="#94a3b8", font=font(15))
    draw.text((36, 44), card["title"], fill="#0f172a", font=font_bold(26))
    draw.line([(28, 84), (W - 28, 84)], fill="#e2e8f0", width=1)

    # ----- 1. WRONG -----
    y0 = 104
    rounded_rect(draw, (28, y0, W - 28, y0 + 220), "#fff7f7", "#fecaca", width=2, radius=16)
    section_label(draw, 48, y0 + 16, "1", "Wrong", "#b91c1c")

    draw_fraction_with_cancels(
        draw, (72, y0 + 70),
        v["wrong_num"], v["wrong_den"],
        result=v.get("wrong_result", ""),
        strike_color="#dc2626",
        fnt=math,
        strike_width=9,
    )
    bx, by = W - 118, y0 + 85
    draw.ellipse((bx, by, bx + 58, by + 58), fill="#fee2e2", outline="#dc2626", width=3)
    draw.line([(bx + 17, by + 17), (bx + 41, by + 41)], fill="#dc2626", width=5)
    draw.line([(bx + 41, by + 17), (bx + 17, by + 41)], fill="#dc2626", width=5)

    # ----- 2. RIGHT -----
    y1 = y0 + 244
    has_legal = bool(v.get("legal_num") and v.get("legal_den"))
    right_bottom = H - 28
    rounded_rect(draw, (28, y1, W - 28, right_bottom), "#f7fdf9", "#bbf7d0", width=2, radius=16)
    section_label(draw, 48, y1 + 16, "2", "Right", "#15803d")

    draw.text((48, y1 + 58), v["right_a"], fill="#14532d", font=body)
    draw.text((48, y1 + 98), v["right_b"], fill="#14532d", font=body)

    if has_legal:
        draw_fraction_with_cancels(
            draw, (72, y1 + 150),
            v["legal_num"], v["legal_den"],
            result=v.get("legal_result", ""),
            strike_color="#16a34a",
            fnt=math_sm,
            strike_width=7,
        )

    path.parent.mkdir(parents=True, exist_ok=True)
    img.save(path, "PNG")


def build_questions() -> list[dict]:
    rows = []
    for card in CARDS:
        true_ids = [i + 1 for i, (ok, _) in enumerate(card["steps"]) if ok]
        false_ids = [i + 1 for i, (ok, _) in enumerate(card["steps"]) if not ok]
        check_lines = [check_line_text(item) for item in card["visual"]["checks"]]
        rows.append({
            "type": "mcq",
            "topic": "Fraction simplification (cancel traps)",
            "question": (
                f"For {card['hook_plain']}, which statement about steps (1)–(4) is correct?"
            ),
            "needs_diagram": True,
            "diagram_file": f"q{card['id']}.png",
            "figure_spec": (
                f"Teach screen only for {card['hook_plain']}: wrong cancel with pen cuts, then right methods."
            ),
            "check_lines": check_lines,
            "options": card["options"],
            "correct_index": card["correct_index"],
            "method_hint": (
                "If the numerator is a sum/difference, split the fraction or factor first. "
                "Cancel only a common factor of the whole top and bottom."
            ),
            "explanation": (
                f"{card['why']} "
                f"True: {true_ids}. False: {false_ids}. "
                f"Result: {card['answer_value']}. "
                f"Final answer = option {chr(65 + card['correct_index'])}"
            ),
            "difficulty": card["difficulty"],
        })
    return rows


def write_teacher_guide() -> None:
    lines = [
        "# Fraction Simplification Cards (Class 6+)",
        "",
        "Figures show **notebook-style cutting** (diagonal strike-through) on cancelled numbers.",
        "",
        "**Golden rule:** Cancel only a common **factor** of the *whole* numerator and denominator.",
        "",
        "---",
        "",
    ]
    for card in CARDS:
        lines.append(f"## Card {card['id']}. {card['title']} ({card['level']})")
        lines.append("")
        lines.append(f"- **Hook:** `{card['hook_plain']}`")
        lines.append(f"- **Wrong cancel:** {card['wrong_cancel']}")
        lines.append(f"- **Why:** {card['why']}")
        lines.append(f"- **Result:** `{card['answer_value']}`")
        lines.append(f"- **MCQ:** `{card['options'][card['correct_index']]}`")
        lines.append("")
    (ROOT / "TEACHER_GUIDE.md").write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    DIAG.mkdir(parents=True, exist_ok=True)
    for card in CARDS:
        draw_card_figure(card, DIAG / f"q{card['id']}.png")
        print(f"wrote q{card['id']}.png")

    rows = build_questions()
    OUT_JSON.write_text(json.dumps({"questions": rows}, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {OUT_JSON.name}")
    write_teacher_guide()
    print("wrote TEACHER_GUIDE.md")

    if OUT_ZIP.exists():
        OUT_ZIP.unlink()
    with zipfile.ZipFile(OUT_ZIP, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        zf.write(OUT_JSON, arcname="questions.json")
        for i in range(1, 11):
            zf.write(DIAG / f"q{i}.png", arcname=f"q{i}.png")
    print(f"wrote {OUT_ZIP.name}")


if __name__ == "__main__":
    main()
