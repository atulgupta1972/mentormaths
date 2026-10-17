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

W, H = 900, 680


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
            "wrong_num": [("4", True, "1"), ("+", False), ("5", False)],
            "wrong_den": ("4", True, "1"),
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
            "wrong_num": [("44", False), ("+", False), ("11", True, "1")],
            "wrong_den": ("11", True, "1"),
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
            "wrong_num": [("15", False), ("−", False), ("5", True, "1")],
            "wrong_den": ("5", True, "1"),
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
            "wrong_num": [("12", True, "3"), ("+", False), ("8", False)],
            "wrong_den": ("4", True, "1"),
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
            "wrong_num": [("6", False), ("+", False), ("9", False), ("+", False), ("3", True, "1")],
            "wrong_den": ("3", True, "1"),
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
        "hook_plain": "(2×7+2)/2",
        "wrong_cancel": "Cut only one lone 2 → claim 14 (did not take common factor).",
        "why": "First take common: 2(7+1)/2 = 8. Cutting one single 2 from (2×7+2)/2 is illegal — both 2s belong to the common factor.",
        "steps": [
            (True, "Take common: 2(7+1)/2 → cut 2 → 8"),
            (False, "Cut one 2 from (2×7+2)/2 → claim 14"),
            (True, "Expand first: 14+2=16, then 16/2 = 8"),
            (True, "2(7+1)/2 = 16/2 = 8"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (1), (3) and (4) are correct; (2) is wrong",
            "Steps (1) and (2) are both correct",
            "Only step (2) is correct",
        ],
        "correct_index": 1,
        "answer_value": "8",
        "difficulty": "Medium",
        "visual": {
            # Wrong: cut one lone +2 with den 2 → claim 2×7 = 14 (skipped taking common).
            "wrong_num": [("2", False), ("×", False), ("7", False), ("+", False), ("2", True, "1")],
            "wrong_den": ("2", True, "1"),
            "wrong_result": "= 14",
            "legal_num": [("2", True, "1"), ("(7+1)", False)],
            "legal_den": ("2", True, "1"),
            "legal_result": "= 8",
            "right_a": "Take common first: 2(7 + 1) / 2",
            "right_b": "Then cut the common factor 2 → 7 + 1 = 8",
            "checks": [
                ("(1)", None, None, "take common: 2(7+1)/2, cut 2 → 8", True),
                ("(2)", None, None, "cut one lone 2 from (2×7+2)/2 → claim 14", False),
                ("(3)", None, None, "expand first: 2×7+2=16, then 16/2 = 8", True),
                ("(4)", None, None, "2(7+1)=16, then 16/2 = 8", True),
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
            "wrong_num": [("4x", False), ("+", False), ("2", True, "1")],
            "wrong_den": ("2", True, "1"),
            "wrong_result": "= 4x + 1",
            "right_a": "4x/2 + 2/2  =  2x + 1",
            "right_b": "Or factor, then cut the 2:",
            "legal_num": [("2", True, "1"), ("(2x+1)", False)],
            "legal_den": ("2", True, "1"),
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
            "wrong_num": [("4", False), ("x", True, "1"), ("+", False), ("2", False)],
            "wrong_den": ("x", True, "1"),
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
            "wrong_num": [("6x", False), ("−", False), ("3", True, "1")],
            "wrong_den": ("3", True, "1"),
            "wrong_result": "= 6x − 1",
            "right_a": "6x/3 − 3/3  =  2x − 1",
            "right_b": "Or factor, then cut the 3:",
            "legal_num": [("3", True, "1"), ("(2x−1)", False)],
            "legal_den": ("3", True, "1"),
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
        "wrong_cancel": "Cut x with x → claim 2+2 = 4 (classic trap).",
        "why": "Factor: 2(x+1)/(x+1) = 2 (x≠−1). Cancel the common factor (x+1), not the letter x inside a sum.",
        "steps": [
            (False, "Cut x with x → claim 4"),
            (True, "Factor → 2"),
            (True, "x=3 check → 2"),
            (False, "Piece-cancel +2 (illegal)"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are correct; (1) and (4) are wrong",
            "All four steps use legal methods",
            "Only step (4) is a safe method",
        ],
        "correct_index": 1,
        "answer_value": "2",
        "difficulty": "Hard",
        "visual": {
            # Student cuts x↔x and writes 2+2=4 (wrong).
            "wrong_num": [("2", False), ("x", True, ""), ("+", False), ("2", False)],
            "wrong_den": [("x", True, ""), ("+", False), ("1", False)],
            "wrong_result": "= 4",
            "right_a": "Factor: 2(x + 1) / (x + 1)",
            "right_b": "Then cut (x + 1)  →  2   (x ≠ −1)",
            "legal_num": [("2", False), ("(x+1)", True, "1")],
            "legal_den": ("(x+1)", True, "1"),
            "legal_result": "= 2",
            "checks": [
                ("(1)", None, None, "cut x with x → claim 2+2 = 4", False),
                ("(2)", None, None, "factor: 2(x+1)/(x+1), cut (x+1) → 2", True),
                ("(3)", None, None, "check x=3: (6+2)/4 = 8/4 = 2", True),
                ("(4)", None, None, "piece-cut the +2 (illegal method)", False),
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
    pad_x, pad_y = 8, 10
    a = (x0 - pad_x, y1 + pad_y)
    b = (x1 + pad_x, y0 - pad_y)
    draw.line([a, b], fill=color, width=width)
    draw.line([(a[0] + 3, a[1]), (b[0] + 3, b[1])], fill=color, width=max(2, width - 3))


def parse_part(part) -> tuple[str, bool, str | None]:
    """
    Part formats:
      ("12", False)           → no cut
      ("12", True)            → cut, quotient defaults to "1"
      ("12", True, "3")       → cut, show quotient 3 (as in copy)
      ("2", True, "")         → cut only, no quotient label
    """
    if not isinstance(part, (list, tuple)) or len(part) < 2:
        return str(part), False, None
    text = str(part[0])
    flag = part[1]
    if flag is False or flag is None:
        return text, False, None
    if len(part) >= 3:
        raw = part[2]
        if raw is None:
            return text, True, "1"
        s = str(raw).strip()
        return text, True, (s if s else None)
    return text, True, "1"


def normalize_parts(parts) -> list:
    """Accept a single part tuple or a list of part tuples."""
    if parts is None:
        return []
    if isinstance(parts, (list, tuple)) and parts and isinstance(parts[0], str):
        return [parts]
    if isinstance(parts, (list, tuple)):
        return list(parts)
    return []


def draw_quotient(
    draw: ImageDraw.ImageDraw,
    cx: int,
    cy: int,
    quotient: str,
    color: str,
    fnt,
):
    """Small number above a cut term — how students rewrite after cancelling."""
    tw, th = text_size(draw, quotient, fnt)
    draw.text((cx - tw // 2, cy - th - 2), quotient, fill=color, font=fnt)


def draw_fraction_with_cancels(
    draw: ImageDraw.ImageDraw,
    origin: tuple[int, int],
    num_parts: list | None,
    den: tuple | list | None,
    *,
    result: str = "",
    strike_color: str = "#dc2626",
    fnt=None,
    label: str = "",
    strike_width: int = 6,
) -> int:
    """Draw a fraction; cancelled parts get a slash + quotient (copy style)."""
    fnt = fnt or font(34)
    try:
        q_size = max(18, int(getattr(fnt, "size", 34) * 0.48))
    except Exception:
        q_size = 20
    q_fnt = font_bold(q_size)

    small = font(18)
    x, y = origin

    if label:
        draw.text((x, y - 28), label, fill="#64748b", font=small)
        x += text_size(draw, label + "  ", small)[0]

    if num_parts is None:
        draw.text((x, y + 10), result, fill="#0f172a", font=fnt)
        return x + text_size(draw, result, fnt)[0]

    # Room above numerator for quotients (e.g. 3 above cut 12).
    y = y + 26

    gap = 8
    num_w = 0
    parts_meta = []
    for part in num_parts:
        text, cancel, quot = parse_part(part)
        tw, th = text_size(draw, text, fnt)
        parts_meta.append((text, cancel, quot, tw, th))
        num_w += tw + gap
    num_w = max(0, num_w - gap)

    den_parts = normalize_parts(den)
    den_meta = []
    den_w = 0
    for part in den_parts:
        text, cancel, quot = parse_part(part)
        tw, th = text_size(draw, text, fnt)
        den_meta.append((text, cancel, quot, tw, th))
        den_w += tw + gap
    den_w = max(0, den_w - gap) if den_meta else 0
    den_h = max((th for *_, th in den_meta), default=0)
    bar_w = max(num_w, den_w) + 16

    # numerator — quotient sits above the cut term
    nx = x + (bar_w - num_w) // 2
    ny = y
    for text, cancel, quot, tw, th in parts_meta:
        draw.text((nx, ny), text, fill="#0f172a", font=fnt)
        if cancel:
            strike_box(draw, nx, ny + 2, nx + tw, ny + th - 2, strike_color, width=strike_width)
            if quot:
                draw_quotient(draw, nx + tw // 2, ny, quot, strike_color, q_fnt)
        nx += tw + gap

    # bar
    bar_y = y + max(th for *_, th in parts_meta) + 8
    draw.line([(x, bar_y), (x + bar_w, bar_y)], fill="#0f172a", width=4)

    # denominator — quotient sits below each cut term (notebook style)
    dx = x + (bar_w - den_w) // 2
    dy = bar_y + 10
    for text, cancel, quot, tw, th in den_meta:
        draw.text((dx, dy), text, fill="#0f172a", font=fnt)
        if cancel:
            strike_box(draw, dx, dy + 2, dx + tw, dy + th - 2, strike_color, width=strike_width)
            if quot:
                qtw, _qth = text_size(draw, quot, q_fnt)
                draw.text(
                    (dx + tw // 2 - qtw // 2, dy + th + 2),
                    quot,
                    fill=strike_color,
                    font=q_fnt,
                )
        dx += tw + gap

    rx = x + bar_w + 16
    if result:
        draw.text((rx, y + 8), result, fill="#0f172a", font=fnt)
        rx += text_size(draw, result, fnt)[0] + 8

    return rx


def rounded_rect(draw, box, fill, outline, width=2, radius=16):
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def section_label(draw, x: int, y: int, step: str, title: str, color: str):
    draw.text((x, y), step, fill=color, font=font_bold(18))
    draw.text((x + 70, y), title, fill=color, font=font_bold(20))


def check_line_text(item: tuple) -> str:
    """Plain text for quiz screen — always keep (1)/(2)/(3)/(4) prefix."""
    label = str(item[0]).strip()
    result = item[-2] if isinstance(item[-2], str) else ""
    text = result.strip()
    # Only skip re-adding label if the line already begins with that exact step tag.
    if text.startswith(f"{label} ") or text == label:
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
    wrong_h = 248
    rounded_rect(draw, (28, y0, W - 28, y0 + wrong_h), "#fff7f7", "#fecaca", width=2, radius=16)
    section_label(draw, 48, y0 + 16, "1", "Wrong", "#b91c1c")

    draw_fraction_with_cancels(
        draw, (72, y0 + 62),
        v["wrong_num"], v["wrong_den"],
        result=v.get("wrong_result", ""),
        strike_color="#dc2626",
        fnt=math,
        strike_width=9,
    )
    bx, by = W - 118, y0 + 95
    draw.ellipse((bx, by, bx + 58, by + 58), fill="#fee2e2", outline="#dc2626", width=3)
    draw.line([(bx + 17, by + 17), (bx + 41, by + 41)], fill="#dc2626", width=5)
    draw.line([(bx + 41, by + 17), (bx + 17, by + 41)], fill="#dc2626", width=5)

    # ----- 2. RIGHT -----
    y1 = y0 + wrong_h + 20
    has_legal = bool(v.get("legal_num") and v.get("legal_den"))
    right_bottom = H - 28
    rounded_rect(draw, (28, y1, W - 28, right_bottom), "#f7fdf9", "#bbf7d0", width=2, radius=16)
    section_label(draw, 48, y1 + 16, "2", "Right", "#15803d")

    draw.text((48, y1 + 52), v["right_a"], fill="#14532d", font=body)
    draw.text((48, y1 + 88), v["right_b"], fill="#14532d", font=body)

    if has_legal:
        draw_fraction_with_cancels(
            draw, (72, y1 + 128),
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
