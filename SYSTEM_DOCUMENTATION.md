================================================================================
EXAM DASHBOARD — COMPLETE SYSTEM DOCUMENTATION
Capabilities, dashboards, workflows, and how every part connects
================================================================================

Document version : 1.0
Applies to       : ExamBoard_V3 (Laravel 12 + Inertia v3 + React 19)
Audience         : Administrators, teachers, and anyone deploying / operating
                   the platform. Written so a non-developer can understand what
                   each role can do and how the pieces fit together, with enough
                   technical depth for a developer to maintain it.

--------------------------------------------------------------------------------
HOW TO READ THIS DOCUMENT
--------------------------------------------------------------------------------
  Section 1  — The big picture (stack, roles, the one-paragraph mental model)
  Section 2  — Accounts, login, and how a user lands on the right dashboard
  Section 3  — THE CAPABILITY SYSTEM (what a teacher is allowed to do)
  Section 4  — THE ADMIN DASHBOARD (every page + every action)
  Section 5  — THE TEACHER DASHBOARD (every page + every action)
  Section 6  — THE STUDENT EXPERIENCE (the full exam-taking lifecycle)
  Section 7  — HOW EVERYTHING CONNECTS (the cross-cutting workflows)
  Section 8  — ANSWER DURABILITY (why no answer is ever lost)
  Section 9  — THE SCORING ENGINE (exactly how marks are calculated)
  Section 10 — AI QUESTION GENERATION (the full pipeline)
  Section 11 — SECURITY MODEL (cookies, encryption, anti-cheat)
  Section 12 — THE DATA MODEL (tables and how they relate)
  Section 13 — QUICK-REFERENCE TABLES (numbers, routes, glossary)


================================================================================
SECTION 1 — THE BIG PICTURE
================================================================================

WHAT THIS IS
  A complete online examination platform. Teachers build a question bank, compose
  exams (by hand or with AI), hand out access tokens, and watch students take the
  exam live. Students log in, redeem a token, answer questions in a timed window,
  and submit. The system auto-grades everything except essays, which teachers grade
  by hand. Admins oversee the whole school, manage teachers and students, and
  configure the AI.

THE TECHNOLOGY STACK
  - Backend  : PHP 8.2, Laravel 12
  - Bridge   : Inertia v3 (lets Laravel render React pages directly — no separate API)
  - Frontend : React 19, Vite 7, Tailwind 4, TypeScript
  - Database : MySQL 8 / MariaDB 10.5+
  - Auth     : Custom JWT cookies (firebase/php-jwt, HS256)
  - Excel    : phpoffice/phpspreadsheet (import/export)
  - Math     : KaTeX (rendering) + MathLive (input)

THE ONE-PARAGRAPH MENTAL MODEL
  Everything orbits the EXAM. A teacher fills a QUESTION BANK with questions tagged
  by subject/topic/difficulty. They assemble those questions into an EXAM and issue
  an ACCESS TOKEN. A student redeems the token, which starts an EXAM SESSION. As the
  student answers, every keystroke is saved three ways (browser, server, and a
  background safety-net). When they submit (or time runs out), the session becomes a
  SUBMISSION, which the SCORING ENGINE grades. Teachers then review submissions,
  grade essays, and export REPORTS. Admins see all of this across every teacher.

THE THREE ROLES AT A GLANCE
  ADMIN   — School-wide superuser. Sees and controls EVERY teacher's data. Manages
            accounts, grants teacher capabilities, configures AI, and can impersonate
            any teacher. Bypasses all capability checks.
  TEACHER — Owns their own content. Sees only what they created (their exams,
            students, bank questions, classes). What features they can use is
            controlled per-teacher by the admin through CAPABILITIES.
  STUDENT — Takes exams. Logs in, redeems a token, answers, submits, reviews scores.
            No capability system; students simply take exams when given a token.


================================================================================
SECTION 2 — ACCOUNTS, LOGIN, AND ROLE ROUTING
================================================================================

THE LOGIN PAGE  (GET / → POST /login)
  A single login form (username + password). There is no public self-registration —
  accounts are created by admins (teachers + students) or by teachers (their own
  students). On success the user is routed to their role's home:
      admin    → /admin
      teacher  → /teacher
      student  → /student

LOGIN SECURITY (enforced in AuthController)
  - Per-IP rate limit      : 1000 attempts / minute (env: LOGIN_IP_RATE_LIMIT)
  - Per-username rate limit : 10 attempts / minute
  - Account lockout         : after 5 failed attempts, locked for 15 minutes
  - Passwords               : verified with bcrypt; lockout state lives in
                              user_credentials (failed_attempts, locked_until)
  - Deactivated accounts    : cannot log in (active = false → rejected)

THE SESSION COOKIE
  On login the server issues a signed JWT cookie:
      Name     : secure-exam-session   (env: SESSION_COOKIE_NAME)
      Lifetime : 5 days                 (env: SESSION_COOKIE_DAYS, range 1–30)
      Signing  : HS256 with SESSION_SECRET
      Claims   : uid, role, iat, exp, tv (token_version), optional imp_uid
      Flags    : HttpOnly, Secure (in production), SameSite=Lax

LOGOUT & FORCED SIGN-OUT (the token_version trick)
  Logout increments the user's token_version in the database. Every request compares
  the cookie's "tv" claim to the live token_version. If they differ, the session is
  dead. This is how a single click can invalidate ALL of a user's logged-in devices —
  used by logout AND by "deactivate account" (admin/teacher deactivating a user
  instantly kicks them out everywhere).

KEEPING YOUR PLACE (no lost form state)
  The UI persists per-user interface state (open tabs, filters, tree expansions,
  half-filled form values) in the browser under a key namespaced by username
  (ui_state::<username>::<key>). Signing out and back in restores exactly where you
  were, and two users on the same computer never see each other's state.


================================================================================
SECTION 3 — THE CAPABILITY SYSTEM  (the heart of "what a teacher can do")
================================================================================

WHAT CAPABILITIES ARE
  A capability is a single on/off permission attached to ONE teacher. Teachers start
  with EVERYTHING OFF. The admin turns on exactly the features each teacher should
  have. Two teachers can have completely different permission sets. This is what lets
  a school give a senior teacher full AI + curriculum powers while a junior teacher
  can only edit question text.

  Stored as a JSON map on the user record (users.capabilities). Missing key = OFF.
  Admins do NOT have capabilities — they bypass every check and can do everything.

THE FOUR CAPABILITY GROUPS
  1. ai           — AI features (the on/off switches for big features)
  2. ai_param     — AI generation parameters (what knobs the teacher may turn when
                    generating questions with AI)
  3. exam_config  — Exam configuration (which exam settings the teacher may change)
  4. exam_param   — Exam parameters (which question types/difficulties/media the
                    teacher may use when composing an exam by hand)

THE COMPLETE LIST — ALL 39 CAPABILITY KEYS
  (exact key strings; this is the authoritative list from app/Support/Capabilities.php)

  GROUP "ai" — big feature switches
    ai.generate ................. Open the AI generator and build/run AI prompts.
    curriculum.manage ........... Upload & manage the Learning-Objective catalog and
                                  use it as content scope in AI generation.

  GROUP "ai_param" — knobs on the AI generator
    Scope:
      ai.param.language ......... May change the generation language (else locked to default).
      ai.param.subject .......... May change the subject (else locked to their subject).
    Difficulty (Bloom's revised taxonomy — replaces old easy/medium/hard/hots):
      ai.param.difficulty.remember ..... Bloom 1: recall facts/terms.
      ai.param.difficulty.understand ... Bloom 2: explain / paraphrase.
      ai.param.difficulty.apply ........ Bloom 3: use in a new situation.
      ai.param.difficulty.analyze ...... Bloom 4: break apart / compare.
      ai.param.difficulty.evaluate ..... Bloom 5: judge / justify.
      ai.param.difficulty.create ....... Bloom 6: design / construct.
      ai.param.difficulty.olympiad ..... Contest-level (beyond the taxonomy).
    Question type:
      ai.param.type.single ...... May request single-choice questions.
      ai.param.type.multi ....... May request multiple-select questions.
      ai.param.type.short_text .. May request short-text questions.
      ai.param.type.numeric ..... May request numeric questions.
      ai.param.type.essay ....... May request essay / structured questions.
    Media:
      ai.param.media.image ...... May ask AI to include image suggestions.
      ai.param.media.table ...... May ask AI to include tables.

  GROUP "exam_config" — which exam settings the teacher may edit
    exam.config.duration ........... Set the exam duration (minutes).
    exam.config.passingGrade ....... Set the passing grade (%).
    exam.config.mode ............... Switch between Strict and Try-Out mode.
    exam.config.shuffleQuestions ... Toggle question shuffling.
    exam.config.shuffleOptions ..... Toggle answer-option shuffling.
    exam.config.language ........... Set the exam language (manual editor).
    exam.config.seb ................ Require Safe Exam Browser.

  GROUP "exam_param" — which building blocks the teacher may use when composing
    Question type:
      exam.param.type.single ........ Use single-choice in the composition targets.
      exam.param.type.multi ......... Use multiple-select.
      exam.param.type.short_text .... Use short-text.
      exam.param.type.numeric ....... Use numeric.
      exam.param.type.essay ......... Use essay / structured.
    Difficulty (Bloom's revised taxonomy):
      exam.param.difficulty.remember ..... Use "Remember" in the difficulty mix.
      exam.param.difficulty.understand ... Use "Understand".
      exam.param.difficulty.apply ........ Use "Apply".
      exam.param.difficulty.analyze ...... Use "Analyze".
      exam.param.difficulty.evaluate ..... Use "Evaluate".
      exam.param.difficulty.create ....... Use "Create".
      exam.param.difficulty.olympiad ..... Use "Olympiad".
    Media:
      exam.param.media.image ........ Target image questions in the composition.
      exam.param.media.table ........ Target table questions in the composition.

HOW A CAPABILITY IS ENFORCED (defense in depth — checked in BOTH places)
  1. In the UI (so disabled features are greyed out / hidden):
     The server sends each teacher their capability map (via Inertia). The exam form,
     the AI generator, etc. hide or disable any control the teacher lacks.
  2. On the server (so the UI can't be bypassed):
     - ai.generate is checked in AiGenerateController before the page or any run.
     - curriculum.manage is checked in LearningObjectiveController on every LO action.
     - exam.config.* are re-checked field-by-field in ExamManageController when an
       exam is created or saved. If a teacher lacks a field's capability, that field
       is simply skipped (on edit it keeps its old value; on create it uses a default).
  In every check the rule is the same three steps:
       (a) admin?  -> allow everything, stop.
       (b) not a teacher?  -> 403 "requires a teacher account".
       (c) teacher missing the capability?  -> 403 "feature disabled for your account".

HOW AN ADMIN GRANTS / REVOKES CAPABILITIES
  Admin → Teachers page → click a teacher's "Capabilities" editor. The capabilities
  are shown grouped (AI features / AI parameters / Exam configuration / Exam
  parameters) with human labels. Toggling sends:
       PATCH /admin/teachers/{uid}/capabilities   { "capabilities": { key: bool, ... } }
  The server validates every key against the known list (unknown keys rejected) and
  stores the result. The response returns the full, authoritative on/off map.


================================================================================
SECTION 4 — THE ADMIN DASHBOARD
================================================================================
All admin routes live under /admin and require the admin role. Admins see SCHOOL-WIDE
data with no ownership filtering. Most analytics pages have an optional "teacher
scope" picker (?teacherId=) to narrow the view to one teacher.

4.1  DASHBOARD (landing)          GET /admin        page: admin/Dashboard.tsx
  Six metric cards in two clusters:
     People  : Total teachers | Active teachers (+ disabled count) | Total students
     Content : Total exams | Total submissions | Bank questions
  Three "recent activity" feeds (latest 10 each, across the whole school):
     - Recent submissions (student, exam, score, pass/fail/pending, time)
     - Recent access tokens (code, exam, class, uses, status)
     - Recent classes (name, academic year, student count, created)

4.2  TEACHER MANAGEMENT            GET /admin/teachers   page: admin/Teachers.tsx
  A table of every teacher (active first, then alphabetical) with their exam count,
  student count, bank-question count, and submission count. Actions:
     - Add teacher        : POST /admin/teachers (username, full name, password, subject)
     - Reset password     : PATCH /admin/teachers/{uid} — shows the new plaintext to copy
     - Activate/deactivate: PATCH — deactivation force-logs-out the teacher instantly
     - Edit capabilities  : PATCH /admin/teachers/{uid}/capabilities (see Section 3)
     - Delete teacher     : DELETE /admin/teachers/{uid}
     - Impersonate        : POST /admin/impersonate/{uid} (see 4.12)
  Filters: A–Z letter filter, and subject filter.

4.3  STUDENT MANAGEMENT            GET /admin/students   page: admin/Students.tsx
  Every student in the school, grouped by class (plus a "No class" bucket). Each
  group shows class name, academic year, student count, and source file. Per student:
  active state, total submissions, last submission, and the (decrypted) password.
  Actions:
     - Per-student      : reset password, activate/deactivate, delete
     - Bulk (checkbox)  : POST /admin/students/bulk — reset / activate / deactivate / delete
     - Credentials panel: after a bulk reset, download a CSV of username+password to hand out
  Deactivating bumps token_version (instant force-logout). Deleting cascades to that
  student's submissions.

4.4  EXAMS — LIST                 GET /admin/exams      page: admin/Exams.tsx
  Every teacher's exams, newest first, with owner, code, active tokens (as pills),
  duration, passing grade, submission count, average %, and passed count. Actions:
     - Create exam            : GET /admin/exams/new → POST /admin/exams
     - Import exam package     : POST /admin/exams/import (a .zip or .json package)
     - Per-token              : regenerate (POST .../tokens/{id}/regenerate) or delete
     - Delete exam            : DELETE /admin/exams/{id} (cascades questions/tokens/submissions)

4.5  EXAMS — LAUNCHPAD            GET /admin/all-exams  page: admin/AllExams.tsx
  A teacher-scoped view: pick a teacher, see their exam metrics, and jump straight to
  Live monitor, Answer audit, or Manage for any exam.

4.6  EXAM DETAIL (full editor)    GET /admin/exams/{id} (reuses teacher/ExamDetail.tsx)
  The complete authoring surface (same as the teacher's — see 5.4c) but unrestricted:
  metadata, the question list (add inline / add from bank / replace / auto-fill),
  tokens, SEB, submissions, and settings. Edit settings: GET /admin/exams/{id}/edit.

4.7  LIVE MONITOR                 GET /admin/exams/{id}/live   page: admin/ExamLive.tsx
  A real-time board (auto-refreshes every 7 seconds via /live-scores JSON). Shows each
  student's status (in progress / submitted / expired), answered count, live auto-score,
  pending essay points, time remaining, last save, and anti-cheat event count. Nothing
  is written — it is read-only observation.

4.8  ANSWER AUDIT                 GET /admin/exams/{id}/audit  page: admin/ExamAudit.tsx
  A data-integrity tool. For every session it lines up the raw saved answers
  (answer_drafts) against the snapshot the scoring used (answers_snapshot) and flags
  any MISMATCH in red. Expand a student to see a per-question raw-vs-snapshot diff.
  This is the forensic proof that no answer was dropped.

4.9  QUESTION BANK (school-wide)  GET /admin/bank       page: admin/Bank.tsx
  Same bank UI as the teacher (Section 5.2) but showing EVERY teacher's questions. The
  collapsible tree is Subject → Topic → Subtopic → Difficulty → Media. Full create /
  edit / delete and bulk import (.zip / .xlsx / .json).

4.10 CURRICULUM / LEARNING OBJECTIVES   GET /admin/learning-objectives
  Four curriculum tabs (Kurikulum Merdeka, AS/A Level, IB, Olympiad). Each shows a
  Topic → Subtopic → Objective tree, inline add/edit/delete, bulk delete, and a
  two-phase Excel import (upload → preview → confirm).

4.11 SCORES & GRADING
  - Scores overview   GET /admin/scores            page: admin/Scores.tsx
        A Teacher → Exam → Class → Student tree with stats at every level, including a
        "Not submitted" row that lists roster students who never sat the exam (with
        their session diagnostics). Bulk-delete submissions from here.
  - Score detail      GET /admin/scores/{id}       page: admin/ScoreDetail.tsx
        The full per-question breakdown for one submission, the topic breakdown, the
        anti-cheat timeline, and the ESSAY GRADING controls (enter a mark 0..max +
        feedback, POST /admin/scores/{id}/grade). The final score recomputes live.
  - Auto score        GET /admin/auto-score        page: admin/AutoScore.tsx
        Auto-graded portion only (everything except essays).
  - Pending score     GET /admin/pending-score     page: admin/PendingScore.tsx
        Only submissions with ungraded essays, rolled up per teacher so admins can
        see who has grading backlog.

4.12 REPORTS                      GET /admin/reports    page: admin/Reports.tsx
  A per-class student × exam achievement matrix with toggleable columns (per-exam
  scores, average, passed/taken, pending, strongest/weakest topic). Export to a
  styled Excel workbook (POST /admin/reports/export) — colour-coded pass/fail cells,
  one sheet per class.

4.13 ANALYZE (system analytics)   GET /admin/analyze    page: admin/Analyze.tsx
  Two tabs:
     Dashboard tab (9 sections): system counts; submissions-per-day (30-day chart);
        pass rate per exam; top-10 and bottom-10 scorers; score distribution (10
        buckets); strongest/weakest topics; bank composition (by subject/type/
        difficulty + most-used/unused); grading workload per teacher.
     Item Analysis tab: per-question statistics for each exam — response count,
        correct rate, difficulty index, and option distribution for multiple choice.

4.14 AI SETTINGS                  GET /admin/ai-settings  page: admin/AiSettings.tsx
  Three sections:
     - API keys     : paste/clear keys for Gemini, Claude, OpenAI (stored ENCRYPTED;
                      empty falls back to the matching environment variable).
     - Text gen     : choose provider (Pollinations / Gemini / Claude / OpenAI), model,
                      and temperature (0.0–2.0).
     - Image gen    : choose Off / Pollinations / Gemini / OpenAI.
  PUT /admin/ai-settings and PATCH /admin/ai-settings/keys persist these.

4.15 AI GENERATE                  GET /admin/ai-generate  page: admin/AiGenerate.tsx
  The same AI question generator teachers use (Section 10), available to the admin
  with all parameters unlocked.

4.16 IMPERSONATION
  POST /admin/impersonate/{uid} starts impersonation: the admin is handed a teacher
  session (the JWT records imp_uid = admin's id) and lands on /teacher seeing exactly
  what that teacher sees. A "Stop impersonation" control (POST /impersonate/stop)
  restores the admin session. Only ACTIVE TEACHER accounts can be impersonated.


================================================================================
SECTION 5 — THE TEACHER DASHBOARD
================================================================================
All teacher routes live under /teacher and require the teacher role. A teacher sees
ONLY their own content (created_by / uploaded_by = their id). Which features and
fields they can use is governed by their CAPABILITIES (Section 3).

5.1  DASHBOARD (landing)          GET /teacher          page: teacher/Dashboard.tsx
  Five metric cards: Exams, Total submissions, Passed, Awaiting grading, Students.
  Plus a "recent submissions" table (latest 10: student, exam, score %, result, time).

5.2  QUESTION BANK                GET /teacher/bank     page: teacher/Bank.tsx
  The reusable library of questions. Organized as a 5-LEVEL TREE:
       Subject → Topic → Subtopic → Difficulty → Media type
  A 6-filter bar (Language, Subject, Topic, Subtopic, Difficulty, Type) plus a search
  box narrows the tree. The teacher sees only questions they uploaded.

  Difficulty uses BLOOM'S REVISED TAXONOMY (7 levels):
       remember, understand, apply, analyze, evaluate, create, olympiad

  The 5 QUESTION TYPES:
       single_choice  — pick one correct option
       multi_select   — pick one or more (partial credit on scoring)
       short_text     — typed string, matched case-insensitively
       numeric        — a number, matched with tolerance bands (partial credit)
       essay          — open response, graded by hand

  Actions:
     - Create one      : POST /teacher/bank (type, prompt, topic, subtopic, difficulty,
                         points, options, correct answer, explanation/mark scheme)
     - Edit one        : PUT /teacher/bank/{id}   (type is fixed; everything else editable)
     - Delete one      : DELETE /teacher/bank/{id} (does NOT touch exams already using it)
     - Bulk import     : POST /teacher/bank/upload — a .zip (questions.json or .xlsx +
                         an optional media/ folder), a bare .xlsx, or a .json file.
                         Media is matched by filename and inlined.

5.3  EXAMS — LIST                 GET /teacher/exams    page: teacher/Exams.tsx
  The teacher's exams, each with code, name, duration, passing grade, active toggle,
  active-token pills (use counts + expiry, with regenerate/delete), and live stats
  (submissions, average %, passed). Token codes are decrypted server-side for display.

5.4  EXAM AUTHORING
  (a) CREATE EXAM    GET /teacher/exams/new → POST /teacher/exams   page: ExamCreate.tsx
        Fields (gated ones only appear if the capability is on):
          - Exam code (unique, UPPERCASE + digits + dashes)
          - Name
          - Duration minutes        [cap: exam.config.duration]
          - Passing grade %         [cap: exam.config.passingGrade]
          - Exam mode strict/try_out[cap: exam.config.mode]
          - Shuffle questions       [cap: exam.config.shuffleQuestions]
          - Shuffle options         [cap: exam.config.shuffleOptions]
          - Language                [cap: exam.config.language]
          - Subject
          - Start / End time (scheduling window, optional)
          - SECURITY: Require Safe Exam Browser  [cap: exam.config.seb]
          - Composition targets:
              * Type distribution        [caps: exam.param.type.*]
              * Difficulty distribution  [caps: exam.param.difficulty.*] (percentages summing to 100)
              * Media targets            [caps: exam.param.media.*]
        (Note: the old "General instructions" textbox was removed; the Security panel
        now occupies that position on the form.)
  (b) EDIT SETTINGS  GET /teacher/exams/{id}/edit → PATCH /teacher/exams/{id}
        The same form pre-filled. Fields the teacher lacks capability for are skipped
        on save (their existing value is preserved).
  (c) EXAM DETAIL    GET /teacher/exams/{id}      page: teacher/ExamDetail.tsx
        The main authoring surface. A left panel shows metrics; the body has:
          - QUESTIONS: the ordered question list. Add a question inline
            (POST .../questions), ADD FROM BANK via a filtered multi-select picker
            (POST .../questions/from-bank), edit inline (PATCH .../questions/{qid}),
            delete (re-densifies positions), or REPLACE a question with a bank match
            (POST .../questions/{qid}/replace — auto-pick by difficulty/topic, or manual).
          - AUTO-FILL: if composition targets are set, top the exam up from the bank to
            hit the type×difficulty matrix (POST .../auto-fill, returns a fill matrix).
          - TOKENS: create (max uses + expiry), regenerate, delete. The plaintext code
            is shown ONCE at creation; only an encrypted preview is stored.
          - SEB: toggle Safe Exam Browser and rotate the 24-char secret.
          - SUBMISSIONS: every submission for this exam, link through to grading.
          - Also: finalize abandoned drafts, reset a "did-not-attempt" student's session,
            delete the exam (cascades).

5.5  CLASSES & STUDENTS           GET /teacher/students  page: teacher/Students.tsx
  The teacher's own students, grouped by class. Per student: active state, total
  submissions, last submission, decrypted password, and reset/activate/delete.
  Building rosters:
     - Add one student         : POST /teacher/students
     - Bulk create from paste   : POST /teacher/students/bulk-create (one per line)
     - Import classes from file : POST /teacher/classes/parse (preview) then
                                  POST /teacher/classes/import (commit). One worksheet =
                                  one class; columns auto-detected (name, username, password).
     - Bulk actions             : POST /teacher/students/bulk (activate/deactivate/reset/delete)
  Passwords are stored two ways: a bcrypt hash for login, and an AES-encrypted
  plaintext so the teacher can re-display/hand out credentials.

5.6  LEARNING OBJECTIVES          GET /teacher/learning-objectives   [cap: curriculum.manage]
  The teacher's curriculum catalog: rows of (curriculum, language, subject, topic,
  subtopic, objective text). Four curricula: kurikulum_merdeka, as_a_level, ib,
  olympiad. Inline add/edit/delete, bulk delete, and a two-phase Excel import
  (upload → preview with warnings → confirm). Catalogs are PER-TEACHER: two teachers
  can each import the same curriculum independently without colliding. These objectives
  become selectable "content scope" in the AI generator.

5.7  AI GENERATE                  GET /teacher/ai-generate  [cap: ai.generate]   (Section 10)

5.8  SCORES & GRADING
  - Scores tree       GET /teacher/scores         page: teacher/Scores.tsx
        Exam → Class → Student with per-level stats and checkbox bulk-delete.
  - Grade one         GET /teacher/scores/{id}    page: teacher/Grade.tsx
        Per-question breakdown; for each essay, enter a mark + feedback
        (POST /teacher/scores/{id}/grade). Score, percent, topic breakdown, and pass
        status recompute immediately.
  - Auto score        GET /teacher/auto-score     (auto-graded portion only)
  - Pending score     GET /teacher/pending-score  (ungraded essays + AI-grading export
        bundles you can paste into an AI and re-import via POST /teacher/grade-bulk)

5.9  REPORTS                      GET /teacher/reports    page: teacher/Reports.tsx
  A student × exam matrix for the teacher's classes with toggleable columns and an
  Excel export (POST /teacher/reports/export): one sheet per class, frozen styled
  header, auto-fit columns, per-student aggregates (taken, passed, pending, average,
  strongest/weakest topic).


================================================================================
SECTION 6 — THE STUDENT EXPERIENCE (full exam-taking lifecycle)
================================================================================

STEP 1 — LOG IN  (/)
  Student signs in and lands on the STUDENT HUB (/student).

STEP 2 — THE HUB  (GET /student, page student/Hub.tsx)
  Two things are shown:
     - RESUME BANNER: if the student has an in-progress attempt, a prominent
       "Resume <exam>" card appears at the top showing time remaining and "all your
       saved answers are preserved." (See Step 6.)
     - Two action cards: "Take an exam" (→ /token) and "View my scores" (→ /student/scores).
       Plus the 5 most recent scores.

STEP 3 — REDEEM A TOKEN  (GET /token → POST /token)
  The student types the access token the teacher gave them. The server (ExamAccessController):
     - Rate-limits to 15 tries / minute.
     - Hashes the token and looks up its digest (plaintext is never stored).
     - Checks the token is active, not expired, and not used up (used_count < max_uses).
     - Checks the exam is active and within its start/end window.
     - STRICT MODE GATE: if the exam is strict and the student already submitted, they
       are sent to their score instead (one attempt only).
     - Redeems idempotently (a deadlock-safe transaction; the same student re-redeeming
       doesn't double-count a use).
     - Issues the EXAM-ACCESS COOKIE (see below) and redirects to the exam.

  THE EXAM-ACCESS COOKIE
     Name     : secure-exam-access
     Lifetime : 8 hours
     Claims   : userId, examId, tokenId, scope="exam_access"
  This cookie — separate from the login cookie — is what unlocks the exam page.

STEP 4 — THE EXAM PAGE LOADS  (GET /exams/{examId}, page exam/Take.tsx)
  The server (ExamController::show):
     - Verifies the exam-access cookie (admins bypass).
     - Finds the student's existing DRAFT session, or creates a new one with
       started_at = now() and a unique resume_token (a UUID).
     - Computes time remaining = duration×60 − elapsed-since-started_at.
     - If already expired, it auto-finalizes on the spot (scores what's there) and
       sends the student to their result.
     - Picks the questions: essays always come last; if shuffle is on, non-essay
       questions (and, separately, each question's options) are shuffled with a SEED
       derived from the session id — so the order is stable for that student across
       refreshes and reviews, but differs between students.
     - Loads any answers already saved on the server (answer_drafts) and hands them to
       the page, which merges them with the browser's local copy.

STEP 5 — ANSWERING (durability is covered fully in Section 8)
  Each question renders by type. The timer counts down in the header (turns red under
  60 seconds). Every keystroke is saved to the browser instantly; a background save to
  the server runs every 5 seconds and on page-hide. General instructions, if the exam
  has any, show above the first question.

STEP 6 — INTERRUPTION & RESUME  (the resume token)
  If the student refreshes, loses network, closes the tab, or their 8-hour access
  cookie simply expires, they are NOT back to zero:
     - While the access cookie is still alive, reopening /exams/{code} finds the same
       draft session and restores every answer.
     - If the access cookie has expired, the HUB shows the Resume card, which links to
            GET /exams/{examId}/resume/{resumeToken}
       The server verifies the token belongs to THIS student (a leaked link is useless
       to anyone else), that the attempt is still a draft, and that the exam code
       matches — then mints a FRESH 8-hour access cookie and drops them back in with
       all answers intact and the timer continuing from real elapsed time.

STEP 7 — SUBMIT (or auto-submit)
  - Manual: the student clicks Submit (with a confirm). The page first flushes all
    pending answers, then POSTs the full answer snapshot. The submit retries up to 4
    times with backoff (0.8s, 1.8s, 4s, 8s); if it still fails, a recovery banner keeps
    retrying every 10 seconds and on network reconnect. Local answers are cleared ONLY
    after the server confirms.
  - Automatic at time-up: when the countdown hits zero the page auto-submits (no
    confirm) through the exact same durable path.
  - Server safety net: a scheduled job (exams:finalize-expired, every minute) sweeps
    any draft whose time has run out and finalizes it from the saved drafts — so even a
    student who closed their laptop gets scored and never loses answers.

STEP 8 — RESULTS  (GET /results/{submissionId}, page results/Show.tsx)
  Immediately after submit the student sees their result, split into:
     - Auto-graded portion: scored instantly (everything except essays).
     - Essay portion: shown as "pending" until the teacher grades it.
  Overall PASS/FAIL (pass needs percent ≥ passing grade AND no pending essays), plus a
  per-topic breakdown. In TRY-OUT mode a "Review exam" button opens the full per-question
  review; in STRICT mode per-question review is withheld (only totals + topic scores).

STEP 9 — MY SCORES  (GET /student/scores, page student/Scores.tsx)
  A history of every submission (newest first): exam, started, submitted, score (raw +
  %), status (Passed / Not passed / Pending), and a Review link.

  THE REVIEW PAGE  (GET /student/scores/{id})
     Full per-question review with the student's answer, the correct answer, the
     explanation, and per-topic breakdown. AN ANTI-LEAK GATE protects the answer key:
     if the student still has an active draft attempt on the same exam and the exam
     window hasn't closed, review is blocked until they finish — so they can't open one
     attempt to read answers for another.

STRICT vs TRY-OUT (summary)
  STRICT  : one attempt; re-redeeming or reopening sends you to your existing score;
            per-question review withheld.
  TRY-OUT : multiple attempts allowed; full per-question review available.


================================================================================
SECTION 7 — HOW EVERYTHING CONNECTS (the cross-cutting workflows)
================================================================================
This section is the "many things connected to each other" map. Each workflow shows
how data flows between roles, pages, and tables.

7.1  THE CORE EXAM LIFECYCLE (end to end)
  Teacher builds bank questions ─┐
                                 ▼
  Teacher composes an EXAM (by hand from the bank, by AI, or auto-fill) 
                                 ▼
  Teacher issues an ACCESS TOKEN (optionally class-scoped, with max uses + expiry)
                                 ▼
  Student redeems token ──► EXAM-ACCESS COOKIE ──► EXAM SESSION (draft) created
                                 ▼
  Student answers ──► answer_drafts (saved continuously) 
                                 ▼
  Submit / time-up / finalizer ──► EXAM SUBMISSION (answers_snapshot frozen)
                                 ▼
  SCORING ENGINE grades it ──► final_score, percent, pass, topic_breakdown, pending essays
                                 ▼
  Teacher grades essays ──► score recomputes ──► REPORTS / ANALYZE roll it up
                                 ▼
  Student sees RESULT + (try-out) REVIEW;  Admin sees everything school-wide.

7.2  THE QUESTION BANK ↔ EXAM RELATIONSHIP (important nuance)
  When a teacher adds a bank question to an exam, the exam gets its OWN COPY (exam_questions
  carries a soft reference source_bank_question_id, not a hard link). Therefore:
     - Editing or deleting a bank question does NOT change exams already built from it.
     - Each exam is a self-contained snapshot — safe to hand out and grade forever.
  "Replace from bank" and "Auto-fill" use the bank to find matching questions by
  difficulty/topic/type, but always copy them in.

7.3  CURRICULUM (LEARNING OBJECTIVES) ↔ AI GENERATION
  A teacher with curriculum.manage uploads Learning Objectives. In the AI generator they
  can pick a curriculum + subject and select specific objectives. The prompt builder then
  forces the AI to cover each selected objective, applying mixing rules for essays (see
  Section 10). This is how an exam is tied to a real syllabus.

7.4  CAPABILITIES ↔ EVERY TEACHER SURFACE
  The admin's capability toggles (Section 3) ripple everywhere: which fields render on the
  exam form, whether the AI generator opens at all, which difficulty/type/media knobs are
  available, and whether the curriculum and SEB features exist for that teacher. Change a
  capability and the teacher's UI changes on their next page load — and the server enforces
  the same rules even if someone crafts a request by hand.

7.5  TOKENS ↔ SESSIONS ↔ SUBMISSIONS ↔ SCORES
  A TOKEN redemption starts a SESSION. A SESSION accumulates answer_drafts and (on
  finish) yields exactly one SUBMISSION. SUBMISSIONS feed every scores/reports/analyze
  view. The Answer Audit (4.8) cross-checks drafts against the submission snapshot to
  prove integrity.

7.6  ADMIN OVERSIGHT ↔ TEACHER OWNERSHIP
  Teachers are scoped to created_by/uploaded_by = themselves. Admin pages drop that
  filter and add an optional teacher picker, so the SAME underlying pages and data serve
  both "my stuff" (teacher) and "everyone's stuff, optionally filtered" (admin). The
  ExamDetail page is literally shared between the two consoles.

7.7  IMPERSONATION (admin → teacher)
  Admin impersonation issues a teacher session tagged with the admin's id. The admin then
  experiences the exact teacher console — useful for support, for verifying a teacher's
  capability set, or for building content on their behalf — then steps back to admin.

7.8  THE AI CONFIG ↔ AI GENERATION ↔ BANK/EXAM
  Admin sets the active AI provider/model/keys (4.14). When any teacher/admin runs the
  generator (Section 10), the server uses that config, parses the AI's questions, and
  writes them into the BANK (and optionally straight into an EXAM). From there they flow
  through the normal lifecycle.


================================================================================
SECTION 8 — ANSWER DURABILITY (why no answer is ever lost)
================================================================================
This is the system's most important guarantee. There are FOUR independent layers; an
answer must slip past ALL of them to be lost, which is effectively impossible.

LAYER 1 — THE BROWSER (instant, survives crash/refresh)
  Every keystroke writes the full answer set to localStorage SYNCHRONOUSLY, before React
  even updates the screen. Key: exam_draft::<sessionId>. If the browser crashes 1ms after
  a keystroke, the answer is already saved locally. On reload the page merges localStorage
  OVER the server's copy (local wins), so anything typed during an outage is recovered.
  Stale local drafts older than 24 hours are pruned automatically.

LAYER 2 — THE SERVER DRAFT (every 5 seconds + on page-hide)
  A background timer flushes changed answers to the server every 5 seconds via
  PUT /api/exams/{examId}/draft, which UPSERTS into answer_drafts (unique per
  session+question). It also flushes on visibilitychange, pagehide, and beforeunload —
  so switching tabs, navigating away, or closing the browser all trigger a final save.

LAYER 3 — THE DURABLE SUBMIT (retry + recovery banner)
  Submit first flushes all drafts, then POSTs the FULL answer snapshot with keepalive
  (so it survives a tab close). It retries 4 times with backoff (0.8s, 1.8s, 4s, 8s). If
  all fail, a recovery banner appears and keeps retrying every 10 seconds and whenever the
  network reconnects. Local answers are cleared ONLY after the server confirms a
  submissionId.

LAYER 4 — THE SERVER SAFETY NET (the finalizer cron)
  A scheduled command, exams:finalize-expired, runs EVERY MINUTE. It finds any draft
  session whose time is up (duration + 60s grace) and finalizes it from the saved drafts —
  scoring it and recording an "auto_submitted_timeout" event. This catches the student who
  closed their laptop, lost power, or whose tab was killed. It is idempotent and
  race-safe: a session is finalized exactly once even under concurrency.

WORKED CASES
  - Refresh / crash mid-question .... Layer 1 restores from localStorage.
  - Network drops while typing ...... Layer 1 holds it; Layer 2 retries when back online.
  - Answers in the final seconds .... Submit flushes first, so even un-autosaved answers go.
  - Submit hits a network error ..... Layers 1+3: local copy intact, banner keeps retrying.
  - Student never clicks submit ..... Layer 4: finalizer scores the saved drafts within a minute.
  - 2000 students submit at once .... Deadlock-safe transactions + idempotent finalize; verified.


================================================================================
SECTION 9 — THE SCORING ENGINE (exactly how marks are calculated)
================================================================================
Implemented in app/Support/Scoring.php. All text is trimmed and compared
case-insensitively. Each question awards between 0 and its full "points".

single_choice
  Exact match → full points, otherwise 0. No partial credit.

multi_select  (PARTIAL CREDIT)
  ratio = (correctly-picked − wrongly-picked) / (total correct options), clamped to [0,1]
  award = points × ratio.
  Example: 3 correct options exist; student picks 2 correct + 1 wrong → (2−1)/3 = 0.33 → 33%.

short_text
  Case-insensitive, trimmed exact match → full points, else 0.

numeric  (PARTIAL CREDIT via tolerance bands)
  Exact (|diff| ≤ 1e-6) → 100%. Otherwise:
     If the correct answer is non-zero, by relative error:
        ≤ 1%  → 80% credit
        ≤ 5%  → 50% credit
        ≤ 10% → 20% credit
        > 10% → 0
     If the correct answer is exactly 0, by absolute difference:
        ≤ 0.001 → 80%,  ≤ 0.01 → 50%,  ≤ 0.1 → 20%,  else 0.

essay  (MANUAL)
  Needs a teacher mark. If a manual score exists, award = clamp(mark, 0, points). If not,
  the item is flagged requiresGrading and counts toward pendingEssayCount (the submission
  shows "pending" and cannot be "passed" until graded).

AGGREGATE RESULTS (per submission)
  finalScore        = sum of awarded points (2 dp)
  possibleScore     = sum of all points (2 dp)
  percentScore      = finalScore / possibleScore × 100 (0 if no points)
  pendingEssayCount = number of ungraded essays
  passed            = percentScore ≥ exam.passing_grade  AND  pendingEssayCount = 0
  topicBreakdown    = per topic: earned, possible, percent, correct count, total count
  Scores are recomputed on every view (and after each essay grade), always consistent
  with the frozen answers_snapshot.


================================================================================
SECTION 10 — AI QUESTION GENERATION (the full pipeline)
================================================================================
Open at /teacher/ai-generate (cap: ai.generate) or /admin/ai-generate.

THE PARAMETERS (each gated by the matching ai.param.* capability)
  Scope    : language, subject, topic, subtopic, grade level
  Volume   : total count (1–500)
  Types    : per-type target counts (single/multi/short_text/numeric/essay)
  Difficulty: percentage mix across the 7 Bloom levels (summed to 100, then allocated to
             exact counts by the Hare–Niemeyer method)
  Olympiad : intensity (intro / moderate / extreme) when olympiad > 0
  Media    : image count, table count
  Curriculum (cap: curriculum.manage): pick a curriculum + subject + specific Learning
             Objectives the questions must cover
  Extras   : free-text instructions, source URLs to study first

TWO WAYS TO RUN
  1. Auto-generate: POST /teacher/ai-generate/run — the server calls the configured AI
     provider, parses + validates the returned questions, writes them into the BANK (and
     optionally appends to an exam), and can generate images if an image provider is set.
  2. Download the prompt: build the prompt and run it in your own AI (ChatGPT/Claude),
     then import the resulting questions.json back through the bank uploader.

THE MIXED-TOPIC ESSAY RULE
  When fewer questions are requested than there are selected Learning Objectives, essays/
  structured questions are made to span multiple objectives, preferring objectives that
  share a subtopic, then the same topic, then across topics — so every objective is
  covered even with a small question count.

THE PROVIDERS (configured by the admin in AI Settings)
  Text : Pollinations (keyless default), Google Gemini, Anthropic Claude, OpenAI
  Image: Off, Pollinations (Flux), Gemini (Imagen), OpenAI (DALL·E/gpt-image)
  Keys are resolved DB-first (encrypted) then environment variable; Pollinations needs no key.


================================================================================
SECTION 11 — SECURITY MODEL
================================================================================

TWO JWT COOKIES (both HS256, signed with SESSION_SECRET)
  secure-exam-session : the login session. 5-day life. Claims uid, role, tv (token_version).
  secure-exam-access  : unlocks one exam. 8-hour life. Claims userId, examId, tokenId, scope.

SESSION REVOCATION
  Every request checks the cookie's tv against the user's live token_version. Logout and
  "deactivate account" both bump token_version, instantly invalidating every existing
  session for that user across all devices.

ENCRYPTION AT REST (AES-256-GCM, domain-tagged from SESSION_SECRET)
  Three kinds of secret are encrypted in the database, each under its own domain key:
     - Student plaintext passwords (so teachers can re-issue them) — user_credentials.password_plain
     - AI provider API keys                                        — app_config_ai.ai_keys
     - Exam token previews (for safe display)                      — exam_access_tokens.token_preview
  The login password HASH (bcrypt) is separate and is what actually authenticates.

CSRF PROTECTION (Origin/Referer, not tokens)
  State-changing requests (POST/PATCH/PUT/DELETE) must carry an Origin or Referer whose
  host matches the site; otherwise they are rejected. This replaces Laravel's token CSRF
  and suits the single-page React front-end.

ANTI-CHEAT
  The exam page records events — tab blur/focus, fullscreen exit/enter, blocked
  paste/copy/right-click, missing Safe Exam Browser, session resumed, auto-submit on
  timeout. They are de-duplicated, capped, attached to the session/submission, and shown
  to teachers/admins in grading and the live monitor.

SAFE EXAM BROWSER (SEB)
  An exam can require SEB (cap: exam.config.seb). A rotating 24-character secret is used to
  verify the student is inside the locked-down browser; "seb_missing" is recorded otherwise.


================================================================================
SECTION 12 — THE DATA MODEL (tables and relationships)
================================================================================
All primary keys are application-generated UUIDs (stored as VARCHAR). Several tables are
append-only (no updated_at). JSON columns hold flexible structures (answers, options,
distributions, anti-cheat events, capabilities).

PEOPLE
  users               role (admin/teacher/student), active, subject, capabilities(JSON),
                      token_version, created_by. Relations: createdExams, submissions,
                      sessions, createdClasses, credential.
  user_credentials    password_hash (bcrypt), password_plain (encrypted), failed_attempts,
                      locked_until, last_sign_in_at. One per user.
  student_classes     a class roster (name, academic_year, source_file). Owned by a teacher.
  class_students      members of a class (loose link by identifier, not a hard FK).

CONTENT
  bank_questions      the reusable library. type, subject, topic, subtopic, difficulty
                      (Bloom), prompt, options(JSON), correct_answer(JSON), points,
                      uploaded_by (the visibility scope), media.
  learning_objectives curriculum, subject, topic, subtopic, text, sort_order, uploaded_by.

EXAMS
  exams               code, name, duration_minutes, passing_grade, exam_mode, shuffle flags,
                      language, subject, type/difficulty/media distributions (JSON),
                      seb_required, seb_secret, scheduling window. Owned by a teacher.
  exam_questions      a self-contained COPY of a question inside an exam (position, type,
                      prompt, options, correct_answer, difficulty, points,
                      source_bank_question_id as a soft reference). Append-only.
  exam_media          images/audio/video attached to an exam question.
  exam_access_tokens  token_digest (SHA-256, for lookup), token_preview (encrypted, for
                      display), max_uses, used_count, expires_at, active, optional class scope.
  exam_token_redemptions  who redeemed which token (idempotency ledger).

ATTEMPTS
  exam_sessions       one per attempt. status (draft/submitted/expired), started_at,
                      last_saved_at, submitted_at, attempt number, token_id,
                      resume_token (UUID), anti_cheat_events(JSON). Append-only.
  answer_drafts       the continuously-saved answers (value JSON), unique per
                      (session, question). This is the live workbook.
  exam_submissions    the final graded record. answers_snapshot(JSON, frozen),
                      manual_scores(JSON, essays), final/possible/percent, passed,
                      pending_essay_count, topic_breakdown(JSON), anti_cheat_events.

AI / OPS
  app_config_ai       singleton: text/image provider, model, temperature, encrypted ai_keys.
  admin_upload_jobs / exam_generation_prompts   bookkeeping for uploads + AI prompt history.

KEY RELATIONSHIPS IN WORDS
  A user (teacher) HAS MANY exams, classes, bank questions, learning objectives.
  An exam HAS MANY questions, tokens, sessions, submissions.
  A token, when redeemed, creates a session; a session HAS MANY answer_drafts and
  HAS ONE submission. A submission belongs to an exam and a user and is what every
  scores/reports/analyze view reads.


================================================================================
SECTION 13 — QUICK-REFERENCE
================================================================================

KEY NUMBERS
  Session cookie life ............. 5 days (1–30 configurable)
  Exam-access cookie life ......... 8 hours
  Resume token .................... lives as long as the draft session
  Autosave interval ............... every 5 seconds (+ on page-hide)
  Submit retries .................. 4, backoff 0.8s / 1.8s / 4s / 8s
  Recovery banner retry ........... every 10 seconds + on reconnect
  Local draft pruning ............. after 24 hours
  Finalizer sweep ................. every minute (60-second grace past deadline)
  Login lockout ................... 5 failed attempts → 15-minute lock
  Token redemption rate limit ..... 15 / minute
  Live monitor refresh ............ every 7 seconds
  Capability keys ................. 39, across 4 groups

THE 7 DIFFICULTY LEVELS (Bloom's revised taxonomy + Olympiad)
  remember · understand · apply · analyze · evaluate · create · olympiad

THE 5 QUESTION TYPES
  single_choice · multi_select · short_text · numeric · essay

THE 4 CURRICULA
  kurikulum_merdeka · as_a_level · ib · olympiad

GLOSSARY
  Capability ...... a single on/off permission granted to one teacher by the admin.
  Session ......... one attempt at an exam; holds the live, continuously-saved answers.
  Submission ...... the final, frozen, graded record of an attempt.
  Access token .... the code a student redeems to unlock an exam (use-limited, expirable).
  Resume token .... a per-attempt UUID that lets a student re-enter an interrupted exam.
  Draft ........... an in-progress answer (in the browser and in answer_drafts).
  Snapshot ........ the frozen copy of answers captured at submission, used for scoring.
  Strict mode ..... one attempt, no per-question review.
  Try-out mode .... multiple attempts, full review.
  Finalizer ....... the per-minute job that auto-submits abandoned, timed-out attempts.
  Bloom's taxonomy. the 6-level cognitive scale used for difficulty (+ Olympiad).

ROLE HOME PAGES
  Admin   → /admin       Teacher → /teacher      Student → /student

================================================================================
END OF DOCUMENT
================================================================================
