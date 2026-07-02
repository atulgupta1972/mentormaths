# Maths Foundation — Product Plan

**Tagline:** Plan. Practice. Perform.

| Item | Value |
|------|--------|
| **Database** | `maths_foundation` |
| **Domain** | `mathsfoundation.in` |
| **App URL (production)** | `https://app.mathsfoundation.in` |
| **Marketing site** | `https://mathsfoundation.in` |
| **Project folder** | `C:\Users\Atul.Gupta\maths_foundation` |
| **Stack** | Laravel 12 + Vue 3 + Inertia + MySQL |

---

## Core concept (TrainingPeaks for Maths)

| TrainingPeaks | Maths Foundation |
|---------------|------------------|
| Coach plan | Syllabus plan |
| Daily workout | Topic worksheet / test |
| Mark complete (green) | Submit complete (green) |
| Race event | School exam |
| Performance stats | Score / time / progress |

---

## Modules

### 1. Student registration & access

- Registration fields: name, student mobile (optional), parent 1 mobile, parent 2 mobile
- Lower classes may have **no student mobile** — parent contact is primary
- School name, class/grade, board (CBSE / ICSE)
- **Academic year** is dynamic (e.g. 1 Mar 2026 → Feb 2027), dates editable per school exam calendar
- Active year vs past years; student/admin can view **historical class data**
- **Request → approve** flow (admin grants access)

**Flow:**
1. Public page: “Request Registration”
2. Status: `pending` → admin reviews → `approved` / `rejected`
3. On approve: create student, enrollment, login
4. Student sees only their data; admin sees all

### 2. Syllabus master (board-wise)

- Boards: **CBSE**, **ICSE**, etc.
- Per board + class + subject (start with **Mathematics**)
- Year-wise versions with **carry forward + edit**

**Excel columns** (`tests/CBSE_Class7_Maths_Syllabus.xlsx`):

| Excel column | System field |
|--------------|--------------|
| Chapter No. | `chapter_number` |
| Main Topic | `chapter_name` |
| Sub-Topic | `topic_name` |
| Key Concepts | `learning_outcomes` |
| Difficulty Level | `difficulty` (Easy/Medium/Hard) |
| Approx. Periods | `planned_periods` |
| Remarks | `remarks` |

**Hierarchy:**
```
Board → Class → Subject → Academic Year Syllabus Version
  → Chapters → Topics → (optional) Sub-concepts
```

### 3. Academic calendar & events

| Event type | Example |
|------------|---------|
| School unit test | 15 Apr 2026 — Ch 1–3 |
| Half-yearly exam | 15 Sep 2026 — Ch 1–8 |
| Final exam | Feb 2027 — Full syllabus |
| Internal worksheet week | Topic-wise practice |

### 4. Worksheets & online tests

- Topic-wise assignments (MCQ, short answer, numeric)
- Track: **score, time taken, attempts, completion date**
- Dashboard: green = done, yellow = in progress, grey = not assigned

### 5. Teacher / admin tests

- Teacher picks topics from syllabus → builds test
- Assign to student or batch
- Auto-grade where possible; manual for written steps

### 6. Performance visibility

- Student login: own scores, weak topics, calendar progress
- Parent login (optional): read-only — fees, attendance, results
- Admin: full analytics per student/class/year

### 7. Fees & attendance

- Fee plan per student/year
- Payments, dues, receipts
- Class attendance (present/absent/late)

---

## Database structure (high level)

```
academic_years          (name, start_date, end_date, is_active)
boards                  (CBSE, ICSE, ...)
grade_levels            (Class 1–12)
registration_requests   (pending approvals)
users                   (student, parent, teacher, admin)
students                (profile, school, parent contacts)
student_enrollments     (student + academic_year + class) ← history preserved

syllabus_versions       (board, class, subject, academic_year, status)
syllabus_chapters
syllabus_topics

calendar_events         (exam dates, type, linked topics)
worksheets / tests
test_questions
test_attempts           (score, time_seconds, completed_at)
attendance_records
fee_plans / fee_payments
```

**Design rule:** Never overwrite past year data. Always link records to `academic_year_id` + `enrollment_id`.

### Student promotion (same person, new class each year)

```
students              ← ONE row per person (name, parents, contacts — constant)
student_enrollments   ← ONE row per academic year
    2026-27: Class 7, CBSE, active → completed when promoted
    2027-28: Class 8, CBSE, active
```

- Profile (`students`) never duplicated
- Promote = new enrollment row + mark old year `completed`
- Bulk promote on **Years** page moves all students to next class
- Individual promote on **Students → detail** page

---

## Registration page fields

| Field | Required | Notes |
|-------|----------|-------|
| Student full name | Yes | |
| Date of birth | Optional | Helps verify age/class |
| Student mobile | No | Optional for Class 5+ |
| Parent 1 name | Yes | |
| Parent 1 mobile | Yes | Primary contact |
| Parent 2 name | No | |
| Parent 2 mobile | No | |
| School name | Yes | |
| Current class | Yes | For requested academic year |
| Board | Yes | CBSE / ICSE |
| Email | Optional | For login / notifications |
| Notes | No | e.g. “No own phone — use father's number” |

---

## Phased build plan

### Phase 1 — Foundation ✅ (in progress)
- Laravel project + DB `maths_foundation`
- Users, roles (admin, teacher, student, parent)
- Academic years (active/past)
- Registration request + approval
- Student profile + enrollment per year

### Phase 2 — Syllabus
- Board/class/subject master
- Excel import for CBSE Class 7 Maths
- Syllabus version + carry forward to next year

### Phase 3 — Calendar & events
- Exam/event calendar
- Link events to syllabus topics

### Phase 4 — Tests & worksheets
- Question bank
- Online tests (MCQ + numeric first)
- Attempt tracking: score, time, completion
- Student dashboard with green/yellow/grey

### Phase 5 — Fees & attendance
- Fee plans, payments, dues
- Class attendance

### Phase 6 — Polish
- Parent portal
- Reports & exports
- Notifications (SMS/WhatsApp)

---

## Additional features (later)

| Feature | Why |
|---------|-----|
| Parent login (read-only) | Parents need results + fees only |
| Weak topic auto-tag | Score < 60% → flag for revision |
| Revision plan generator | Before exam, suggest weak-topic worksheets |
| Question bank | Reuse questions; tag by topic + difficulty |
| PDF worksheet upload | Paper work; mark complete manually |
| Teacher notes per topic | “Common mistakes in integers” |
| Batch / small group | Group students for same schedule |
| Audit log | Who changed syllabus, fees, scores |
| Data export | Excel/PDF for parents |

---

## Hosting (15–20 students)

- **Recommended:** DigitalOcean Bangalore or Hetzner VPS (~₹600/month)
- Nginx + PHP 8.2+ + MySQL + Let's Encrypt SSL
- Optional: Laravel Forge for deploy automation

---

## URL structure

```
mathsfoundation.in          → Landing page (request registration)
app.mathsfoundation.in      → Student / teacher / admin login
```

---

*Last updated: June 2026*
