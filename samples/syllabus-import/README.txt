Mentor Maths — Syllabus Excel import format
==========================================

Use these templates to prepare CBSE Class 4 and Class 5 maths syllabus.
Search NCERT / CBSE official syllabus online, then fill one row per sub-topic.

Typical student age (CBSE, start of year)
-----------------------------------------
  Class 4 → age 9
  Class 5 → age 10
  Class 6 → age 11
  Class 7 → age 12
  (Class N → age N + 5)

Include age when asking Gemini / Claude to generate syllabus or MCQs so wording stays age-appropriate.

Files
-----
  CBSE_Class4_Maths_Syllabus_TEMPLATE.xlsx
  CBSE_Class5_Maths_Syllabus_TEMPLATE.xlsx

Excel columns (row 1 — do not rename)
-------------------------------------
  Chapter No.              e.g. 1, 2, 3
  Main Topic (Chapter)     chapter / unit name (same for all sub-topics in that chapter)
  Sub-Topic                one row per teachable sub-topic
  Key Concepts / Learning Outcomes   optional but recommended
  Difficulty Level         Easy | Medium | Hard (optional)
  Approx. Periods          number of periods (optional)
  Remarks                  notes, NCERT reference (optional)

Rules
-----
  • One row = one sub-topic.
  • Repeat Chapter No. + Main Topic for each sub-topic in the same chapter.
  • Leave Sub-Topic blank on a row only if that row starts a new chapter with no sub-topics yet.
  • Save as .xlsx (Excel). File must be under 10 MB.

Import in admin
----------------
  1. Admin → Syllabus  (/admin/syllabus)
  2. Expand "Optional: import from Excel"
  3. Board: CBSE | Class: Class 4 or Class 5 | Subject: Mathematics | Year: current
  4. Upload your filled .xlsx → Import

Or: Create & edit syllabus manually, then re-import on the syllabus editor page.

Regenerate blank templates
--------------------------
  php scripts/generate-syllabus-templates.php
