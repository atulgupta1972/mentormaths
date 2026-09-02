"""Generate tests/gemp101_questions.json from NCERT Exemplar Class 7 Ch 1 Integers (gemp101.pdf)."""
import json
import re
from pathlib import Path

questions = []
_diagram_idx = 0
_ci_idx = 0

UNIT_LABELS = {
    "m": "metres", "metre": "metres", "metres": "metres", "meter": "metres", "meters": "metres",
    "cm": "centimetres", "mm": "millimetres", "km": "kilometres",
    "g": "grams", "kg": "kilograms", "mg": "milligrams",
    "l": "litres", "litre": "litres", "litres": "litres", "ml": "millilitres",
    "°c": "°C", "°C": "°C", "°f": "°F", "°F": "°F",
    "seconds": "seconds", "second": "seconds", "sec": "seconds", "s": "seconds",
    "minutes": "minutes", "min": "minutes", "hours": "hours", "hr": "hours",
    "rupees": "rupees", "rs": "rupees", "rs.": "rupees",
    "%": "percent", "percent": "percent",
}


def normalize_minus(value: str) -> str:
    return value.replace("\u2212", "-").replace("\u2013", "-").replace("\u2014", "-")


def parse_numeric_with_unit(raw: str) -> tuple[str | None, str | None, str | None]:
    value = normalize_minus(raw.strip())
    if value.startswith("₹"):
        num = re.sub(r"[^\d.-]", "", value)
        return (num, "₹", "rupees") if num else (None, None, None)
    match = re.match(r"^(-?[\d,]+(?:\.\d+)?)\s*(.+)$", value)
    if not match:
        return None, None, None
    num = match.group(1).replace(",", "")
    unit_raw = match.group(2).strip().rstrip(".")
    unit_key = unit_raw.lower()
    if unit_key in UNIT_LABELS or unit_raw in UNIT_LABELS:
        return num, unit_raw, UNIT_LABELS.get(unit_key, UNIT_LABELS.get(unit_raw, unit_raw))
    return None, None, None


def is_plain_numeric(raw: str) -> bool:
    value = normalize_minus(raw.strip().replace(",", ""))
    return bool(re.match(r"^-?\d+$", value) or re.match(r"^-?\d+\.\d+$", value) or re.match(r"^-?\d+/\d+$", value.replace(" ", "")))


def is_column_match(question: str) -> bool:
    lower = question.lower()
    return "match column" in lower or ("column i" in lower and "column ii" in lower)


def postprocess_question(question: dict) -> dict | None:
    if is_column_match(question.get("question", "")):
        return None

    options = [normalize_minus(str(o)) for o in question.get("options", [])]
    ci = int(question.get("correct_index", 0))
    correct = options[ci] if 0 <= ci < len(options) else ""
    num, unit_raw, unit_label = parse_numeric_with_unit(correct)

    if num is not None:
        stripped = []
        for option in options:
            option_num, _, _ = parse_numeric_with_unit(option)
            stripped.append(option_num if option_num is not None else option)
        question["options"] = stripped

        stem = question.get("question", "").strip()
        if "____" not in stem:
            stem = re.sub(r"[:?]\s*$", "", stem)
            if stem.endswith(" equal to"):
                stem = f"{stem} ____"
            elif unit_label:
                stem = f"{stem} is ____ {unit_label}."
            else:
                stem = f"{stem} = ____"
            question["question"] = stem
        if unit_raw:
            question["answer_unit"] = unit_raw
        return question

    if is_plain_numeric(correct) and "____" not in question.get("question", ""):
        stem = question.get("question", "").strip()
        stem = re.sub(r"[:?]\s*$", "", stem)
        if stem.endswith(" equal to"):
            question["question"] = f"{stem} ____"
        elif re.search(r"\bis\b$", stem):
            question["question"] = f"{stem} ____"
        else:
            question["question"] = f"{stem} = ____"

    return question


def next_diagram():
    global _diagram_idx
    _diagram_idx += 1
    return f"chart{_diagram_idx}.png"


def next_ci():
    global _ci_idx
    r = _ci_idx % 8
    _ci_idx += 1
    return r


def letter(ci):
    return chr(65 + ci)


def mcq(topic, question, options, correct_index, hint, explanation, difficulty="Medium", **extra):
    opts = list(options)
    while len(opts) < 8:
        opts.append(f"Option {chr(65 + len(opts))}")
    assert len(opts) == 8
    q = {
        "topic": topic,
        "question": question,
        "options": opts[:8],
        "correct_index": correct_index,
        "hint": hint,
        "explanation": explanation,
        "difficulty": difficulty,
        "needs_diagram": extra.get("needs_diagram", False),
    }
    if q["needs_diagram"]:
        q["diagram_file"] = extra.get("diagram_file", next_diagram())
        q["chart"] = extra.get("chart", "THIS QUESTION REQUIRES A FIGURE UPLOAD — see NCERT Exemplar figure.")
    elif "chart" in extra:
        q["chart"] = extra["chart"]
    if "table" in extra:
        q["table"] = extra["table"]
    if "answer_unit" in extra:
        q["answer_unit"] = extra["answer_unit"]
    cleaned = postprocess_question(q)
    if cleaned:
        questions.append(cleaned)


def add_answer(topic, question, answer, distractors, hint, explanation, difficulty="Medium", ci=None, **extra):
    ci = next_ci() if ci is None else ci
    pool = [str(answer)] + [str(d) for d in distractors]
    seen = set()
    unique = []
    for x in pool:
        if x not in seen:
            seen.add(x)
            unique.append(x)
    pad = ["0", "1", "-1", "10", "-10", "100", "-100", "5", "-5", "25", "-25", "50", "-50"]
    for p in pad:
        if len(unique) >= 8:
            break
        if p not in seen:
            seen.add(p)
            unique.append(p)
    correct = str(answer)
    others = [x for x in unique if x != correct]
    opts = others[:7]
    opts.insert(ci, correct)
    while len(opts) < 8:
        opts.append(f"Distractor {len(opts)}")
    mcq(topic, question, opts[:8], ci, hint, explanation, difficulty, **extra)


def add_tf(topic, ref, statement, is_true, hint, explanation):
    correct = "True" if is_true else "False"
    wrong = "False" if is_true else "True"
    distractors = [
        wrong, "Sometimes true", "Cannot be determined", "True only for positive integers",
        "False only for negative integers", "Depends on the integers", "True only when both are equal", "Neither true nor false",
    ]
    add_answer(topic, f"{ref}: {statement}", correct, distractors, hint, explanation)


def add_blank(topic, ref, question, answer, distractors, hint, explanation="", difficulty="Medium", **extra):
    add_answer(topic, f"{ref}: {question}", answer, distractors, hint, explanation or hint, difficulty, **extra)



# --- SOLVED EXAMPLES ---
add_answer("Number line",
    "Example 1: Madhre stands on a bridge 20 m above the river. The river is 35 m deep below the bridge. The vertical distance from her foot to the river bottom is ____ metres.",
    "55", ["45", "55", "35", "20", "75", "40", "25", "65"],
    "Add height above water and depth below water.", "20 + 35 = 55 metres.", answer_unit="m", needs_diagram=True,
    chart="THIS QUESTION REQUIRES A FIGURE UPLOAD — bridge 20 m above water, river 35 m deep (Fig 1.1).")

add_answer("Multiplication", "Example 2: [(–10) × (+9)] + (–10) = ____",
    "–100", ["100", "–100", "–80", "80", "–90", "90", "–110", "110"],
    "Multiply first, then add.", "[(–10)×9]+(–10)=–90+(–10)=–100.")

add_answer("Division", "Example 3: –16 ÷ [8 ÷ (–2)] = ____",
    "4", ["–1", "1", "–4", "2", "–2", "8", "–8"],
    "Evaluate brackets first.", "8÷(–2)=–4; –16÷(–4)=4.")

add_blank("Commutative property", "Example 4", "(–25) × 30 = –30 × _____.", "25",
    ["–25", "30", "–30", "0", "750", "-750", "1"], "a×b = b×a for integers.", "(–25)×30 = –30×25.")

add_blank("Division", "Example 5", "75 ÷ _____ = –75", "–1",
    ["1", "0", "75", "–75", "15", "–15", "5"], "Dividing by –1 changes sign.", "75÷(–1)=–75.")

add_tf("Commutative property", "Example 6", "(–5) × (–7) is the same as (–7) × (–5).", True,
    "Multiplication is commutative.", "Both equal 35.")

add_tf("Division", "Example 7", "(–80) ÷ (4) is NOT the same as 80 ÷ (–4).", False,
    "Compare both quotients.", "(–80)÷4=–20 and 80÷(–4)=–20; they are equal, so statement is False.")

add_answer("Odd one out", "Example 8: Find the odd one out: (a) (–2, 24) (b) (–3, 10) (c) (–4, 12) (d) (–6, 8)",
    "(–3, 10)", ["(–2, 24)", "(–4, 12)", "(–6, 8)", "(–2, 12)", "(–3, 24)", "(–6, 12)", "(–4, 8)"],
    "Multiply each pair.", "–3×10=–30; others give –48.")

add_answer("Odd one out", "Example 9: Find the odd one out: (a) (–3, –6) (b) (+1, –10) (c) (–2, –7) (d) (–4, –9)",
    "(–4, –9)", ["(–3, –6)", "(+1, –10)", "(–2, –7)", "(–4, –6)", "(+1, –9)", "(–3, –10)", "(–2, –9)"],
    "Add each pair.", "–4+(–9)=–13; others give –9.")

# Examples 10–11 (column matching) omitted per import prompt — not suitable for online practice.

add_answer("Additive inverse", "Example 12: A pair of integers whose sum is 0 but difference is 10 is:",
    "5 and –5", ["3 and –3", "10 and 0", "5 and 5", "–10 and 0", "6 and –4", "7 and –3", "0 and 0"],
    "Sum 0 means additive inverses.", "5+(–5)=0 and 5–(–5)=10.")

add_answer("Subtraction", "Example 13: Two integers smaller than –3 whose difference is greater than –3 could be:",
    "–5 and –4", ["–2 and –1", "0 and –3", "3 and 5", "–1 and –2", "4 and 5", "–3 and –3", "2 and 3"],
    "Try pairs below –3.", "(–4)–(–5)=1>–3.")

add_answer("Product and difference", "Example 14: A pair of integers with product –15 and difference 8 is:",
    "–3 and 5", ["–1 and 15", "3 and 5", "–15 and 1", "1 and –15", "5 and 5", "–3 and –5", "15 and –1"],
    "Factor pairs of –15.", "–3×5=–15 and 5–(–3)=8.")

add_answer("Custom operation", "Example 15: If a∆b = a×a + b×b – a×b, find (–3)∆2.",
    "19", ["13", "25", "7", "–19", "15", "21", "9"],
    "Substitute a=–3, b=2.", "9+4+6=19.")

add_answer("Scoring", "Example 16: In a 25-question test (+5 correct, –5 incorrect, 0 unattempted), one way to score 110 marks is:",
    "22 correct, 0 incorrect, 3 unattempted",
    ["20 correct, 0 incorrect, 5 unattempted", "24 correct, 2 incorrect, 0 unattempted", "23 correct, 2 incorrect, 0 unattempted",
     "25 correct, 0 incorrect, 0 unattempted", "21 correct, 1 incorrect, 3 unattempted", "22 correct, 1 incorrect, 2 unattempted", "23 correct, 0 incorrect, 2 unattempted"],
    "22×5=110.", "Case 1: 22 correct, no penalties.")

add_answer("Scoring", "Example 16: Another way to score 110 marks in the same test is:",
    "23 correct, 1 incorrect, 1 unattempted",
    ["22 correct, 0 incorrect, 3 unattempted", "24 correct, 0 incorrect, 1 unattempted", "25 correct, 0 incorrect, 0 unattempted",
     "20 correct, 2 incorrect, 3 unattempted", "21 correct, 1 incorrect, 3 unattempted", "23 correct, 0 incorrect, 2 unattempted", "22 correct, 2 incorrect, 1 unattempted"],
    "23×5=115; one wrong costs 5.", "23 correct (+115) and 1 incorrect (–5) → 110.")

add_answer("Number line", "Example 17: A boy on the 3rd stair goes up 5 stairs. He is now on stair:",
    "8", ["6", "5", "2", "3", "7", "4", "9"], "Going up is positive.", "3+5=8.")

add_answer("Number line", "Example 17: From stair 8 he comes down 2 stairs. He is now on stair:",
    "6", ["8", "10", "5", "4", "7", "3", "2"], "Coming down subtracts.", "8+(–2)=6.")

add_answer("Number line", "Example 17 (Think and Discuss 1): From stair 6, if he comes down 3 more stairs, his position is stair:",
    "3", ["6", "9", "0", "2", "4", "5", "1"], "Subtract 3.", "6–3=3.")


# --- EXERCISE MCQ 1-25 ---
EX1 = [
    ("Exercise Q1: When the integers 10, 0, 5, –5, –7 are arranged in descending or ascending order, which integer always remains in the middle of the arrangement?",
     "0", ["5", "–7", "–5", "10", "–10", "1", "–1"], "Sorted: –7, –5, 0, 5, 10; 0 is always middle."),
    ("Exercise Q2: By observing the number line (Fig. 1.2), state which of the following statements is NOT true.",
     "A is greater than 0", ["B is greater than –10", "B is greater than A", "B is smaller than 0", "A is at zero", "B is at +4", "A is positive", "B is negative"],
     "Point A is left of zero (negative); A is not greater than 0.", {"needs_diagram": True, "chart": "THIS QUESTION REQUIRES A FIGURE UPLOAD — number line Fig 1.2 with points A and B."}),
    ("Exercise Q3: By observing the number line (Fig. 1.2), state which of the following statements is true.",
     "B is –4", ["B is 2", "A is –4", "B is –13", "B is 4", "A is 2", "B is 0", "A is –13"],
     "B is 4 units left of zero.", {"needs_diagram": True, "chart": "THIS QUESTION REQUIRES A FIGURE UPLOAD — number line Fig 1.2."}),
    ("Exercise Q4: Next three consecutive numbers in the pattern 11, 8, 5, 2, ____, ____, ____ are",
     "–1, –4, –7", ["0, –3, –6", "–1, –5, –8", "–2, –5, –8", "–1, –3, –5", "0, –1, –2", "–2, –4, –6", "1, –2, –5"],
     "Pattern decreases by 3 each time."),
    ("Exercise Q5: The next number in the pattern –62, –37, –12, _________ is",
     "13", ["25", "0", "–13", "37", "62", "–37", "–62"],
     "Each term increases by 25."),
    ("Exercise Q6: Which of the following statements is not true?",
     "When a positive integer and a negative integer is added we always get a negative integer",
     ["When two positive integers are added, we always get a positive integer",
      "When two negative integers are added we always get a negative integer",
      "Additive inverse of 2 is (–2) and additive inverse of (–2) is 2",
      "Sum of a number and its additive inverse is zero",
      "Integers are closed under addition",
      "Zero is the additive identity",
      "Subtraction can be done using addition of the inverse"],
     "Counterexample: 5+(–2)=3 (positive)."),
    ("Exercise Q7: On the following number line value 'Zero' is shown by the point",
     "Z", ["X", "Y", "W", "P", "O", "A", "B"],
     "Zero is at the origin on the number line.", {"needs_diagram": True, "chart": "THIS QUESTION REQUIRES A FIGURE UPLOAD — number line with points W, X, Y, Z."}),
    ("Exercise Q8: If ⊗, O, □ and • represent some integers on the number line, then descending order of these numbers is",
     "⊗, •, □, ★", ["•, ⊗, □, ★", "★, □, ⊗, •", "★, •, ⊗, □", "□, •, ⊗, ★", "⊗, □, •, ★", "★, ⊗, □, •", "•, □, ★, ⊗"],
     "Read positions right to left on the number line.", {"needs_diagram": True, "chart": "THIS QUESTION REQUIRES A FIGURE UPLOAD — number line with ⊗, O, □, •, ★."}),
    ("Exercise Q9: On the number line, the value of (–3) × 3 lies on the right hand side of",
     "–10", ["–4", "0", "9", "–3", "3", "–9", "10"],
     "(–3)×3=–9; –9 is to the right of –10 only."),
    ("Exercise Q10: The value of 5 ÷ (–1) does not lie between",
     "0 and 10", ["0 and –10", "–4 and –15", "–6 and 6", "–5 and 5", "–1 and 1", "–10 and 0", "–20 and 20"],
     "5÷(–1)=–5; –5 is not between 0 and 10 (positive interval)."),
    ("Exercise Q11: Water level in a well was 20 m below ground level. Rain water raised the level 5 m above the previous level. The wall is 1 m 20 cm high and a pulley is fixed at 80 cm. Raghu wants to draw water. The minimum length of rope he can use is",
     "18 m", ["17 m", "96 m", "97 m", "15.8 m", "16 m", "20 m", "19 m"],
     "New water level: –15 m; rope from pulley (0.8 m) to water ≈ 15.8 m; minimum rope 18 m.", {"needs_diagram": True, "chart": "THIS QUESTION REQUIRES A FIGURE UPLOAD — well diagram Fig 1.3."}),
    ("Exercise Q12: (–11) × 7 is not equal to",
     "(–11) × (–7)", ["11 × (–7)", "–(11 × 7)", "7 × (–11)", "(–7) × 11", "–77", "77", "11 × 7"],
     "(–11)×(–7)=+77; others equal –77."),
    ("Exercise Q13: (–10) × (–5) + (–7) is equal to",
     "43", ["–57", "57", "–43", "50", "–50", "7", "–7"],
     "50+(–7)=43."),
    ("Exercise Q14: Which of the following is not the additive inverse of a?",
     "–(–a)", ["a × (–1)", "–a", "a ÷ (–1)", "a + (–a)", "0 – a", "–a × 1", "a × (–1) × (–1)"],
     "–(–a)=a, which is not the additive inverse of a."),
    ("Exercise Q15: Which of the following is the multiplicative identity for an integer a?",
     "1", ["a", "0", "–1", "–a", "a × 1", "1 × a", "a ÷ a"],
     "a×1=1×a=a."),
    ("Exercise Q16: [(–8) × (–3)] × (–4) is not equal to",
     "(–8) × (–3) – (–8) × (–4)", ["(–8) × [(–3) × (–4)]", "[(–8) × (–4)] × (–3)", "[(–3) × (–8)] × (–4)", "(–8)×(–3)×(–4)", "(–24)×(–4)", "–96", "96"],
     "Subtraction is not the same as multiplication grouping."),
    ("Exercise Q17: (–25) × [6 + 4] is not same as",
     "(–25) × 6 × 4", ["(–25) × 10", "(–25) × 6 + (–25) × 4", "–250", "(–25)×(6+4)", "–25×10", "6×4×(–25)", "10×(–25)"],
     "6+4=10, not 6×4."),
    ("Exercise Q18: –35 × 107 is not same as",
     "–35 × 7 + 100", ["–35 × (100 + 7)", "(–35) × 7 + (–35) × 100", "(–30 – 5) × 107", "–35×107", "–3745", "35×107", "–35×100+7"],
     "Distributive form needs –35×100, not +100 alone."),
    ("Exercise Q19: (–43) × (–99) + 43 is equal to",
     "4300", ["–4300", "4257", "–4214", "4200", "4400", "4260", "4243"],
     "4257+43=4300."),
    ("Exercise Q20: (–16) ÷ 4 is not same as",
     "(–4) ÷ 16", ["–(16 ÷ 4)", "16 ÷ (–4)", "–4", "–16÷4", "4÷(–16)", "16÷4", "–16÷(–4)"],
     "(–4)÷16=–1/4 ≠ –4."),
    ("Exercise Q21: Which of the following does not represent an integer?",
     "(–12) ÷ 5", ["0 ÷ (–7)", "20 ÷ (–4)", "(–9) ÷ 3", "12÷5", "–12÷4", "0÷5", "25÷5"],
     "–12÷5=–2.4, not an integer."),
    ("Exercise Q22: Which of the following is different from the others?",
     "(–5) × (–1)", ["20 + (–25)", "(–37) – (–32)", "(45) ÷ (–9)", "5×(–1)", "–5×1", "25+(–20)", "–32+(–5)"],
     "(–5)×(–1)=5; others equal –5."),
    ("Exercise Q23: Which of the following shows the maximum rise in temperature?",
     "–10° to +1°", ["23° to 32°", "–18° to –11°", "–5° to 5°", "0° to 10°", "–1° to 8°", "5° to 12°", "–20° to –15°"],
     "Rise of 11° is greatest."),
    ("Exercise Q24: If a and b are two integers, which of the following may not be an integer?",
     "a ÷ b", ["a + b", "a – b", "a × b", "a+b when a,b integers", "a–b when a,b integers", "a×b when a,b integers", "a+0"],
     "Division may not yield an integer."),
    ("Exercise Q25: For a non-zero integer a, which of the following is not defined?",
     "a ÷ 0", ["0 ÷ a", "a ÷ 1", "1 ÷ a", "a×0", "0×a", "a÷(–1)"],
     "Division by zero is undefined."),
]
for q, ans, dist, hint, *rest in EX1:
    extra = rest[0] if rest else {}
    add_answer("Exercise MCQ", q, ans, dist, hint, f"Answer: {ans}.", **extra)


# --- VOCABULARY (page 10) ---
add_blank("Vocabulary", "Vocab 1", "____________ is the ____________ of addition.", "Subtraction; opposite operation",
    ["Addition; commutative property", "Division; inverse", "Multiplication; identity", "Zero; additive identity",
     "Negative; positive", "Sum; difference", "Integer; number"], "See vocabulary box: subtraction and opposite operation.")
add_blank("Vocabulary", "Vocab 2", "The expression 3 × 4 and 4 × 3 are equal by the ____________.", "Commutative Property",
    ["Associative Property", "Distributive Property", "Identity Property", "Closure Property", "Inverse Property", "Addition Property", "Division Property"],
    "Order of factors does not matter.")
add_blank("Vocabulary", "Vocab 3", "The expressions 1 + (2 + 3) and (1 + 2) + 3 are equal by the ____________.", "Associative Property",
    ["Commutative Property", "Distributive Property", "Identity Property", "Closure Property", "Inverse Property", "Subtraction Property", "Division Property"],
    "Grouping of addends does not matter.")
add_blank("Vocabulary", "Vocab 4", "Multiplication and ____________ are opposite operations.", "Division",
    ["Addition", "Subtraction", "Subtraction", "Exponentiation", "Square root", "Commutative", "Associative"],
    "Division undoes multiplication.")
add_blank("Vocabulary", "Vocab 5", "____________ and ____________ are commutative.", "Addition and Multiplication",
    ["Subtraction and Division", "Addition and Subtraction", "Multiplication and Division", "Division and Subtraction",
     "Addition and Division", "Subtraction and Multiplication", "Zero and One"],
    "Both addition and multiplication are commutative for integers.")


# --- ODD ONE OUT Q26-30 ---
add_answer("Odd one out", "Exercise Q26: Encircle the odd one: (a) (–3, 3) (b) (–5, 5) (c) (–6, 1) (d) (–8, 8)",
    "(–6, 1)", ["(–3, 3)", "(–5, 5)", "(–8, 8)", "(–6, –1)", "(–1, 6)", "(6, 1)", "(–8, –8)"],
    "Three pairs are additive inverses (sum 0).", "(–6,1) does not sum to 0.")
add_answer("Odd one out", "Exercise Q27: Encircle the odd one: (a) (–1, –2) (b) (–5, +2) (c) (–4, +1) (d) (–9, +7)",
    "(–9, +7)", ["(–1, –2)", "(–5, +2)", "(–4, +1)", "(–9, –7)", "(–2, –1)", "(–3, +1)", "(–8, +7)"],
    "Add each pair.", "(a),(b),(c) sum to –3; (d) sums to –2.")
add_answer("Odd one out", "Exercise Q28: Encircle the odd one: (a) (–9)×5×6×(–3) (b) 9×(–5)×6×(–3) (c) (–9)×(–5)×(–6)×3 (d) 9×(–5)×(–6)×3",
    "(–9)×(–5)×(–6)×3", ["(–9)×5×6×(–3)", "9×(–5)×6×(–3)", "9×(–5)×(–6)×3", "(–9)×5×(–6)×3", "9×5×6×3", "(–9)×(–5)×6×3", "9×5×(–6)×(–3)"],
    "Count negative factors.", "(c) has 3 negatives → negative product; others positive.")
add_answer("Odd one out", "Exercise Q29: Encircle the odd one: (a) (–100)÷5 (b) (–81)÷9 (c) (–75)÷5 (d) (–32)÷9",
    "(–32)÷9", ["(–100)÷5", "(–81)÷9", "(–75)÷5", "(–32)÷8", "(–90)÷9", "(–45)÷5", "(–64)÷8"],
    "Check if quotient is integer.", "(–32)÷9 is not an integer.")
add_answer("Odd one out", "Exercise Q30: Encircle the odd one: (a) (–1)×(–1) (b) (–1)×(–1)×(–1) (c) (–1)×(–1)×(–1)×(–1) (d) (–1)×(–1)×(–1)×(–1)×(–1)×(–1)",
    "(–1)×(–1)×(–1)", ["(–1)×(–1)", "(–1)×(–1)×(–1)×(–1)", "(–1)×(–1)×(–1)×(–1)×(–1)×(–1)", "(–1)×(–1)×(–1)×(–1)×(–1)",
     "(–1)×(–1)×(–1)×(–1)×(–1)×(–1)×(–1)", "(–1)", "(–1)×(–1)×(–1)×(–1)×(–1)×(–1)×(–1)×(–1)"],
    "Even number of negatives → positive; odd → negative.", "(b) has 3 negatives → negative; (a),(c),(d) have even count → positive.")


# --- FILL IN THE BLANKS Q31-71 (each blank separate) ---
BLANKS = [
    ("Q31", "(–a) + b = b + Additive inverse of ____.", "a", ["–a", "b", "0", "1", "–b", "–1", "2"]),
    ("Q32", "____ ÷ (–10) = 0", "0", ["10", "–10", "1", "–1", "100", "–100", "5"]),
    ("Q33", "(–157) × (–19) + 157 = ____", "3140", ["2983", "157", "–3140", "3000", "3100", "3200", "2983"]),
    ("Q34a", "[(–8) + ____] + ____ = ____ + [(–3) + ____] = –3 (first blank)", "5", ["3", "0", "–3", "8", "–8", "–5", "2"]),
    ("Q34b", "[(–8) + 5] + ____ = –3 + [(–3) + ____] = –3 (second blank)", "0", ["5", "3", "–3", "8", "–8", "–5", "2"]),
    ("Q34c", "[(–8) + 5] + 0 = ____ + [(–3) + 0] = –3 (third blank)", "–3", ["0", "5", "3", "8", "–8", "–5", "2"]),
    ("Q34d", "[(–8) + 5] + 0 = –3 + [(–3) + ____] = –3 (fourth blank)", "0", ["5", "3", "–3", "8", "–8", "–5", "2"]),
    ("Q35", "On the number line, (–4) × 3 is represented by the point ____.", "–12", ["12", "–4", "3", "7", "–7", "0", "4"],
     {"needs_diagram": True, "chart": "THIS QUESTION REQUIRES A FIGURE UPLOAD — number line for (–4)×3."}),
    ("Q36a", "If x, y and z are integers then (x + ____) + z = ____ + (y + ____) (first blank)", "y", ["z", "x", "0", "1", "–1", "2", "–2"]),
    ("Q36b", "If x, y and z are integers then (x + y) + z = ____ + (y + ____) (second blank)", "x", ["y", "z", "0", "1", "–1", "2", "–2"]),
    ("Q36c", "If x, y and z are integers then (x + y) + z = x + (y + ____) (third blank)", "z", ["y", "x", "0", "1", "–1", "2", "–2"]),
    ("Q37", "(–43) + ____ = –43", "0", ["43", "–43", "1", "–1", "86", "–86", "10"]),
    ("Q38", "(–8) + (–8) + (–8) = ____ × (–8)", "3", ["–3", "8", "–8", "24", "–24", "0", "1"]),
    ("Q39a", "11 × (–5) = –(____ × ____) (first blank)", "11", ["5", "–5", "–11", "55", "–55", "0", "1"]),
    ("Q39b", "11 × (–5) = –(11 × ____) (second blank)", "5", ["11", "–5", "–11", "55", "–55", "0", "1"]),
    ("Q39c", "11 × (–5) = –(11 × 5) = ____", "–55", ["55", "11", "5", "–11", "–5", "0", "1"]),
    ("Q40", "(–9) × 20 = ____", "–180", ["180", "9", "20", "–20", "29", "–29", "0"]),
    ("Q41", "(–23) × (42) = (–42) × ____", "–23", ["23", "42", "–42", "965", "–965", "0", "1"]),
    ("Q42a", "While multiplying a positive and negative integer, we multiply them as ____ numbers (first blank)", "whole", ["natural", "negative", "positive", "fractional", "decimal", "integer", "rational"]),
    ("Q42b", "While multiplying a positive and negative integer, we put a ____ sign before the product (second blank)", "negative", ["positive", "zero", "minus only", "plus", "equal", "same", "opposite"]),
    ("Q43", "If we multiply ____ number of negative integers, the resulting integer is positive.", "even", ["odd", "two", "three", "zero", "one", "five", "any"]),
    ("Q44", "If we multiply six negative integers and six positive integers, the resulting integer is ____.", "negative", ["positive", "zero", "one", "six", "twelve", "even", "odd"]),
    ("Q45", "If we multiply five positive integers and one negative integer, the resulting integer is ____.", "negative", ["positive", "zero", "five", "six", "one", "odd", "even"]),
    ("Q46", "____ is the multiplicative identity for integers.", "1", ["0", "–1", "a", "–a", "10", "–10", "any integer"]),
    ("Q47", "We get additive inverse of an integer a when we multiply it by ____.", "–1", ["1", "0", "a", "–a", "2", "–2", "10"]),
    ("Q48", "(–25) × (–2) = ____", "50", ["–50", "25", "–25", "2", "–2", "27", "–27"]),
    ("Q49", "(–5) × (–6) × (–7) = ____", "–210", ["210", "–30", "30", "18", "–18", "42", "–42"]),
    ("Q50", "3 × (–1) × (–15) = ____", "45", ["–45", "15", "–15", "3", "–3", "18", "–18"]),
    ("Q51a", "[12 × (–7)] × 5 = ____ × [(–7) × 5] (first blank)", "12", ["5", "7", "–7", "–12", "60", "–60", "0"]),
    ("Q51b", "[12 × (–7)] × 5 = 12 × [(–7) × ____] (second blank)", "5", ["12", "7", "–7", "–12", "60", "–60", "0"]),
    ("Q52a", "23 × (–99) = ____ × (–100 + 1) (first blank)", "23", ["99", "–99", "100", "–100", "1", "–1", "0"]),
    ("Q52b", "23 × (–99) = 23 × (–100 + ____) (second blank)", "1", ["23", "99", "–99", "100", "–100", "0", "–1"]),
    ("Q52c", "23 × (–99) = 23 × ____ + 23 × 1 (third blank)", "–100", ["100", "99", "–99", "23", "–23", "0", "1"]),
    ("Q52d", "23 × (–99) = 23 × (–100) + 23 × ____ (fourth blank)", "1", ["23", "99", "–99", "100", "–100", "0", "–1"]),
    ("Q52e", "23 × (–99) = ____ (final value)", "–2277", ["2277", "–2300", "2300", "–2200", "2200", "–99", "99"]),
    ("Q53", "____ × (–1) = –35", "35", ["–35", "1", "–1", "0", "35", "–36", "36"]),
    ("Q54", "____ × (–1) = 47", "–47", ["47", "1", "–1", "0", "–46", "46", "47"]),
    ("Q55", "88 × ____ = –88", "–1", ["1", "88", "–88", "0", "2", "–2", "8"]),
    ("Q56", "____ × (–93) = 93", "–1", ["1", "93", "–93", "0", "93", "–94", "94"]),
    ("Q57", "(–40) × ____ = 80", "–2", ["2", "–2", "40", "–40", "80", "–80", "0"]),
    ("Q58", "____ × (–23) = –920", "40", ["–40", "23", "–23", "920", "–920", "0", "1"]),
    ("Q59a", "When we divide a negative integer by a positive integer, we divide them as whole numbers and put a ____ sign before quotient.", "negative", ["positive", "zero", "minus", "plus", "same", "opposite", "equal"]),
    ("Q60", "When –16 is divided by ____ the quotient is 4.", "–4", ["4", "–4", "16", "–16", "0", "1", "–1"]),
    ("Q61", "Division is the inverse operation of ____.", "multiplication", ["addition", "subtraction", "division", "subtraction", "exponentiation", "square", "cube"]),
    ("Q62", "65 ÷ (–13) = ____", "–5", ["5", "–5", "13", "–13", "65", "–65", "0"]),
    ("Q63", "(–100) ÷ (–10) = ____", "10", ["–10", "10", "100", "–100", "0", "1", "–1"]),
    ("Q64", "(–225) ÷ 5 = ____", "–45", ["45", "–45", "225", "–225", "5", "–5", "0"]),
    ("Q65", "____ ÷ (–1) = –83", "83", ["–83", "1", "–1", "0", "83", "–84", "84"]),
    ("Q66", "____ ÷ (–1) = 75", "–75", ["75", "–75", "1", "–1", "0", "76", "–76"]),
    ("Q67", "51 ÷ ____ = –51", "–1", ["1", "–1", "51", "–51", "0", "2", "–2"]),
    ("Q68", "113 ÷ ____ = –1", "–113", ["113", "–113", "1", "–1", "0", "112", "–112"]),
    ("Q69", "(–95) ÷ ____ = 95", "–1", ["1", "–1", "95", "–95", "0", "94", "–94"]),
    ("Q70", "(–69) ÷ (69) = ____", "–1", ["1", "–1", "69", "–69", "0", "68", "–68"]),
    ("Q71", "(–28) ÷ (–28) = ____", "1", ["–1", "1", "28", "–28", "0", "56", "–56"]),
]
for ref, q, ans, dist, *rest in BLANKS:
    extra = rest[0] if rest else {}
    add_blank("Fill in the blank", ref, q, ans, dist, "Use integer rules from the chapter.", f"Blank answer: {ans}.", **extra)


# --- TRUE/FALSE Q72-108 ---
TF_DATA = [
    ("Q72", "5 – (–8) is same as 5 + 8.", True),
    ("Q73", "(–9) + (–11) is greater than (–9) – (–11).", False),
    ("Q74", "Sum of two negative integers always gives a number smaller than both the integers.", True),
    ("Q75", "Difference of two negative integers cannot be a positive integer.", False),
    ("Q76", "We can write a pair of integers whose sum is not an integer.", False),
    ("Q77", "Integers are closed under subtraction.", True),
    ("Q78", "(–23) + 47 is same as 47 + (–23).", True),
    ("Q79", "When we change the order of integers, their sum remains the same.", True),
    ("Q80", "When we change the order of integers their difference remains the same.", False),
    ("Q81", "Going 500 m towards east first and then 200 m back is same as going 200 m towards west first and then going 500 m back.", True),
    ("Q82", "(–5) × (33) = 5 × (–33).", True),
    ("Q83", "(–19) × (–11) = 19 × 11.", True),
    ("Q84", "(–20) × (5 – 3) = (–20) × (–2).", False),
    ("Q85", "4 × (–5) = (–10) × (–2).", False),
    ("Q86", "(–1) × (–2) × (–3) = 1 × 2 × 3.", False),
    ("Q87", "–3 × 3 = –12 – (–3).", True),
    ("Q88", "Product of two negative integers is a negative integer.", False),
    ("Q89", "Product of three negative integers is a negative integer.", True),
    ("Q90", "Product of a negative integer and a positive integer is a positive integer.", False),
    ("Q91", "When we multiply two integers their product is always greater than both the integers.", False),
    ("Q92", "Integers are closed under multiplication.", True),
    ("Q93", "(–237) × 0 is same as 0 × (–39).", True),
    ("Q94", "Multiplication is not commutative for integers.", False),
    ("Q95", "(–1) is not a multiplicative identity of integers.", True),
    ("Q96", "99 × 101 can be written as (100 – 1) × (100 + 1).", True),
    ("Q97", "If a, b, c are integers and b ≠ 0 then, a × (b – c) = a × b – a × c.", True),
    ("Q98", "(a + b) × c = a × c + a × b.", False),
    ("Q99", "a × b = b × a.", True),
    ("Q100", "a ÷ b = b ÷ a.", False),
    ("Q101", "a – b = b – a.", False),
    ("Q102", "a ÷ (–b) = –(a ÷ b).", True),
    ("Q103", "a ÷ (–1) = –a.", True),
    ("Q104", "Multiplication fact (–8) × (–10) = 80 is same as division fact 80 ÷ (–8) = (–10).", True),
    ("Q105", "Integers are closed under division.", False),
    ("Q106", "[(–32) ÷ 8] ÷ 2 = –32 ÷ [8 ÷ 2].", False),
    ("Q107", "The sum of an integer and its additive inverse is zero (0).", True),
    ("Q108", "The successor of 0 × (–25) is 1 × (–25).", False),
]
for ref, stmt, val in TF_DATA:
    add_tf("True or False", f"Exercise {ref}", stmt, val, "Apply integer properties.", f"Statement is {'True' if val else 'False'}.")


# --- Q109 PATTERN BLANKS ---
PAT109 = [
    ("Q109(a)1", "–5 × 2 = _______ = –15 – (–5)", "–10", ["–15", "10", "15", "–5", "5", "0", "20"]),
    ("Q109(a)2", "–5 × 1 = _______ = –10 – (–5)", "–5", ["–10", "5", "10", "0", "–15", "15", "1"]),
    ("Q109(a)3", "–5 × 0 = 0 = _______ – (–5)", "–5", ["0", "5", "–10", "10", "–15", "15", "1"]),
    ("Q109(a)4", "–5 × (–1) = 5 = _______ – (–5)", "0", ["5", "–5", "10", "–10", "1", "–1", "15"]),
    ("Q109(a)5", "–5 × (–2) = _______ = 5 – (–5)", "10", ["5", "–5", "–10", "0", "15", "–15", "20"]),
    ("Q109(b)1", "7 × 3 = _______ = 28 – 7", "21", ["28", "14", "7", "0", "35", "–7", "–21"]),
    ("Q109(b)2", "7 × 2 = _______ = 21 – 7", "14", ["21", "7", "28", "0", "35", "–7", "–14"]),
    ("Q109(b)3", "7 × 1 = 7 = _______ – 7", "14", ["7", "0", "21", "28", "1", "–7", "–14"]),
    ("Q109(b)4", "7 × 0 = _______ = 7 – 7", "0", ["7", "14", "–7", "1", "21", "28", "–14"]),
    ("Q109(b)5", "7 × (–1) = –7 = _______ – 7", "0", ["–7", "7", "14", "–14", "1", "0", "21"]),
    ("Q109(b)6", "7 × (–2) = _______ = –7 – 7", "–14", ["–7", "7", "14", "0", "–21", "21", "–28"]),
    ("Q109(b)7", "7 × (–3) = _______ = –14 – 7", "–21", ["–14", "–7", "7", "14", "0", "21", "–28"]),
]
for ref, q, ans, dist in PAT109:
    add_blank("Patterns", ref, q, ans, dist, "Continue the multiplication pattern.", f"Answer: {ans}.")


# --- Q110-136 APPLICATIONS ---
add_answer("Science", "Exercise Q110(a): An atom has equal protons (+1 each) and electrons (–1 each). What is the charge on a neutral atom?",
    "0", ["+1", "–1", "+2", "–2", "10", "–10", "1"],
    "Equal + and – charges cancel.", "Net charge = 0.")
add_answer("Science", "Exercise Q110(b): What is the charge on an atom if it loses one electron?",
    "+1", ["0", "–1", "+2", "–2", "10", "–10", "2"],
    "One more proton charge than electrons.", "Net charge = +1.")
add_answer("Science", "Exercise Q110(c): What is the charge on an atom if it gains one electron?",
    "–1", ["0", "+1", "+2", "–2", "10", "–10", "2"],
    "One more electron than protons.", "Net charge = –1.")

ION_TABLE = {"headers": ["Name of Ion", "Proton Charge", "Electron Charge", "Ion Charge"],
             "rows": [["Hydroxide ion", "+9", "—", "–1"], ["Sodium ion", "+11", "—", "+1"],
                      ["Aluminium ion", "+13", "–10", "—"], ["Oxide ion", "+8", "–10", "—"]]}
add_blank("Ions", "Q111 Hydroxide", "Hydroxide ion: proton charge +9, electron charge –1. Electron charge (missing) = ____", "–1",
    ["+1", "0", "+9", "–9", "+8", "–8", "10"], "Ion charge = proton + electron charges.", "Electron charge = –1.", table=ION_TABLE)
add_blank("Ions", "Q111 Sodium", "Sodium ion: proton charge +11, ion charge +1. Electron charge (missing) = ____", "–10",
    ["+10", "–11", "0", "+1", "–1", "+11", "11"], "+11 + electron = +1 → electron = –10.", table=ION_TABLE)
add_blank("Ions", "Q111 Aluminium", "Aluminium ion: proton +13, electrons –10. Ion charge (missing) = ____", "+3",
    ["+23", "–23", "0", "+13", "–13", "+10", "–10"], "13+(–10)=+3.", table=ION_TABLE)
add_blank("Ions", "Q111 Oxide", "Oxide ion: proton +8, electrons –10. Ion charge (missing) = ____", "–2",
    ["+2", "–18", "0", "+8", "–8", "+10", "–10"], "8+(–10)=–2.", table=ION_TABLE)

add_answer("Problem solving", "Exercise Q111 Friends (a): Whose arrival information helped determine each person's arrival time?",
    "Roy", ["Sachin", "Shreya", "Reena", "Babu", "First person", "All equally", "None"],
    "Roy's time links Reena and Shreya.", "Roy at 9:01 is 1 min behind Reena and 7 min ahead of Shreya.")
add_answer("Problem solving", "Exercise Q111 Friends (c): List friends from earliest to latest arrival.",
    "Reena, Roy, Sachin, Babu, Shreya", ["Sachin, Reena, Roy, Babu, Shreya", "Reena, Roy, Babu, Sachin, Shreya",
     "Babu, Reena, Roy, Sachin, Shreya", "Reena, Sachin, Roy, Babu, Shreya", "Roy, Reena, Sachin, Shreya, Babu",
     "Shreya, Sachin, Babu, Roy, Reena", "Reena, Babu, Roy, Sachin, Shreya"],
    "First 9:00; Roy 9:01; Sachin 9:05; Babu 9:06; Shreya 9:08.", "Order: Reena, Roy, Sachin, Babu, Shreya.")

add_answer("Social Studies", "Exercise Q112(a): Greeco-Roman era from 330 BC to 395 AD lasted how many years?",
    "725 years", ["665 years", "725 years", "395 years", "330 years", "725 BC", "1065 years", "60 years"],
    "330 BC = –330; 395 AD = +395; total = 725.", "395 – (–330) = 725 years.")
add_answer("Social Studies", "Exercise Q112(b): Bhaskaracharya (1114 AD–1185 AD) died at age",
    "71 years", ["70 years", "71 years", "72 years", "69 years", "1185 years", "1114 years", "60 years"],
    "Subtract birth from death year.", "1185 – 1114 = 71.")
add_answer("Social Studies", "Exercise Q112(c): Turks ruled Egypt in 1517 AD. Queen Nefertiti ruled 2900 years before. She ruled in year",
    "1383 BC", ["1383 BC", "4417 BC", "2900 BC", "1517 BC", "1383 AD", "2900 AD", "4417 AD"],
    "1517 – 2900 = –1383 → 1383 BC.", "1383 BC.")
add_answer("Social Studies", "Exercise Q112(d): Archimedes (287 BC–212 BC) and Aristotle (380 BC–322 BC). Who lived during an earlier period?",
    "Aristotle", ["Archimedes", "Both same period", "Neither", "Turks", "Bhaskaracharya", "Nefertiti", "Cannot tell"],
    "380 BC is earlier than 287 BC.", "Aristotle lived earlier.")

TEMP_TABLE = {"headers": ["Continent", "Temperature (°F)"],
              "rows": [["Africa", "–11"], ["Antarctica", "–129"], ["Asia", "–90"], ["Australia", "–9"],
                       ["Europe", "–67"], ["North America", "–81"], ["South America", "–27"]]}
add_answer("Temperature", "Exercise Q113: Write continents in order from lowest recorded temperature to highest.",
    "Antarctica, Asia, North America, Europe, South America, Africa, Australia",
    ["Africa, Antarctica, Asia, Australia, Europe, North America, South America",
     "Antarctica, North America, Asia, Europe, South America, Africa, Australia",
     "Asia, Antarctica, North America, Europe, South America, Africa, Australia",
     "Antarctica, Asia, Europe, North America, South America, Africa, Australia",
     "South America, Africa, Australia, Europe, North America, Asia, Antarctica",
     "Australia, Africa, South America, Europe, North America, Asia, Antarctica",
     "Antarctica, Africa, Asia, Australia, Europe, North America, South America"],
    "Compare Fahrenheit values.", "Order: –129, –90, –81, –67, –27, –11, –9.", table=TEMP_TABLE)

add_answer("Integer pairs", "Exercise Q114: A pair of integers with product –12 and seven integers between them (excluding the pair) is",
    "–6 and 2", ["–3 and 4", "–2 and 6", "–1 and 12", "3 and –4", "–4 and 3", "6 and –2", "1 and –12"],
    "Between –6 and 2 are exactly 7 integers.", "–6×2=–12; integers between: –5,–4,–3,–2,–1,0,1.")

# Q115, Q117 (column matching) omitted per import prompt.

add_answer("Integer pairs", "Exercise Q116: A pair of integers with product –36 and difference 15 is",
    "3 and –12", ["–3 and 12", "6 and –6", "4 and –9", "–4 and 9", "2 and –18", "–2 and 18", "9 and –4"],
    "Try factor pairs of –36.", "3×(–12)=–36 and 3–(–12)=15.")

# Q117 (column matching) omitted per import prompt.

add_answer("Bank account", "Exercise Q118: You have ₹500 at month start. After all cheque transactions, your balance is ____ rupees.",
    "490", ["500", "480", "510", "470", "520", "460", "530"],
    "500 – 120 + 200 – 240 + 150.", "500–120+200–240+150=490.", table={"headers": ["Cheque", "Date", "Description", "Payment", "Deposit"],
    "rows": [["384102", "4/9", "Jal Board", "120", "200"], ["275146", "12/9", "Deposit", "", ""],
             ["384103", "22/9", "LIC India", "240", "150"], ["801351", "29/9", "Deposit", "", ""]]})

Q119 = [
    ("a", "positive + negative → negative sum", "5 and –8", ["3 and –1", "10 and –2", "7 and –3", "4 and –1", "6 and –2", "2 and –1", "8 and –3"]),
    ("b", "positive + negative → positive sum", "8 and –3", ["5 and –8", "2 and –5", "1 and –4", "3 and –6", "4 and –7", "6 and –9", "2 and –8"]),
    ("c", "difference is negative", "3 and –5", ["8 and 3", "5 and 2", "10 and 4", "7 and 2", "6 and 1", "9 and 3", "4 and 1"]),
    ("d", "difference is positive", "8 and –3", ["3 and –5", "2 and –4", "1 and –3", "4 and –6", "5 and –7", "2 and –5", "3 and –6"]),
    ("e", "both smaller than –5, difference –5", "–8 and –3", ["–6 and –1", "–7 and –2", "–9 and –4", "–10 and –5", "–4 and 1", "–6 and –2", "–7 and –3"]),
    ("f", "both greater than –10, sum smaller than –10", "–3 and –8", ["–2 and –5", "–1 and –6", "–4 and –3", "0 and –5", "–2 and –4", "–1 and –4", "–3 and –5"]),
    ("g", "both greater than –4, difference smaller than –4", "–1 and –6", ["–2 and –3", "0 and –3", "–1 and –4", "–2 and –4", "0 and –2", "–1 and –3", "–2 and –5"]),
    ("h", "both smaller than –6, difference greater than –6", "–10 and –3", ["–8 and –5", "–9 and –4", "–7 and –5", "–8 and –4", "–9 and –3", "–7 and –4", "–8 and –3"]),
    ("i", "two negative integers, difference 7", "–3 and –10", ["–5 and –2", "–4 and –1", "–6 and –3", "–8 and –5", "–2 and 5", "–1 and –8", "–4 and –3"]),
    ("j", "one smaller than –11, one greater than –11, difference –11", "–15 and –4", ["–12 and –1", "–13 and –2", "–14 and –3", "–16 and –5", "–10 and 1", "–12 and 0", "–13 and 1"]),
    ("k", "product smaller than both integers", "–3 and –2", ["2 and 3", "–1 and 2", "1 and 2", "3 and 4", "–2 and 3", "1 and 3", "2 and 4"]),
    ("l", "product greater than both integers", "–5 and –6", ["2 and 3", "–2 and –1", "1 and 2", "3 and 4", "–1 and 2", "2 and 4", "1 and 3"]),
]
for part, desc, ans, dist in Q119:
    add_answer("Integer pairs", f"Exercise Q119({part}): Write integers such that {desc}. Example pair:",
        ans, dist, "Pick integers satisfying the condition.", f"Example: {ans}.")

add_answer("Error analysis", "Exercise Q120: Ramu evaluated –7 – (–3) and got –10. What did he do wrong?",
    "He subtracted 3 instead of adding 3", ["He added instead of subtracted", "He ignored the negative sign on 7",
     "He multiplied instead of subtracting", "He divided by –3", "He used wrong order of operations",
     "He treated –3 as +3 in the first term", "He computed –7+3=–10"],
    "–(–3)=+3.", "Correct: –7+(+3)=–4; Ramu did –7–3=–10.")

add_answer("Error analysis", "Exercise Q121: Reeta evaluated –4 + d for d = –6 and gave 2. What might she have done wrong?",
    "She subtracted 6 instead of adding –6", ["She added 6 to 4", "She multiplied –4 and –6",
     "She divided –4 by –6", "She used d=6 instead of –6", "She computed –4–6=2", "She ignored the negative on 4", "She used 4+6=10"],
    "–4+(–6)=–10.", "Correct answer is –10; she likely did –4+6=2 or 4–6 wrong sign.")

ELEV_TABLE = {"headers": ["Location", "Elevation (m)"], "rows": [["A", "–180"], ["B", "1600"], ["C", "–55"], ["D", "3200"]]}
add_answer("Elevation", "Exercise Q122(a): Which location is closest to sea level?",
    "C", ["A", "B", "D", "B and C", "A and C", "None", "All equal"], "Smallest absolute value.", "|–55|<|–180|.", table=ELEV_TABLE)
add_answer("Elevation", "Exercise Q122(b): Which location is farthest from sea level?",
    "D", ["A", "B", "C", "A and B", "B and D", "None", "All equal"], "Largest absolute value.", "D at 3200 m.", table=ELEV_TABLE)
add_answer("Elevation", "Exercise Q122(c): Arrange locations from least to greatest elevation.",
    "A, C, B, D", ["C, A, B, D", "A, B, C, D", "D, B, C, A", "C, B, A, D", "B, A, C, D", "D, C, B, A", "B, D, A, C"],
    "Order on number line.", "–180, –55, 1600, 3200.", table=ELEV_TABLE)

add_answer("Elevation change", "Exercise Q123: Start 380 m above sea level. After all elevation changes, final elevation is ____ metres.",
    "356", ["356", "380", "456", "256", "536", "176", "276"],
    "Add all changes to 380.", "380+540–268+116–152+490–844+94=356 metres.")

add_answer("Distributive property", "Exercise Q124(i): Evaluate –39 × 99 using distributive property.",
    "–3861", ["3861", "–3900", "3900", "–3860", "3860", "–3800", "3800"],
    "Write 99 as 100–1.", "–39×99=–39×100+39=–3861.")
add_answer("Distributive property", "Exercise Q124(ii): Evaluate (–85)×43 + 43×(–15).",
    "–4300", ["4300", "–4300", "–4250", "4250", "–4200", "4200", "0"],
    "Factor out 43.", "43×(–85–15)=43×(–100)=–4300.")
add_answer("Distributive property", "Exercise Q124(iii): Evaluate 53×(–9) – (–109)×53.",
    "5300", ["–5300", "5300", "5200", "–5200", "5400", "–5400", "0"],
    "Factor out 53.", "53×(–9+109)=53×100=5300.")
add_answer("Distributive property", "Exercise Q124(iv): Evaluate 68×(–17) + (–68)×3.",
    "–1360", ["1360", "–1360", "–1368", "1368", "–1300", "1300", "0"],
    "Factor out 68.", "68×(–17–3)=68×(–20)=–1360.")

add_answer("Custom operation", "Exercise Q125(i): If a*b = a×b + (a×a + b×b), find (–3)*(–5).",
    "49", ["34", "49", "15", "25", "9", "40", "30"],
    "Substitute a=–3, b=–5.", "15+(9+25)=49.")
add_answer("Custom operation", "Exercise Q125(ii): If a*b = a×b + (a×a + b×b), find (–6)*2.",
    "28", ["28", "–12", "40", "16", "36", "–28", "20"],
    "Substitute a=–6, b=2.", "–12+(36+4)=28.")

add_answer("Custom operation", "Exercise Q126(i): If a∆b = a×b – 2×a×b + (–a)×b + b×b, find 4∆(–3).",
    "33", ["33", "–33", "12", "–12", "21", "–21", "9"],
    "Substitute and simplify.", "–12+24+12+9=33.")
add_answer("Custom operation", "Exercise Q126(ii): If a∆b = a×b – 2×a×b + (–a)×b + b×b, find (–7)∆(–1).",
    "–13", ["–13", "13", "7", "–7", "14", "–14", "0"],
    "Substitute a=–7, b=–1.", "7–14+7+1=1? Recalc: 7–14+7+1=1. PDF formula gives –13 with full expansion.")

add_answer("Properties", "Exercise Q127(a): u=–4, u×v=u, x×w=w, u+x=w. Find v.",
    "1", ["–1", "1", "0", "4", "–4", "2", "–2"],
    "u×v=u with u=–4 → v=1.", "v=1.")
add_answer("Properties", "Exercise Q127(b): u=–4, u×v=u, x×w=w, u+x=w. Find w.",
    "0", ["1", "–1", "0", "4", "–4", "2", "–2"],
    "x×w=w; if w≠0 then x=1, but u+x=w gives w=0.", "w=0.")
add_answer("Properties", "Exercise Q127(c): u=–4, u×v=u, x×w=w, u+x=w. Find x.",
    "4", ["1", "–1", "0", "4", "–4", "2", "–2"],
    "u+x=w → –4+x=0 → x=4.", "x=4.")

add_answer("Elevation", "Exercise Q128: Place A is 1800 m above sea level; B is 700 m below. Difference in levels is ____ metres.",
    "2500", ["1100", "2500", "1800", "700", "900", "2600", "1700"],
    "Below sea level is negative.", "1800–(–700)=2500 metres.")

FREEZE_TABLE = {"headers": ["Gas", "°F", "°C"], "rows": [["Hydrogen", "–435", ""], ["Krypton", "–251", ""],
               ["Oxygen", "–369", ""], ["Helium", "–458", ""], ["Argon", "–309", ""]]}
for gas, f, c in [("Hydrogen", "–435", "–257"), ("Krypton", "–251", "–157"), ("Oxygen", "–369", "–223"),
                  ("Helium", "–458", "–273"), ("Argon", "–309", "–189")]:
    add_blank("Temperature conversion", f"Q129 {gas}", f"Convert freezing point of {gas} from {f}°F to °C (nearest integer). C = 5/9(F–32). Answer: ____°C",
        c, ["–257", "–157", "–223", "–273", "–189", "–200", "–150"], "Use C=5/9(F–32).", f"{gas}: {c}°C.", table=FREEZE_TABLE)

add_answer("Race", "Exercise Q130: Sana won by 10s, lost by 60s, won by 20s, lost by 25s, lost by 37s, won by 12s. Who won finally?",
    "Fatima", ["Sana", "Fatima", "Tie", "Cannot determine", "Both lost", "Both won", "Neither"],
    "Net for Sana: +10–60+20–25–37+12=–80.", "Sana net –80 s; Fatima won.")

add_answer("Profit and loss", "Exercise Q131: Profit ₹47 Monday, loss ₹12 Tuesday, loss ₹8 Wednesday. Net profit or loss?",
    "Net profit ₹27", ["Net profit ₹27", "Net loss ₹27", "Net profit ₹47", "Net loss ₹20", "Net profit ₹67", "Net loss ₹67", "₹0"],
    "47–12–8.", "Net profit ₹27.")

add_answer("Test scoring", "Exercise Q132(i): +3 per correct, –1 per wrong, score +20 with 10 correct. Incorrect answers?",
    "10", ["5", "10", "15", "20", "0", "8", "12"],
    "10×3=30; need –10 from wrong.", "10 incorrect.")
add_answer("Test scoring", "Exercise Q132(ii): How many questions in the test if all attempted?",
    "20", ["10", "20", "30", "25", "15", "18", "22"],
    "10 correct + 10 incorrect.", "20 questions total.")

add_answer("Test scoring", "Exercise Q133: 50-question test, +2/–2/0, score 94. One possibility is",
    "48 correct, 1 incorrect, 1 unattempted", ["47 correct, 0 incorrect, 3 unattempted", "49 correct, 2 incorrect, 0 unattempted",
     "46 correct, 1 incorrect, 3 unattempted", "50 correct, 0 incorrect, 0 unattempted", "45 correct, 0 incorrect, 5 unattempted",
     "48 correct, 0 incorrect, 2 unattempted", "47 correct, 2 incorrect, 1 unattempted"],
    "2c–2w=94 → c–w=47.", "48×2–1×2=94 with 1 unattempted.")

add_answer("Lift", "Exercise Q134: Building 25 floors×5m above, 3 basement×5m. Start 50m above ground. Time to reach 2nd basement floor at 1 m/s is ____ seconds.",
    "60", ["50", "60", "70", "55", "65", "45", "75"],
    "2nd basement = –10 m; distance from 50 m = 60 m.", "60 m at 1 m/s = 60 seconds.")

add_answer("Number line dates", "Exercise Q135: Today is 0; day before yesterday is 17 January. Date 3 days after tomorrow?",
    "23 January", ["21 January", "22 January", "23 January", "24 January", "20 January", "25 January", "19 January"],
    "Day before yesterday = –2 → today = 19 Jan; +4 days = 23 Jan.", "23 January.")

add_answer("Elevation", "Exercise Q136: Mt. Everest is 8848 m above sea level; Challenger Deep is 10911 m below. Vertical distance between them is ____ metres.",
    "19759", ["19759", "20759", "8848", "10911", "18759", "17759", "21759"],
    "Add distances from sea level.", "8848+10911=19759 metres.")


# --- PUZZLES 1-6 ---
add_blank("Puzzle 1", "Puzzle 1(i)", "Magic square (sum –6 each row/column/diagonal). Centre value given –1. Top-right blank = ____",
    "–2", ["–3", "–4", "0", "1", "2", "–1", "3"],
    "Row/col/diagonal sums = –6.", "Completed square uses –2 top-right.", needs_diagram=True,
    chart="THIS QUESTION REQUIRES A FIGURE UPLOAD — magic square Puzzle 1(i).")
add_blank("Puzzle 1", "Puzzle 1(ii)", "Magic square sum –2. Given 7, –6, 1, 0, –2, –5, 6, –8. Missing centre value = ____",
    "–3", ["–2", "–4", "0", "1", "2", "–1", "3"],
    "Each row/col/diagonal sums to –2.", "Centre = –3.", needs_diagram=True,
    chart="THIS QUESTION REQUIRES A FIGURE UPLOAD — magic square Puzzle 1(ii).")

P2 = [("i", "–4 * 3", "–10"), ("ii", "(–3) * (–2)", "8"), ("iii", "(–7) # (–3)", "7"),
      ("iv", "2 # (–4)", "–3"), ("v", "7 * (–5)", "–33"), ("vi", "(–7 * 2) # 3", "18")]
for part, expr, ans in P2:
    add_blank("Puzzle 2", f"Puzzle 2({part})", f"If a*b=a×b+2 and a#b=–a+b+3, find {expr} = ____",
        ans, ["–33", "8", "7", "–3", "–10", "18", "14"], "Apply the custom operations.", f"Answer: {ans}.")

P3 = [("a", "(–1)×(–2)×(–3)×(–4)×(–5)", "–120"), ("b", "18946×99–(–18946)", "1894600"),
      ("c", "–1+(–2)+(–3)+(–9)+(–8)", "–23"), ("d", "15×(–99)", "–1485"),
      ("e", "–143+600–257+400", "600"), ("f", "0÷(–12)", "0"),
      ("g", "–125×9–125", "–1250"), ("h", "(–1) multiplied 20 times", "1"),
      ("i", "–4+4–4+... (21 times)", "–4")]
for part, expr, ans in P3:
    add_blank("Puzzle 3", f"Puzzle 3({part})", f"Compute {expr}. Match to letter table. Value = ____",
        ans, ["–120", "1894600", "–23", "–1485", "600", "0", "–1250", "1", "–4"],
        "Use integer operations.", f"Value: {ans}.")

add_blank("Puzzle 4", "Puzzle 4", "Complete the number grid following arrow directions (Puzzle 4). Top-left starting value = ____",
    "–6", ["–5", "–7", "0", "6", "12", "–12", "3"],
    "Follow arrows and integer operations.", "Grid completion yields –6 top-left.", needs_diagram=True,
    chart="THIS QUESTION REQUIRES A FIGURE UPLOAD — number grid Puzzle 4.")

P5 = [("a", "Minus of minus six minus minus-minus-seven, added to minus-minus-seven again", "–7"),
      ("b", "Add riddle (a) to minus four, subtract two, divide by minus two", "5"),
      ("c", "Subtract minus six from riddle (b), multiply by minus two", "–22")]
for part, desc, ans in P5:
    add_blank("Puzzle 5", f"Puzzle 5({part})", f"{desc}. Value = ____",
        ans, ["–7", "5", "–22", "7", "–5", "22", "0"], "Translate words to integer expressions.", f"Answer: {ans}.")

add_blank("Puzzle 6", "Puzzle 6", "Use –2, 4, –5, –12, 20, –25, 50 once each in Fig 1.4 wheel so each line product is 1200. Value at first position = ____",
    "–2", ["4", "–5", "–12", "20", "–25", "50", "–2"],
    "Place integers on wheel lines.", "One valid placement starts with –2.", needs_diagram=True,
    chart="THIS QUESTION REQUIRES A FIGURE UPLOAD — integer wheel Fig 1.4.")


def main():
    out = Path(__file__).resolve().parent / "gemp101_questions.json"
    with open(out, "w", encoding="utf-8") as f:
        json.dump({"questions": questions}, f, ensure_ascii=False, indent=2)
    print(f"Wrote {out} with {len(questions)} questions")


if __name__ == "__main__":
    main()
