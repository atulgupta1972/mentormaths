Formula bank export
===================

Total cards: 60
Exported at: 2026-07-26 04:48:05

How to import on production
---------------------------
1. git pull / deploy code if needed (Formula bank UI must exist).
2. Open Admin → Teaching → Formula bank.
3. Select the same Board + Class as in each file meta.
4. Open the matching chapter.
5. Paste the JSON from that chapter file into the import box.
6. Preview → verify → Save (creates cards + formula sets).

Important
---------
- Topic names in JSON must match prod syllabus topic names (same wording).
- Do not SQL-dump question IDs — local and prod IDs differ.
- Re-importing the same JSON will create duplicate cards; import each chapter once.

Files
-----
- cbse-class-7-chch-11-perimeter-and-area.json: CBSE Class 7 · Ch Ch 11 — Perimeter and Area · 10 cards
- cbse-class-7-chch-1-integers.json: CBSE Class 7 · Ch Ch 1 — Integers · 20 cards
- cbse-class-7-chch-5-lines-and-angles.json: CBSE Class 7 · Ch Ch 5 — Lines and Angles · 10 cards
- cbse-class-7-chch-13-exponents-and-powers.json: CBSE Class 7 · Ch Ch 13 — Exponents and Powers · 10 cards
- cbse-class-7-chch-12-algebraic-expressions.json: CBSE Class 7 · Ch Ch 12 — Algebraic Expressions · 10 cards
