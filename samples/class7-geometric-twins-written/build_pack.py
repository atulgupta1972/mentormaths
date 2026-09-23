#!/usr/bin/env python3
"""Build Class 7 Geometric Twins written practice zip (40 sums + diagrams)."""

from __future__ import annotations

import json
import zipfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent
DIAG = ROOT / "diagrams"
OUT_ZIP = ROOT / "class7-geometric-twins-written-import.zip"


def font(size: int = 18):
    for name in ("DejaVuSans.ttf", "arial.ttf", "C:\\Windows\\Fonts\\arial.ttf"):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def new_canvas(w=640, h=420):
    img = Image.new("RGB", (w, h), "white")
    draw = ImageDraw.Draw(img)
    return img, draw


def label(draw, xy, text, size=20):
    draw.text(xy, text, fill="#0f172a", font=font(size))


def line(draw, a, b, width=3, fill="#0f172a"):
    draw.line([a, b], fill=fill, width=width)


def right_angle(draw, corner, toward_a, toward_b, size=16):
    """Small square at corner between two directions."""
    import math

    def unit(p, q):
        dx, dy = q[0] - p[0], q[1] - p[1]
        L = math.hypot(dx, dy) or 1
        return dx / L, dy / L

    ua = unit(corner, toward_a)
    ub = unit(corner, toward_b)
    p1 = (corner[0] + ua[0] * size, corner[1] + ua[1] * size)
    p2 = (corner[0] + ub[0] * size, corner[1] + ub[1] * size)
    p3 = (corner[0] + (ua[0] + ub[0]) * size, corner[1] + (ua[1] + ub[1]) * size)
    draw.line([p1, p3, p2], fill="#0f172a", width=2)


def tick(draw, a, b, n=1, offset=10):
    """Equality tick marks on segment midpoint."""
    import math

    mx, my = (a[0] + b[0]) / 2, (a[1] + b[1]) / 2
    dx, dy = b[0] - a[0], b[1] - a[1]
    L = math.hypot(dx, dy) or 1
    px, py = -dy / L, dx / L
    for i in range(n):
        shift = (i - (n - 1) / 2) * 6
        cx, cy = mx + dx / L * shift, my + dy / L * shift
        draw.line(
            [(cx - px * offset, cy - py * offset), (cx + px * offset, cy + py * offset)],
            fill="#0f172a",
            width=2,
        )


def angle_arc(draw, vertex, p1, p2, r=22):
    import math

    def ang(p):
        return math.atan2(p[1] - vertex[1], p[0] - vertex[0])

    a1, a2 = ang(p1), ang(p2)
    # normalize
    while a2 < a1:
        a2 += 2 * math.pi
    bbox = [vertex[0] - r, vertex[1] - r, vertex[0] + r, vertex[1] + r]
    draw.arc(bbox, start=math.degrees(a1), end=math.degrees(a2), fill="#2563eb", width=2)


# ---- Diagram builders -------------------------------------------------------

def fig_two_triangles(path: Path, left=("A", "B", "C"), right=("P", "Q", "R"), marks="asa"):
    img, d = new_canvas()
    A, B, C = (90, 80), (50, 300), (220, 300)
    P, Q, R = (400, 80), (360, 300), (560, 300)
    line(d, A, B)
    line(d, B, C)
    line(d, C, A)
    line(d, P, Q)
    line(d, Q, R)
    line(d, R, P)
    label(d, (A[0] - 8, A[1] - 28), left[0])
    label(d, (B[0] - 22, B[1] + 4), left[1])
    label(d, (C[0] + 8, C[1] + 4), left[2])
    label(d, (P[0] - 8, P[1] - 28), right[0])
    label(d, (Q[0] - 22, Q[1] + 4), right[1])
    label(d, (R[0] + 8, R[1] + 4), right[2])
    if marks == "asa":
        angle_arc(d, B, A, C)
        angle_arc(d, Q, P, R)
        angle_arc(d, A, C, B, r=18)
        angle_arc(d, P, R, Q, r=18)
        tick(d, A, B)
        tick(d, P, Q)
    elif marks == "sas":
        tick(d, A, B)
        tick(d, P, Q)
        tick(d, B, C, n=2)
        tick(d, Q, R, n=2)
        angle_arc(d, B, A, C)
        angle_arc(d, Q, P, R)
    elif marks == "sss":
        tick(d, A, B)
        tick(d, P, Q)
        tick(d, B, C, n=2)
        tick(d, Q, R, n=2)
        tick(d, C, A, n=3)
        tick(d, R, P, n=3)
    img.save(path, quality=92)


def fig_altitude(path: Path, labels=("A", "C", "B", "D")):
    """Triangle ACD with altitude AB to base CD (B foot)."""
    img, d = new_canvas()
    A, C, D, B = (320, 50), (80, 340), (560, 340), (320, 340)
    line(d, A, C)
    line(d, C, D)
    line(d, D, A)
    line(d, A, B)
    right_angle(d, B, A, C)
    label(d, (A[0] - 8, A[1] - 28), labels[0])
    label(d, (C[0] - 18, C[1] + 4), labels[1])
    label(d, (B[0] - 6, B[1] + 8), labels[2])
    label(d, (D[0] + 6, D[1] + 4), labels[3])
    tick(d, C, B)
    tick(d, B, D)
    img.save(path, quality=92)


def fig_bowtie(path: Path, labels=("A", "B", "C", "D")):
    """Triangles ABC (up) and DBC (down) sharing BC."""
    img, d = new_canvas()
    A, B, C, D = (320, 40), (120, 220), (520, 220), (320, 380)
    line(d, A, B)
    line(d, B, C)
    line(d, C, A)
    line(d, D, B)
    line(d, D, C)
    label(d, (A[0] - 8, A[1] - 28), labels[0])
    label(d, (B[0] - 22, B[1] - 8), labels[1])
    label(d, (C[0] + 8, C[1] - 8), labels[2])
    label(d, (D[0] - 8, D[1] + 4), labels[3])
    tick(d, A, B)
    tick(d, D, B)
    tick(d, A, C, n=2)
    tick(d, D, C, n=2)
    img.save(path, quality=92)


def fig_overlap(path: Path, labels=("A", "B", "C", "P")):
    """Overlapping triangles ABC and PBC / PCB."""
    img, d = new_canvas()
    A, B, C, P = (100, 80), (80, 340), (520, 340), (540, 80)
    line(d, A, B)
    line(d, B, C)
    line(d, C, A)
    line(d, P, B)
    line(d, P, C)
    label(d, (A[0] - 18, A[1] - 8), labels[0])
    label(d, (B[0] - 18, B[1] + 4), labels[1])
    label(d, (C[0] + 6, C[1] + 4), labels[2])
    label(d, (P[0] + 6, P[1] - 8), labels[3])
    tick(d, A, B)
    tick(d, P, C)
    angle_arc(d, B, A, C)
    angle_arc(d, C, P, B)
    img.save(path, quality=92)


def fig_rect_diagonals(path: Path):
    """Rectangle ABCD with diagonals; right angles at B and C."""
    img, d = new_canvas(640, 400)
    A, B, C, D = (120, 80), (120, 300), (520, 300), (520, 80)
    line(d, A, B)
    line(d, B, C)
    line(d, C, D)
    line(d, D, A)
    line(d, A, C)
    line(d, B, D)
    right_angle(d, B, A, C)
    right_angle(d, C, B, D)
    label(d, (A[0] - 22, A[1] - 8), "A")
    label(d, (B[0] - 22, B[1] + 4), "B")
    label(d, (C[0] + 8, C[1] + 4), "C")
    label(d, (D[0] + 8, D[1] - 8), "D")
    tick(d, A, B)
    tick(d, D, C)
    img.save(path, quality=92)


def fig_isosceles_common(path: Path):
    """△ABC and △ADC sharing AC; AB=AD, CB=CD."""
    img, d = new_canvas()
    A, B, C, D = (320, 50), (80, 340), (320, 280), (560, 340)
    line(d, A, B)
    line(d, B, C)
    line(d, C, A)
    line(d, A, D)
    line(d, D, C)
    label(d, (A[0] - 8, A[1] - 28), "A")
    label(d, (B[0] - 18, B[1] + 4), "B")
    label(d, (C[0] + 10, C[1] - 6), "C")
    label(d, (D[0] + 6, D[1] + 4), "D")
    tick(d, A, B)
    tick(d, A, D)
    tick(d, B, C, n=2)
    tick(d, D, C, n=2)
    img.save(path, quality=92)


def fig_rhs(path: Path):
    """Two right triangles for RHS."""
    img, d = new_canvas()
    A, B, C = (80, 300), (80, 80), (280, 300)
    P, Q, R = (380, 300), (380, 80), (580, 300)
    for tri in ((A, B, C), (P, Q, R)):
        line(d, tri[0], tri[1])
        line(d, tri[1], tri[2])
        line(d, tri[2], tri[0])
    right_angle(d, A, B, C)
    right_angle(d, P, Q, R)
    tick(d, B, C)  # hypotenuse
    tick(d, Q, R)
    tick(d, A, B, n=2)
    tick(d, P, Q, n=2)
    label(d, (A[0] - 20, A[1] + 4), "A")
    label(d, (B[0] - 20, B[1] - 20), "B")
    label(d, (C[0] + 6, C[1] + 4), "C")
    label(d, (P[0] - 20, P[1] + 4), "P")
    label(d, (Q[0] - 20, Q[1] - 20), "Q")
    label(d, (R[0] + 6, R[1] + 4), "R")
    img.save(path, quality=92)


def fig_line_segments(path: Path):
    """Equal line segments with midpoints."""
    img, d = new_canvas(640, 280)
    A, B, C, D = (60, 100), (300, 100), (340, 100), (580, 100)
    P, Q = (60, 200), (300, 200)
    R, S = (340, 200), (580, 200)
    line(d, A, B)
    line(d, C, D)
    line(d, P, Q)
    line(d, R, S)
    tick(d, A, B)
    tick(d, C, D)
    tick(d, P, Q, n=2)
    tick(d, R, S, n=2)
    label(d, (A[0] - 4, A[1] - 28), "A")
    label(d, (B[0] - 4, B[1] - 28), "B")
    label(d, (C[0] - 4, C[1] - 28), "C")
    label(d, (D[0] - 4, D[1] - 28), "D")
    label(d, (P[0] - 4, P[1] + 8), "P")
    label(d, (Q[0] - 4, Q[1] + 8), "Q")
    label(d, (R[0] - 4, R[1] + 8), "R")
    label(d, (S[0] - 4, S[1] + 8), "S")
    img.save(path, quality=92)


def fig_angles(path: Path):
    """Two angles with equal marks."""
    img, d = new_canvas(640, 320)
    O1, A1, B1 = (160, 220), (60, 80), (300, 100)
    O2, A2, B2 = (480, 220), (380, 80), (600, 100)
    line(d, O1, A1)
    line(d, O1, B1)
    line(d, O2, A2)
    line(d, O2, B2)
    angle_arc(d, O1, A1, B1, r=36)
    angle_arc(d, O2, A2, B2, r=36)
    label(d, (O1[0] - 8, O1[1] + 8), "O")
    label(d, (A1[0] - 10, A1[1] - 18), "A")
    label(d, (B1[0] + 4, B1[1] - 8), "B")
    label(d, (O2[0] - 8, O2[1] + 8), "P")
    label(d, (A2[0] - 10, A2[1] - 18), "Q")
    label(d, (B2[0] + 4, B2[1] - 8), "R")
    img.save(path, quality=92)


def fig_parallel_asa(path: Path):
    """AB ∥ CD with transversals for ASA."""
    img, d = new_canvas()
    A, B = (80, 120), (280, 120)
    C, D = (360, 300), (560, 300)
    O = (320, 210)
    # draw AB, CD and diagonals through O
    line(d, A, B)
    line(d, C, D)
    line(d, A, D)
    line(d, B, C)
    # mark O midpoint-ish
    label(d, (A[0] - 18, A[1] - 22), "A")
    label(d, (B[0] + 6, B[1] - 22), "B")
    label(d, (C[0] - 18, C[1] + 4), "C")
    label(d, (D[0] + 6, D[1] + 4), "D")
    label(d, (O[0] + 6, O[1] - 8), "O")
    tick(d, A, B)
    tick(d, C, D)
    img.save(path, quality=92)


def fig_square_diag(path: Path):
    img, d = new_canvas(480, 480)
    A, B, C, D = (80, 80), (80, 380), (380, 380), (380, 80)
    line(d, A, B)
    line(d, B, C)
    line(d, C, D)
    line(d, D, A)
    line(d, A, C)
    label(d, (A[0] - 22, A[1] - 8), "A")
    label(d, (B[0] - 22, B[1] + 4), "B")
    label(d, (C[0] + 8, C[1] + 4), "C")
    label(d, (D[0] + 8, D[1] - 8), "D")
    tick(d, A, B)
    tick(d, A, D)
    tick(d, B, C, n=2)
    tick(d, D, C, n=2)
    img.save(path, quality=92)


def q(topic, question, answer, difficulty, fmt="integer", hint="", explanation="", needs_diagram=False, diagram_file=None, decimal_places=None):
    row = {
        "topic": topic,
        "question": question,
        "answer_format": fmt,
        "correct_answer": str(answer),
        "method_hint": hint,
        "explanation": explanation,
        "difficulty": difficulty,
        "needs_diagram": needs_diagram,
    }
    if needs_diagram and diagram_file:
        row["diagram_file"] = diagram_file
    if decimal_places is not None:
        row["decimal_places"] = decimal_places
    return row


PROOF = (
    "Write using: (1) To prove (2) Diagram with congruent marks "
    "(3) Condition–Reason table with 3 rows (4) Congruence criterion. "
)


def build_questions() -> list[dict]:
    T1 = "Congruence of Plane Figures"
    T2 = "Congruence among Line Segments & Angles"
    T3 = "Criteria for Congruence (SSS, SAS)"
    T4 = "Criteria for Congruence (ASA, RHS)"

    qs: list[dict] = []

    # --- T1 Medium 5 ---
    qs.append(q(T1,
        "In the figure, △ABC and △PQR have AB = PQ = 6 cm, BC = QR = 8 cm and CA = RP = 7 cm. "
        + PROOF + "Prove △ABC ≅ △PQR. Hence find the length (in cm) of side corresponding to BC under this congruence.",
        8, "Medium", hint="SSS: three corresponding sides equal ⇒ triangles congruent; corresponding sides then equal by CPCT.",
        explanation="AB=PQ, BC=QR, CA=RP. By SSS, △ABC ≅ △PQR with B↔Q. So BC corresponds to QR = 8.",
        needs_diagram=True, diagram_file="q1.jpg"))
    qs.append(q(T1,
        "In the figure, two plane figures match under a flip so that every point of one maps onto the other. "
        "If the perimeter of the first figure is 42 cm, find the perimeter (in cm) of its congruent twin.",
        42, "Medium",
        hint="Congruent figures have the same shape and size, so corresponding lengths and perimeters are equal.",
        explanation="Congruent figures have equal corresponding sides, so perimeters are equal. Perimeter = 42.",
        needs_diagram=False))
    qs.append(q(T1,
        "In the figure, △ABC ≅ △DEF under A↔D, B↔E, C↔F. If ∠A = 55° and ∠B = 70°, find ∠F in degrees.",
        55, "Medium",
        hint="Under congruence, corresponding angles are equal (CPCT). C↔F so ∠C = ∠F. Also ∠A+∠B+∠C=180°.",
        explanation="∠C = 180 − 55 − 70 = 55°. Since C↔F, ∠F = ∠C = 55.",
        needs_diagram=True, diagram_file="q3.jpg"))
    qs.append(q(T1,
        "In the figure, a rubber stamp prints congruent copies of a triangle. One print has base 9 cm. "
        "Find the base (in cm) of the next congruent print of the same stamp.",
        9, "Medium",
        hint="Congruent copies from the same stamp have equal corresponding lengths.",
        explanation="Congruent figures produced by the same stamp are equal in all corresponding lengths. Base = 9.",
        needs_diagram=False))
    qs.append(q(T1,
        "In the figure, △ABC ≅ △XYZ. If AB = 5.5 cm and the side corresponding to AB is XY, find XY in cm.",
        "5.5", "Medium", fmt="decimal", decimal_places=1,
        hint="Corresponding sides of congruent triangles are equal.",
        explanation="Under △ABC ≅ △XYZ with A↔X, B↔Y, AB corresponds to XY, so XY = 5.5.",
        needs_diagram=True, diagram_file="q5.jpg"))

    # --- T1 Hard 5 ---
    qs.append(q(T1,
        "In the figure, △ABC ≅ △PQR and also △ABC ≅ △XYZ. If PQ = 11 cm, find the length (in cm) of the side of △XYZ that corresponds to AB (same correspondence A↔P↔X, B↔Q↔Y).",
        11, "Hard",
        hint="Congruence is transitive for corresponding parts: if both are congruent to △ABC under matching letters, corresponding sides match.",
        explanation="AB corresponds to PQ and to XY. PQ = 11 ⇒ XY = 11.",
        needs_diagram=True, diagram_file="q6.jpg"))
    qs.append(q(T1,
        "In the figure, square ABCD has diagonal AC. "
        + PROOF + "Prove △ABC ≅ △ADC. Hence find ∠BAC + ∠DAC in degrees (the full angle at A of the square).",
        90, "Hard",
        hint="In a square all sides are equal and diagonals create two triangles with three equal sides (SSS).",
        explanation="AB=AD, BC=DC, AC common ⇒ SSS ⇒ △ABC ≅ △ADC. ∠BAD of a square is 90°, so ∠BAC+∠DAC = 90.",
        needs_diagram=True, diagram_file="q7.jpg"))
    qs.append(q(T1,
        "In the figure, two congruent rectangles each measure 12 cm by 5 cm. Find the total area (in cm²) of both rectangles together.",
        120, "Hard",
        hint="Area of rectangle = length × breadth. Congruent rectangles have equal areas.",
        explanation="Each area = 12×5 = 60. Both together = 120.",
        needs_diagram=False))
    qs.append(q(T1,
        "In the figure, △ABC ≅ △DEF. The map of vertices is A↔D, B↔E, C↔F. If BC = 3/4 of AC and AC = 16 cm, find DE in cm when AB = AC − 4.",
        12, "Hard",
        hint="Find AB from AC, then use corresponding sides under the given vertex map.",
        explanation="AB = 16 − 4 = 12. A↔D, B↔E ⇒ AB corresponds to DE, so DE = 12.",
        needs_diagram=True, diagram_file="q9.jpg"))
    qs.append(q(T1,
        "In the figure, a cardboard triangle is flipped and placed on another triangle so every vertex matches. "
        "If the original has angles 40°, 60° and 80°, find the largest angle (in degrees) of the congruent twin.",
        80, "Hard",
        hint="Flipping preserves size of angles; corresponding angles remain equal.",
        explanation="Congruent twin has the same angles 40°, 60°, 80°. Largest = 80.",
        needs_diagram=False))

    # --- T2 Medium 5 ---
    qs.append(q(T2,
        "In the figure, AB = CD = 7 cm and PQ = RS. If PQ = AB, find RS in cm.",
        7, "Medium",
        hint="Congruent (equal) line segments have equal lengths; equality is transitive.",
        explanation="PQ = AB = 7 and PQ = RS ⇒ RS = 7.",
        needs_diagram=True, diagram_file="q11.jpg"))
    qs.append(q(T2,
        "In the figure, ∠AOB ≅ ∠QPR. If ∠AOB = 48°, find ∠QPR in degrees.",
        48, "Medium",
        hint="Congruent angles have equal measures.",
        explanation="∠AOB ≅ ∠QPR ⇒ measures equal, so ∠QPR = 48.",
        needs_diagram=True, diagram_file="q12.jpg"))
    qs.append(q(T2,
        "In the figure, ray OX bisects ∠AOB. If ∠AOX = 35°, find ∠AOB in degrees.",
        70, "Medium",
        hint="An angle bisector divides an angle into two congruent (equal) angles.",
        explanation="∠AOX = ∠XOB = 35 ⇒ ∠AOB = 70.",
        needs_diagram=False))
    qs.append(q(T2,
        "In the figure, M is the midpoint of AB. If AM = 9/2 cm, find AB in cm.",
        9, "Medium",
        hint="Midpoint divides a segment into two congruent halves of equal length.",
        explanation="AM = MB = 9/2 ⇒ AB = 9.",
        needs_diagram=False))
    qs.append(q(T2,
        "In the figure, ∠1 ≅ ∠2 and ∠2 ≅ ∠3. If ∠1 = 62°, find ∠3 in degrees.",
        62, "Medium",
        hint="Congruence of angles is transitive.",
        explanation="∠1 ≅ ∠2 ≅ ∠3 ⇒ ∠3 = ∠1 = 62.",
        needs_diagram=False))

    # --- T2 Hard 5 ---
    qs.append(q(T2,
        "In the figure, AB ≅ CD and CD ≅ EF. Points divide AB into three congruent parts each of length 4 cm. Find EF in cm.",
        12, "Hard",
        hint="Equal segments have equal lengths; sum of three congruent parts gives the whole.",
        explanation="AB = 3×4 = 12. AB ≅ CD ≅ EF ⇒ EF = 12.",
        needs_diagram=True, diagram_file="q16.jpg"))
    qs.append(q(T2,
        "In the figure, ∠PQR is bisected by ray QS and also ∠PQS ≅ ∠TUV. If ∠TUV = 27°, find ∠PQR in degrees.",
        54, "Hard",
        hint="Bisector makes two equal halves; congruent angles share measure.",
        explanation="∠PQS = ∠TUV = 27 ⇒ half of ∠PQR is 27 ⇒ ∠PQR = 54.",
        needs_diagram=True, diagram_file="q17.jpg"))
    qs.append(q(T2,
        "In the figure, two line segments are congruent. Their combined length is 25 cm and one is 3 cm longer than half of the other. "
        "If the shorter is x and the longer is x+3 with 2x+(x+3)=25, find the longer length in cm.",
        11, "Hard",
        hint="Solve the linear relation carefully; congruent means equal — here the setup gives two segments of lengths x and x+3 summing to 25.",
        explanation="3x + 3 = 25 ⇒ 3x = 22 ⇒ x = 22/3. Longer = x+3 = 31/3 ≈ 10.33 — wait: 2x+(x+3)=25 ⇒ 3x=22, x=22/3, longer=31/3. "
                    "Re-check intended: use x + (x+3) = 25 ⇒ 2x=22 ⇒ x=11, longer=14. Correct equation for two segments: x+(x+3)=25 ⇒ longer = 14.",
        needs_diagram=False))
    # Fix q18 - I made a mess in explanation. Let me fix properly below when rewriting.

    qs.append(q(T2,
        "In the figure, ∠A = ∠B and ∠B = ∠C. If ∠A + ∠B + ∠C = 180° (angles of a triangle), find ∠A in degrees.",
        60, "Hard",
        hint="Equal angles in a triangle that sum to 180° are each 60° when all three are equal.",
        explanation="∠A=∠B=∠C and sum 180 ⇒ each is 60.",
        needs_diagram=False))
    qs.append(q(T2,
        "In the figure, AB = 2·CM where M is midpoint of AB and CM is drawn to a point C off the line. "
        "If CM = 5 cm, find AB in cm.",
        10, "Hard",
        hint="Midpoint fact is about AM and MB; here AB = 2·CM is an extra given length relation.",
        explanation="AB = 2·CM = 2×5 = 10.",
        needs_diagram=False))

    # --- T3 Medium 5 (SSS, SAS) ---
    qs.append(q(T3,
        "In the figure, AB = PQ, BC = QR and CA = RP. "
        + PROOF + "Prove △ABC ≅ △PQR by SSS. If AB = 6 cm, find PQ in cm.",
        6, "Medium",
        hint="SSS criterion: three sides of one triangle equal to three corresponding sides of the other.",
        explanation="Given three pairs of equal sides ⇒ SSS ⇒ △ABC ≅ △PQR ⇒ PQ = AB = 6.",
        needs_diagram=True, diagram_file="q21.jpg"))
    qs.append(q(T3,
        "In the figure, AB = DE, ∠B = ∠E and BC = EF. "
        + PROOF + "Prove △ABC ≅ △DEF by SAS. If ∠B = 50°, find ∠E in degrees.",
        50, "Medium",
        hint="SAS: two sides and the included angle equal.",
        explanation="AB, ∠B, BC match DE, ∠E, EF with included angles ⇒ SAS. ∠E = ∠B = 50.",
        needs_diagram=True, diagram_file="q22.jpg"))
    qs.append(q(T3,
        "In the figure, AB = AD, CB = CD. "
        + PROOF + "Prove △ABC ≅ △ADC by SSS. If AB = 9 cm, find AD in cm.",
        9, "Medium",
        hint="Include the common side AC; then SSS applies.",
        explanation="AB=AD, CB=CD, AC common ⇒ SSS ⇒ △ABC ≅ △ADC ⇒ AD = AB = 9.",
        needs_diagram=True, diagram_file="q23.jpg"))
    qs.append(q(T3,
        "In the figure, △ABC and △DBC share side BC with AB = DB and AC = DC. "
        + PROOF + "Prove △ABC ≅ △DBC by SSS. If AC = 10 cm, find DC in cm.",
        10, "Medium",
        hint="Shared side BC is common; use SSS with the two given equal pairs.",
        explanation="AB=DB, AC=DC, BC common ⇒ SSS. DC = AC = 10.",
        needs_diagram=True, diagram_file="q24.jpg"))
    qs.append(q(T3,
        "In the figure, AB = XY = 6 cm, AC = XZ = 5 cm and ∠A = ∠X = 30° (included). "
        + PROOF + "Prove △ABC ≅ △XYZ by SAS. Find ∠X in degrees.",
        30, "Medium",
        hint="Included angle lies between the two equal sides.",
        explanation="Two sides and included angle equal ⇒ SAS. ∠X = 30.",
        needs_diagram=True, diagram_file="q25.jpg"))

    # --- T3 Hard 5 ---
    qs.append(q(T3,
        "In the figure, O is midpoint of AD and of BC. "
        + PROOF + "Prove △AOB ≅ △DOC by SAS (use vertical angles). If AO = 7 cm, find OD in cm.",
        7, "Hard",
        hint="Midpoint ⇒ AO=OD and BO=OC; vertical angles at O are equal — that included angle gives SAS.",
        explanation="AO=OD, ∠AOB=∠DOC (vert. opp.), BO=OC ⇒ SAS. OD = AO = 7.",
        needs_diagram=True, diagram_file="q26.jpg"))
    qs.append(q(T3,
        "In the figure, AB = DE, BC = EF and AC = DF. A student claims SSA proves congruence. "
        "If the only valid criterion that fits the three equal sides is SSS, write 1 for SSS and 0 for SSA. What number should the student choose?",
        1, "Hard",
        hint="SSA does not guarantee congruence; three sides do (SSS).",
        explanation="Three sides equal ⇒ SSS (code 1), not SSA.",
        needs_diagram=False))
    qs.append(q(T3,
        "In the figure, △ABC has AB = 8 cm, BC = 6 cm, ∠B = 90°. △PQR has PQ = 8 cm, QR = 6 cm, ∠Q = 90°. "
        + PROOF + "Prove congruence by SAS. Find AB + PQ in cm.",
        16, "Hard",
        hint="Right angle is included between AB,BC and PQ,QR respectively.",
        explanation="AB=PQ, ∠B=∠Q=90°, BC=QR ⇒ SAS. AB+PQ = 8+8 = 16.",
        needs_diagram=True, diagram_file="q28.jpg"))
    qs.append(q(T3,
        "In the figure, △ABC ≅ △ADC by SSS with AC common. If ∠BAC = 35°, find ∠DAC in degrees.",
        35, "Hard",
        hint="CPCT: corresponding angles at A are equal when B↔D under △ABC ≅ △ADC.",
        explanation="△ABC ≅ △ADC with B↔D ⇒ ∠BAC = ∠DAC = 35.",
        needs_diagram=True, diagram_file="q29.jpg"))
    qs.append(q(T3,
        "In the figure, sides of △ABC are 3.5 cm, 5 cm and 6 cm. △JAM has the same three side lengths. "
        "Find the number of distinct SSS correspondences that make △ABC ≅ △JAM when vertices are matched by equal side lengths (enter how many valid letter maps exist among the 6 permutations that preserve side matching — answer is 1 for the unique size-matching map up to naming in the book style, use 1).",
        1, "Hard",
        hint="SSS gives congruence once corresponding equal sides are matched; there is essentially one geometric match of the three lengths.",
        explanation="Three distinct side lengths determine a unique correspondence of vertices by matching lengths, so 1.",
        needs_diagram=True, diagram_file="q30.jpg"))

    # --- T4 Medium 5 (ASA, RHS) ---
    qs.append(q(T4,
        "In the figure, ∠B = ∠Q, BC = QR and ∠C = ∠R. "
        + PROOF + "Prove △ABC ≅ △PQR by ASA. If BC = 5 cm, find QR in cm.",
        5, "Medium",
        hint="ASA: two angles and the included side.",
        explanation="∠B, BC, ∠C match ∠Q, QR, ∠R ⇒ ASA. QR = BC = 5.",
        needs_diagram=True, diagram_file="q31.jpg"))
    qs.append(q(T4,
        "In the figure, △ABC and △PQR are right-angled at B and Q with hypotenuse AC = PR and leg AB = PQ. "
        + PROOF + "Prove △ABC ≅ △PQR by RHS. If AB = 9 cm, find PQ in cm.",
        9, "Medium",
        hint="RHS: right angle, hypotenuse and one side.",
        explanation="Right angles at B,Q; AC=PR; AB=PQ ⇒ RHS. PQ = 9.",
        needs_diagram=True, diagram_file="q32.jpg"))
    qs.append(q(T4,
        "In the figure, AB ∥ CD, AB = CD, and O is intersection of AC and BD. "
        + PROOF + "Prove △AOB ≅ △COD by ASA. If AB = 10 cm, find CD in cm.",
        10, "Medium",
        hint="Parallel lines give alternate interior angles equal; AB=CD is the included side pair with those angles.",
        explanation="Alternate angles equal and AB=CD ⇒ ASA. CD = AB = 10.",
        needs_diagram=True, diagram_file="q33.jpg"))
    qs.append(q(T4,
        "In the figure, △ABC and △DCB are right-angled at B and C respectively with AB = DC and BC common. "
        + PROOF + "Prove △ABC ≅ △DCB by RHS (or SAS). If AB = 12 cm, find DC in cm.",
        12, "Medium",
        hint="Right angles at B and C; hypotenuse AC and DB; given AB=DC and common BC.",
        explanation="AB = DC given; with right angles and common/hypotenuse setup ⇒ congruence. DC = 12.",
        needs_diagram=True, diagram_file="q34.jpg"))
    qs.append(q(T4,
        "In the figure, ∠A = ∠P = 40°, ∠B = ∠Q = 70°, and side AB = PQ (included between ∠A,∠B and ∠P,∠Q). "
        + PROOF + "Prove △ABC ≅ △PQR by ASA. Find ∠C in degrees.",
        70, "Medium",
        hint="Third angle of a triangle is 180° minus the other two; ASA uses two angles and included side.",
        explanation="∠C = 180−40−70 = 70. (ASA proves congruence; CPCT also gives ∠C=∠R=70.)",
        needs_diagram=True, diagram_file="q35.jpg"))

    # --- T4 Hard 5 ---
    qs.append(q(T4,
        "In the figure, altitude AB is drawn from A to side CD of △ACD with B on CD and CB = BD. "
        + PROOF + "Prove △ABC ≅ △ABD by RHS (or SAS). If ∠CAB = 28°, find ∠DAB in degrees.",
        28, "Hard",
        hint="Altitude ⇒ right angles at B; AB common hypotenuse relative to the small right triangles; CB=BD.",
        explanation="Right angles at B, AB common, CB=BD ⇒ RHS. ∠CAB=∠DAB by CPCT = 28.",
        needs_diagram=True, diagram_file="q36.jpg"))
    qs.append(q(T4,
        "In the figure, △ABC ≅ △PQR by ASA. If ∠A = 55° and ∠B = 65°, find ∠R in degrees.",
        60, "Hard",
        hint="Find ∠C first from angle sum; under A↔P, B↔Q, C↔R, ∠C corresponds to ∠R.",
        explanation="∠C = 180−55−65 = 60. C↔R ⇒ ∠R = 60.",
        needs_diagram=True, diagram_file="q37.jpg"))
    qs.append(q(T4,
        "In the figure, two right triangles have equal hypotenuses 13 cm and one pair of equal legs 5 cm. "
        + PROOF + "Prove they are congruent by RHS. Find the other leg in cm (use Pythagoras).",
        12, "Hard",
        hint="RHS gives congruence; Pythagoras: other² + 5² = 13².",
        explanation="other = √(169−25) = √144 = 12.",
        needs_diagram=True, diagram_file="q38.jpg"))
    qs.append(q(T4,
        "In the figure, △ABC and △PBC overlap on BC with ∠ABC = ∠PCB and ∠ACB = ∠PBC. "
        + PROOF + "Prove △ABC ≅ △PCB by ASA. If BC = 14 cm, find the length of the common side used as included side in cm.",
        14, "Hard",
        hint="The included side between the equal angle pairs is the common side BC.",
        explanation="ASA uses included side BC = 14.",
        needs_diagram=True, diagram_file="q39.jpg"))
    qs.append(q(T4,
        "In the figure, rectangle ABCD has ∠ABC = ∠BCD = 90° and AB = DC. "
        + PROOF + "Prove △ABC ≅ △DCB. If BC = 15 cm, find CB in cm (same segment).",
        15, "Hard",
        hint="Right angles at B and C, AB=DC, BC common ⇒ RHS/SAS.",
        explanation="BC is common, so its length is 15.",
        needs_diagram=True, diagram_file="q40.jpg"))

    # Fix botched q18 (index 17)
    qs[17] = q(T2,
        "Two congruent line segments have lengths x cm and (x + 3) cm and together measure 25 cm. Find the longer length in cm.",
        14, "Hard",
        hint="For two segments: x + (x + 3) = 25. Solve for x, then longer = x + 3.",
        explanation="2x + 3 = 25 ⇒ 2x = 22 ⇒ x = 11. Longer = 11 + 3 = 14.",
        needs_diagram=False)

    assert len(qs) == 40
    # renumber diagram files to q1..q40 matching order
    for i, row in enumerate(qs, start=1):
        if row.get("needs_diagram"):
            row["diagram_file"] = f"q{i}.jpg"
    return qs


def render_diagrams(questions: list[dict]):
    DIAG.mkdir(parents=True, exist_ok=True)
    for i, row in enumerate(questions, start=1):
        if not row.get("needs_diagram"):
            continue
        path = DIAG / f"q{i}.jpg"
        topic = row["topic"]
        # choose figure style by topic / index
        if i in (1, 5, 6, 9, 21, 25, 30):
            fig_two_triangles(path, marks="sss" if i in (1, 21, 30) else ("sas" if i in (25,) else "asa"))
        elif i in (3, 31, 35, 37):
            fig_two_triangles(path, marks="asa")
        elif i in (7, 29):
            fig_square_diag(path)
        elif i in (11, 16):
            fig_line_segments(path)
        elif i in (12, 17):
            fig_angles(path)
        elif i in (22, 28):
            fig_two_triangles(path, marks="sas")
        elif i in (23,):
            fig_isosceles_common(path)
        elif i in (24,):
            fig_bowtie(path)
        elif i in (26, 33):
            fig_parallel_asa(path)
        elif i in (32, 38):
            fig_rhs(path)
        elif i in (34, 40):
            fig_rect_diagonals(path)
        elif i in (36,):
            fig_altitude(path)
        elif i in (39,):
            fig_overlap(path)
        else:
            fig_two_triangles(path, marks="asa")


def main():
    questions = build_questions()
    render_diagrams(questions)
    payload = {"questions": questions}
    json_path = ROOT / "questions.json"
    json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    if OUT_ZIP.exists():
        OUT_ZIP.unlink()
    with zipfile.ZipFile(OUT_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.write(json_path, "questions.json")
        for row in questions:
            if row.get("needs_diagram"):
                img = DIAG / row["diagram_file"]
                zf.write(img, row["diagram_file"])

    # validate counts
    from collections import Counter
    c = Counter((q["topic"], q["difficulty"]) for q in questions)
    print("Counts:", dict(c))
    print("Wrote", OUT_ZIP, "size", OUT_ZIP.stat().st_size)


if __name__ == "__main__":
    main()
