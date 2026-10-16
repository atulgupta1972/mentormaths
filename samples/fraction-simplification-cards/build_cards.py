#!/usr/bin/env python3
"""
Fraction Simplification Cards (Class 6+)

Teaches the common trap: you cannot cancel a term that is only part of a
sum/difference in the numerator. Cancellation is legal only for common
FACTORS of the whole numerator and denominator.

Builds:
  diagrams/q1.png … q10.png
  questions.json  (MCQ import)
  fraction-simplification-cards.zip
  TEACHER_GUIDE.md
"""

from __future__ import annotations

import json
import zipfile
from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch

ROOT = Path(__file__).resolve().parent
DIAG = ROOT / "diagrams"
OUT_JSON = ROOT / "questions.json"
OUT_ZIP = ROOT / "fraction-simplification-cards.zip"


CARDS = [
    {
        "id": 1,
        "title": "Cancel trap — numbers in a sum",
        "level": "Class 6+",
        "hook": r"$\dfrac{4+5}{4}$",
        "hook_plain": "(4+5)/4",
        "wrong_cancel": "Cancel both 4s → left with 5 (illegal).",
        "why": (
            "The bar means the whole numerator is divided by 4. "
            "So (4+5)/4 = 4/4 + 5/4 = 1 + 5/4 = 9/4, not 5."
        ),
        "steps": [
            (False, "Cancel 4s: get 5"),
            (True, "Add first: 9/4"),
            (True, "Split: 4/4 + 5/4 = 9/4"),
            (False, "Cancel one digit only: get 45/4"),
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
        "figure": {
            "wrong": r"$\dfrac{4+5}{4}$  → cancel 4s →  $5$",
            "right_a": r"$\dfrac{4+5}{4}=\dfrac{9}{4}$",
            "right_b": r"$\dfrac{4}{4}+\dfrac{5}{4}=\dfrac{9}{4}$",
            "check_title": r"Check these for $\dfrac{4+5}{4}$",
            "lines": [
                r"(1) Cancel 4s → $5$",
                r"(2) $\dfrac{9}{4}$",
                r"(3) $\dfrac{4}{4}+\dfrac{5}{4}=\dfrac{9}{4}$",
                r"(4) Partial cancel → $45/4$",
            ],
        },
    },
    {
        "id": 2,
        "title": "Same trap with a matching addend",
        "level": "Class 6+",
        "hook": r"$\dfrac{44+11}{11}$",
        "hook_plain": "(44+11)/11",
        "wrong_cancel": "Cancel the two 11s → 44+1 = 45 (very common, still wrong).",
        "why": (
            "11 divides the whole sum 44+11. "
            "Correct: 55/11 = 5, or 44/11 + 11/11 = 4+1 = 5. Never 45."
        ),
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
        "figure": {
            "wrong": r"$\dfrac{44+11}{11}$  → cancel 11s →  $45$",
            "right_a": r"$\dfrac{55}{11}=5$",
            "right_b": r"$\dfrac{44}{11}+\dfrac{11}{11}=5$",
            "check_title": "Based on this, check true / false",
            "lines": [
                r"(1) $=45$   (cancel 11s)",
                r"(2) $=\dfrac{55}{11}=5$",
                r"(3) $=\dfrac{44}{11}+\dfrac{11}{11}=5$",
                r"(4) $=15$   (bad partial cancel)",
            ],
        },
    },
    {
        "id": 3,
        "title": "Subtraction — same rule",
        "level": "Class 6+",
        "hook": r"$\dfrac{15-5}{5}$",
        "hook_plain": "(15-5)/5",
        "wrong_cancel": "Cancel both 5s → 15−1 = 14, or just 1 (both wrong).",
        "why": (
            "Minus does not allow cancel of a single term. "
            "(15−5)/5 = 10/5 = 2, or 15/5 − 5/5 = 3−1 = 2."
        ),
        "steps": [
            (False, "Cancel 5s → 15−1 = 14"),
            (True, "Subtract first: 10/5 = 2"),
            (True, "Split: 15/5 − 5/5 = 2"),
            (False, "Cancel 5s → get 1"),
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
        "figure": {
            "wrong": r"$\dfrac{15-5}{5}$  → cancel 5s →  $14$ or $1$",
            "right_a": r"$\dfrac{10}{5}=2$",
            "right_b": r"$\dfrac{15}{5}-\dfrac{5}{5}=2$",
            "check_title": r"Check these for $\dfrac{15-5}{5}$",
            "lines": [
                r"(1) Cancel → $14$",
                r"(2) $\dfrac{10}{5}=2$",
                r"(3) $3-1=2$",
                r"(4) Cancel → $1$",
            ],
        },
    },
    {
        "id": 4,
        "title": "Denominator divides every term",
        "level": "Class 6+",
        "hook": r"$\dfrac{12+8}{4}$",
        "hook_plain": "(12+8)/4",
        "wrong_cancel": "Cancel only 12 with 4 → 3+8 = 11 (wrong).",
        "why": (
            "4 must divide both 12 and 8. "
            "12/4 + 8/4 = 3+2 = 5, or (12+8)/4 = 20/4 = 5."
        ),
        "steps": [
            (False, "Cancel 12 with 4 → 3+8 = 11"),
            (True, "Add first: 20/4 = 5"),
            (True, "Split: 12/4 + 8/4 = 5"),
            (False, "Cancel 8 with 4 → 12+2 = 14"),
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
        "figure": {
            "wrong": r"$\dfrac{12+8}{4}$  → cancel one pair only →  $11$ or $14$",
            "right_a": r"$\dfrac{20}{4}=5$",
            "right_b": r"$\dfrac{12}{4}+\dfrac{8}{4}=5$",
            "check_title": r"Check these for $\dfrac{12+8}{4}$",
            "lines": [
                r"(1) Cancel 12↔4 → $11$",
                r"(2) $\dfrac{20}{4}=5$",
                r"(3) $3+2=5$",
                r"(4) Cancel 8↔4 → $14$",
            ],
        },
    },
    {
        "id": 5,
        "title": "Three terms — cancel still illegal",
        "level": "Class 6–7",
        "hook": r"$\dfrac{6+9+3}{3}$",
        "hook_plain": "(6+9+3)/3",
        "wrong_cancel": "Cancel the last 3 with the denominator → 6+9 = 15 (wrong).",
        "why": (
            "Every term is divided by 3: 6/3 + 9/3 + 3/3 = 2+3+1 = 6. "
            "Or add first: 18/3 = 6."
        ),
        "steps": [
            (False, "Cancel last 3 → 6+9 = 15"),
            (True, "Add first: 18/3 = 6"),
            (True, "Split all three: 2+3+1 = 6"),
            (False, "Random cancel → claim 5"),
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
        "figure": {
            "wrong": r"$\dfrac{6+9+3}{3}$  → cancel last 3 →  $15$",
            "right_a": r"$\dfrac{18}{3}=6$",
            "right_b": r"$2+3+1=6$",
            "check_title": r"Check these for $\dfrac{6+9+3}{3}$",
            "lines": [
                r"(1) Cancel last 3 → $15$",
                r"(2) $\dfrac{18}{3}=6$",
                r"(3) $2+3+1=6$",
                r"(4) Random cancel → $5$",
            ],
        },
    },
    {
        "id": 6,
        "title": "Golden rule — when cancel IS allowed",
        "level": "Class 6–7",
        "hook": r"$\dfrac{2(7+1)}{2}$ vs $\dfrac{2+7}{2}$",
        "hook_plain": "2(7+1)/2 vs (2+7)/2",
        "wrong_cancel": "Students mix factor-cancel with sum-cancel.",
        "why": (
            "Cancel only a common FACTOR of the whole top and bottom. "
            "2(7+1)/2 → cancel factor 2 → 8 is legal. "
            "(2+7)/2 cannot cancel the 2; correct value is 9/2."
        ),
        "steps": [
            (True, "2(7+1)/2 → cancel factor 2 → 8"),
            (False, "(2+7)/2 → cancel the 2 → 7"),
            (True, "(2+7)/2 = 2/2 + 7/2 = 9/2"),
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
        "figure": {
            "wrong": r"Illegal: $\dfrac{2+7}{2}$ → cancel 2 → $7$",
            "right_a": r"Legal: $\dfrac{2(7+1)}{2}=8$",
            "right_b": r"Split sum: $\dfrac{2+7}{2}=\dfrac{9}{2}$",
            "check_title": "Which statements are true?",
            "lines": [
                r"(1) $\dfrac{2(7+1)}{2}=8$  (factor cancel OK)",
                r"(2) $\dfrac{2+7}{2}=7$  (illegal cancel)",
                r"(3) $\dfrac{2+7}{2}=\dfrac{9}{2}$",
                r"(4) $\dfrac{2(7+1)}{2}=8$ also via $16/2$",
            ],
        },
    },
    {
        "id": 7,
        "title": "Algebra — split the fraction",
        "level": "Class 7–8",
        "hook": r"$\dfrac{4x+2}{2}$",
        "hook_plain": "(4x+2)/2",
        "wrong_cancel": "Cancel only the +2 with denominator 2 → 4x+1 (wrong).",
        "why": (
            "Split: 4x/2 + 2/2 = 2x + 1. "
            "Or factor: 2(2x+1)/2 = 2x+1. Cancelling only one term is illegal."
        ),
        "steps": [
            (False, "Cancel the +2 → 4x+1"),
            (True, "Split: 4x/2 + 2/2 = 2x+1"),
            (True, "Factor: 2(2x+1)/2 = 2x+1"),
            (False, "Leave as 4x+2 (not simplified)"),
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
        "figure": {
            "wrong": r"$\dfrac{4x+2}{2}$  → cancel +2 →  $4x+1$",
            "right_a": r"$\dfrac{4x}{2}+\dfrac{2}{2}=2x+1$",
            "right_b": r"$\dfrac{2(2x+1)}{2}=2x+1$",
            "check_title": r"Check these for $\dfrac{4x+2}{2}$",
            "lines": [
                r"(1) Cancel +2 → $4x+1$",
                r"(2) Split → $2x+1$",
                r"(3) Factor cancel → $2x+1$",
                r"(4) Leave as $4x+2$",
            ],
        },
    },
    {
        "id": 8,
        "title": "Algebra — never cancel a variable in a sum",
        "level": "Class 7–8",
        "hook": r"$\dfrac{4x+2}{x}$",
        "hook_plain": "(4x+2)/x",
        "wrong_cancel": "Cancel x in 4x with denominator x → 4+2 = 6 (classic trap).",
        "why": (
            "x is not a factor of the whole numerator. "
            "Correct: 4x/x + 2/x = 4 + 2/x. Not 6. "
            "Check x=2: left side is 5, so '6' fails."
        ),
        "steps": [
            (False, "Cancel x → 4+2 = 6"),
            (True, "Split: 4x/x + 2/x = 4 + 2/x"),
            (False, "Cancel x → get 4"),
            (True, "For x=2: value is 5, while '6' fails"),
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
        "figure": {
            "wrong": r"$\dfrac{4x+2}{x}$  → cancel $x$ →  $6$",
            "right_a": r"$\dfrac{4x}{x}+\dfrac{2}{x}=4+\dfrac{2}{x}$",
            "right_b": r"Check $x=2$: left $=5$, not $6$",
            "check_title": r"Check these for $\dfrac{4x+2}{x}$",
            "lines": [
                r"(1) Cancel $x$ → $6$",
                r"(2) $=4+\dfrac{2}{x}$",
                r"(3) Cancel $x$ → $4$",
                r"(4) $x=2$ check shows $6$ fails",
            ],
        },
    },
    {
        "id": 9,
        "title": "Algebra subtraction + factor path",
        "level": "Class 7–8",
        "hook": r"$\dfrac{6x-3}{3}$",
        "hook_plain": "(6x-3)/3",
        "wrong_cancel": "Cancel only −3 with 3 → 6x−1 (wrong).",
        "why": (
            "Split: 6x/3 − 3/3 = 2x − 1. "
            "Or factor: 3(2x−1)/3 = 2x−1."
        ),
        "steps": [
            (False, "Cancel −3 → 6x−1"),
            (True, "Split: 2x − 1"),
            (True, "Factor: 3(2x−1)/3 = 2x−1"),
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
        "figure": {
            "wrong": r"$\dfrac{6x-3}{3}$  → cancel $-3$ →  $6x-1$",
            "right_a": r"$\dfrac{6x}{3}-\dfrac{3}{3}=2x-1$",
            "right_b": r"$\dfrac{3(2x-1)}{3}=2x-1$",
            "check_title": r"Check these for $\dfrac{6x-3}{3}$",
            "lines": [
                r"(1) Cancel $-3$ → $6x-1$",
                r"(2) Split → $2x-1$",
                r"(3) Factor → $2x-1$",
                r"(4) Answer is $6x$",
            ],
        },
    },
    {
        "id": 10,
        "title": "Master card — factor vs illegal cancel",
        "level": "Class 8+",
        "hook": r"$\dfrac{2x+2}{x+1}$",
        "hook_plain": "(2x+2)/(x+1)",
        "wrong_cancel": "Piece-cancel the +2, or cancel x terms carelessly.",
        "why": (
            "Factor numerator: 2(x+1)/(x+1) = 2 (for x ≠ −1). "
            "You may cancel the common factor (x+1), not random pieces of the sum. "
            "Cancelling x terms 'to get 2' is illegal method (fails at x=0)."
        ),
        "steps": [
            (False, "Piece-cancel → 2x/(x+1)"),
            (True, "Factor: 2(x+1)/(x+1) = 2"),
            (True, "For x=3: left = 8/4 = 2, matches"),
            (False, "Cancel x top/bottom (illegal method)"),
        ],
        "options": [
            "Only step (1) is correct",
            "Steps (2) and (3) are correct; (1) is wrong; (4) method is illegal",
            "All four steps use legal methods",
            "Only step (4) is a safe method",
        ],
        "correct_index": 1,
        "answer_value": "2",
        "difficulty": "Hard",
        "figure": {
            "wrong": r"Illegal piece-cancel on $\dfrac{2x+2}{x+1}$",
            "right_a": r"$\dfrac{2(x+1)}{x+1}=2$   ($x\neq-1$)",
            "right_b": r"Check $x=3$: $8/4=2$",
            "check_title": "Which statements are reliable?",
            "lines": [
                r"(1) Piece-cancel → $\dfrac{2x}{x+1}$",
                r"(2) Factor → $2$",
                r"(3) $x=3$ check → $2$",
                r"(4) Cancel $x$ terms (illegal method)",
            ],
        },
    },
]


def draw_card_figure(card: dict, path: Path) -> None:
    fig = card["figure"]
    fig_plt, ax = plt.subplots(figsize=(8.2, 7.2), dpi=160)
    ax.set_xlim(0, 100)
    ax.set_ylim(0, 100)
    ax.axis("off")
    fig_plt.patch.set_facecolor("#ffffff")

    ax.text(
        50, 96, f"Card {card['id']}: {card['title']}",
        ha="center", va="top", fontsize=13, fontweight="bold", color="#0f172a",
    )
    ax.text(
        50, 92, "Fraction simplification — wrong / right steps",
        ha="center", va="top", fontsize=9, color="#475569",
    )

    ax.add_patch(FancyBboxPatch(
        (4, 72), 92, 16, boxstyle="round,pad=0.4,rounding_size=1.5",
        linewidth=1.5, edgecolor="#b91c1c", facecolor="#fef2f2",
    ))
    ax.text(8, 85, "WRONG cancel (do not do this)", fontsize=9, fontweight="bold", color="#b91c1c")
    ax.text(8, 78, fig["wrong"], fontsize=12, color="#7f1d1d")
    ax.text(88, 78, "X", fontsize=22, fontweight="bold", color="#dc2626", ha="center", va="center")

    ax.add_patch(FancyBboxPatch(
        (4, 50), 92, 19, boxstyle="round,pad=0.4,rounding_size=1.5",
        linewidth=1.5, edgecolor="#15803d", facecolor="#f0fdf4",
    ))
    ax.text(8, 66, "RIGHT ways", fontsize=9, fontweight="bold", color="#15803d")
    ax.text(8, 59, fig["right_a"], fontsize=11, color="#14532d")
    ax.text(8, 53, fig["right_b"], fontsize=11, color="#14532d")

    ax.add_patch(FancyBboxPatch(
        (4, 6), 92, 40, boxstyle="round,pad=0.4,rounding_size=1.5",
        linewidth=1.2, edgecolor="#1e3a8a", facecolor="#eff6ff",
    ))
    ax.text(8, 42, fig["check_title"], fontsize=10, fontweight="bold", color="#1e3a8a")
    y = 36
    for line in fig["lines"]:
        ax.text(8, y, line, fontsize=11, color="#0f172a")
        y -= 7

    ax.text(
        50, 2.5,
        "Rule: cancel only a common FACTOR of the whole numerator and denominator.",
        ha="center", fontsize=8, color="#334155", style="italic",
    )

    path.parent.mkdir(parents=True, exist_ok=True)
    fig_plt.savefig(path, bbox_inches="tight", facecolor="white", pad_inches=0.25)
    plt.close(fig_plt)


def build_questions() -> list[dict]:
    rows = []
    for card in CARDS:
        true_ids = [i + 1 for i, (ok, _) in enumerate(card["steps"]) if ok]
        false_ids = [i + 1 for i, (ok, _) in enumerate(card["steps"]) if not ok]
        rows.append({
            "type": "mcq",
            "topic": "Fraction simplification (cancel traps)",
            "question": (
                f"Card {card['id']} — {card['title']}. "
                f"Study the figure for {card['hook_plain']}. "
                "Which statement about steps (1)–(4) is correct?"
            ),
            "needs_diagram": True,
            "diagram_file": f"q{card['id']}.png",
            "figure_spec": (
                f"Teaching card: illegal cancel for {card['hook_plain']}, "
                f"two correct methods, four check lines. Wrong: {card['wrong_cancel']}"
            ),
            "options": card["options"],
            "correct_index": card["correct_index"],
            "method_hint": (
                "Cancel only a common factor of the WHOLE numerator and denominator. "
                "If the numerator is a sum/difference, split the fraction or factor first."
            ),
            "explanation": (
                f"{card['why']} "
                f"True steps: {true_ids}. False traps: {false_ids}. "
                f"Simplified result: {card['answer_value']}. "
                f"Final answer = option {chr(65 + card['correct_index'])}"
            ),
            "difficulty": card["difficulty"],
        })
    return rows


def write_teacher_guide() -> None:
    lines = [
        "# Fraction Simplification Cards (Class 6+)",
        "",
        "Common misunderstanding: students cancel a number/variable that is only **part of a sum or difference**.",
        "",
        "**Golden rule:** You may cancel only a common **factor** of the *whole* numerator and the denominator.",
        "",
        "If the numerator is a sum/difference, use either:",
        "",
        "1. **Split** — `(a+b)/c = a/c + b/c` (when `c ≠ 0`)",
        "2. **Factor then cancel** — only after a common factor appears on the whole top",
        "",
        "Use these cards instead of rote times-tables drills when you want students to *see* the cancel trap.",
        "",
        "Import: `fraction-simplification-cards.zip` (MCQ + figures) via admin question zip import.",
        "",
        "---",
        "",
    ]
    for card in CARDS:
        lines.append(f"## Card {card['id']}. {card['title']} ({card['level']})")
        lines.append("")
        lines.append(f"- **Hook:** `{card['hook_plain']}`")
        lines.append(f"- **Wrong cancel students try:** {card['wrong_cancel']}")
        lines.append(f"- **Why / correct idea:** {card['why']}")
        lines.append(f"- **Target result:** `{card['answer_value']}`")
        lines.append("- **Check steps:**")
        for i, (ok, text) in enumerate(card["steps"], start=1):
            mark = "TRUE" if ok else "FALSE"
            lines.append(f"  - ({i}) [{mark}] {text}")
        lines.append(
            f"- **MCQ correct option:** "
            f"`{card['options'][card['correct_index']]}`"
        )
        lines.append("")
    (ROOT / "TEACHER_GUIDE.md").write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    DIAG.mkdir(parents=True, exist_ok=True)
    for card in CARDS:
        draw_card_figure(card, DIAG / f"q{card['id']}.png")
        print(f"wrote q{card['id']}.png")

    rows = build_questions()
    OUT_JSON.write_text(
        json.dumps({"questions": rows}, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
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
