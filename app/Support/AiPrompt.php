<?php

namespace App\Support;

/**
 * Builds AI exam-generation prompts. VERBATIM port of the original
 * src/lib/ai-prompt.ts — every prompt string below must match the original
 * character-for-character so the same model behaviour is preserved across
 * the Next.js app and this Laravel port.
 *
 * Split into TWO phases:
 *
 *   Phase 1 — buildQuestionPrompt(input):
 *     The full question-generation prompt. The teacher downloads this as
 *     JSON and feeds it to ChatGPT / Claude / Gemini. The AI returns a
 *     `questions.json` array — for every question that needs a diagram
 *     the AI fills a `mediaPrompt` field (a string describing what the
 *     image should depict). No images are generated in Phase 1.
 *
 *   Phase 2 — buildImagePrompt(input):
 *     A short, focused instruction set for an image-generation AI. The
 *     teacher pastes this into DALL·E / Midjourney / Bing Image Creator
 *     along with the questions.json produced by Phase 1.
 *
 * `buildAiPrompt(input)` remains exported as a thin back-compat wrapper
 * returning the question prompt (Phase 1).
 *
 * The $input array shape (parity with AiPromptInput):
 *   language: string
 *   subject: string
 *   topic: string
 *   subtopic: string
 *   gradeLevel: string
 *   totalCount: int
 *   difficultyCounts: array{remember:int,understand:int,apply:int,analyze:int,evaluate:int,create:int,olympiad:int}
 *   typeCounts: array{single_choice:int,multi_select:int,short_text:int,numeric:int,essay:int}
 *   mediaImageCount: int
 *   mediaTableCount: int
 *   selectedLearningObjectives?: array<int,array{topic:string,subtopic:?string,text:string}>
 *   extraInstructions: string
 *   sourceUrls: string[]
 *   olympiadIntensity: 'intro'|'moderate'|'extreme'
 */
class AiPrompt
{
    /** @var array<string,string> */
    private const OLYMPIAD_INTENSITY_DESCRIPTIONS = [
        'intro' => 'introductory contest level — accessible to a strong high-school student in their first olympiad round; rigorous but solvable in a few minutes',
        'moderate' => 'mid-tier contest level — typical national olympiad final, requires solid problem-solving but not multi-page proofs',
        'extreme' => 'highest contest level — IMO / IPhO / IChO style, requires deep insight, non-obvious technique, or a clever case analysis',
    ];

    private const SCHEMA_SAMPLE_NO_MEDIA = <<<'JSON'
[
  {
    "type": "single_choice",
    "language": "English",
    "subject": "Mathematics",
    "topic": "Algebra",
    "subtopic": "Linear equations",
    "difficulty": "remember",
    "points": 1,
    "prompt": "Solve for x: 3x + 7 = 22.",
    "options": [
      { "id": "A", "text": "3" },
      { "id": "B", "text": "5" },
      { "id": "C", "text": "7" },
      { "id": "D", "text": "9" }
    ],
    "correctAnswer": "B",
    "explanationText": "Subtract 7 from both sides: 3x = 15. Divide by 3: x = 5.",
    "mediaPrompt": null
  },
  {
    "type": "essay",
    "language": "English",
    "subject": "Mathematics",
    "topic": "Algebra",
    "subtopic": "Linear equations",
    "difficulty": "analyze",
    "points": 5,
    "prompt": "Solve 2x + 3 = 15 and show every step.",
    "options": null,
    "correctAnswer": "",
    "explanationText": "Subtract 3, divide by 2. Award up to 5 points for clear steps and correct final answer.",
    "mediaPrompt": null
  }
]
JSON;

    private const SCHEMA_SAMPLE_WITH_MEDIA = <<<'JSON'
[
  {
    "type": "single_choice",
    "language": "English",
    "subject": "Mathematics",
    "topic": "Geometry",
    "subtopic": "Right triangles",
    "difficulty": "apply",
    "points": 2,
    "prompt": "In the right triangle shown below, find the length of the hypotenuse.",
    "options": [
      { "id": "A", "text": "5" },
      { "id": "B", "text": "7" },
      { "id": "C", "text": "12" },
      { "id": "D", "text": "25" }
    ],
    "correctAnswer": "A",
    "explanationText": "By the Pythagorean theorem, c = sqrt(3^2 + 4^2) = sqrt(25) = 5.",
    "mediaPrompt": "A clean, simple geometry diagram on a white background showing a right triangle. The horizontal leg is labeled '3 cm' at the bottom; the vertical leg is labeled '4 cm' on the right; the hypotenuse is unlabeled and drawn diagonally from the top-right to the bottom-left. Right-angle marker in the bottom-right corner. Minimal, textbook style, black lines on white, no shading, no extra decoration."
  },
  {
    "type": "numeric",
    "language": "English",
    "subject": "Mathematics",
    "topic": "Statistics",
    "subtopic": null,
    "difficulty": "understand",
    "points": 1,
    "prompt": "Find the mean of 4, 8, 10, and 14.",
    "options": null,
    "correctAnswer": 9,
    "explanationText": "Sum = 36. 36 / 4 = 9.",
    "mediaPrompt": null
  }
]
JSON;

    private static function buildLanguageBlock(string $language): string
    {
        $lang = trim($language) !== '' ? trim($language) : 'English';
        if (strtolower($lang) === 'english') {
            return "== LANGUAGE GUIDELINE ==\n"
                ."Write everything (prompts, options, explanations) in clear, fluent English.\n"
                ."- Use precise STEM terminology.\n"
                ."- Use realistic examples and contexts.\n"
                ."- Vary sentence structure so the questions do not read like a template.";
        }

        return "== LANGUAGE GUIDELINE (CRITICAL) ==\n"
            ."Write everything (prompts, options, explanations) NATIVELY in {$lang}.\n"
            ."\n"
            ."DO NOT translate from English. Author the questions directly in {$lang} as a fluent, experienced {$lang}-speaking teacher would write them.\n"
            ."- Use the proper, locally-accepted STEM terminology, notation, and unit conventions for {$lang}.\n"
            ."  Example: in Bahasa Indonesia, use \"persamaan linear\" (not \"linear equation\"), \"luas\" (not \"area\"), \"akar kuadrat\" (not \"square root\"), etc.\n"
            ."- Use locally appropriate names, currency, units, geography, and cultural references where examples are needed.\n"
            ."  Example: in Bahasa Indonesia, \"Rp\", \"kilometer\", \"Ahmad / Siti / Budi\", \"Surabaya\", \"rupiah\".\n"
            ."- Use idiomatic phrasing. The questions must NOT read like machine-translated English — vary sentence structure, use natural word order, and avoid awkward calques.\n"
            ."- Punctuation, number formatting, and decimal separators should follow {$lang} convention (e.g., Indonesian uses \",\" as decimal separator: \"3,14\" not \"3.14\").\n"
            ."- Output the JSON KEYS in English (e.g., \"type\", \"prompt\", \"options\", \"correctAnswer\") — only the VALUES should be in {$lang}. The \"type\", \"difficulty\", and option \"id\" values stay in English / Latin letters.";
    }

    /**
     * 6-step procedure that runs end-to-end every time the teacher clicks
     * Generate. Steps 5–6 happen later in a SEPARATE phase 2.
     */
    private static function buildWorkflowBlock(
        bool $includeMedia,
        bool $hasSources,
        bool $hasLearningObjectives,
        int $totalCount
    ): string {
        $steps = [];

        // ── Step 1: ALLOCATE QUESTIONS PER SCOPE ────────────────────────
        if ($hasLearningObjectives) {
            $steps[] = "ALLOCATE QUESTIONS PER SCOPE — distribute the {$totalCount} questions across the chosen Learning Objectives listed in the LEARNING OBJECTIVES section below.\n"
                ."\n"
                ."   • If totalCount ≥ number of chosen LOs: cover each LO at least once, then double up on the LOs that naturally support more variation (different scenarios, numbers, framings).\n"
                ."\n"
                ."   • If totalCount < number of chosen LOs: you MUST mix multiple LOs into single combined questions so every chosen LO is still touched (never drop one). Mix in this priority order:\n"
                ."       (a) FIRST mix LOs that share the SAME Subtopic into one combined question.\n"
                ."       (b) If still too many LOs for the slots, mix LOs from DIFFERENT subtopics within the SAME Topic.\n"
                ."       (c) If still too many, mix LOs across DIFFERENT Topics.\n"
                ."     This hierarchy is intentional: combining LOs within a subtopic is pedagogically natural; combining across topics is a last resort.\n"
                ."\n"
                ."   • Apply the same falling-back rule for the chosen Subtopics and Topics themselves: if totalCount < number of chosen Subtopics, mix subtopics within a topic; if totalCount < number of chosen Topics, mix topics. (This protects against a teacher who ticked a topic box expecting one question and ended up with many auto-selected LOs underneath.)";
        } else {
            $steps[] = "ALLOCATE QUESTIONS PER SCOPE — distribute the {$totalCount} questions across the requested Topic / Subtopic in REQUIREMENTS. Vary scenarios, numbers, and angles across the set so the questions aren't repetitive.";
        }

        // ── Step 2: ALLOCATE DIFFICULTY ─────────────────────────────────
        $steps[] = "ALLOCATE DIFFICULTY — within step 1's allocation, assign each question a Bloom's-taxonomy cognitive level (remember / understand / apply / analyze / evaluate / create / olympiad) so the per-level counts in REQUIREMENTS are matched EXACTLY. The level is INDEPENDENT of which LO / topic a question targets — any LO can be authored at any cognitive level (do not infer level from LO wording).";

        // ── Step 3: STUDY ───────────────────────────────────────────────
        if ($hasSources) {
            $steps[] = 'STUDY — for each (scope + difficulty) slot from steps 1+2, gather the relevant logic, concepts, laws, equations, formulas, rules, sample question framings, sample media ideas, and reference data from the SOURCE URLS section below. Those URLs are the authoritative syllabus reference — prefer their facts and conventions over general background knowledge.';
        } else {
            $steps[] = 'STUDY — for each (scope + difficulty) slot from steps 1+2, recall from your training knowledge the relevant logic, concepts, laws, equations, formulas, rules, sample question framings, sample media ideas, and reference data needed to author the question.';
        }

        // ── Step 4: AUTHOR JSON ─────────────────────────────────────────
        $jsonParts = [
            'AUTHOR JSON — write each question as a JSON entry per the OUTPUT SCHEMA at the bottom (type, language, subject, topic, subtopic, difficulty, points, prompt, options, correctAnswer, explanationText).',
        ];
        if ($hasLearningObjectives) {
            $jsonParts[] = 'Each question\'s `topic` + `subtopic` JSON fields must be COPIED from the [Topic > Subtopic] prefix of the LO it addresses.';
        }
        if ($includeMedia) {
            $jsonParts[] = 'Questions chosen to carry an image must fill the `mediaPrompt` field with a detailed, self-contained instruction for an image AI (see MEDIA section for what makes a good mediaPrompt). Do NOT generate or invent any image filename — only describe what the image should look like.';
        }
        $steps[] = implode(' ', $jsonParts);

        // ── Step 5: GENERATE IMAGES (SEPARATE PHASE — for context only) ─
        if ($includeMedia) {
            $steps[] = 'GENERATE IMAGES (separate phase — NOT your job) — in a later phase, the teacher runs a different image-generation AI with the `mediaPrompt` strings from step 4. That phase produces `media/qN.png` files. You do NOT need to render images yourself; just write each mediaPrompt with the assumption that it will be handed AS-IS to an image AI later.';
        }

        // ── Step 6: PACKAGE ZIP (downstream — teacher does this) ───────
        if ($includeMedia) {
            $steps[] = 'PACKAGE ZIP (downstream — teacher does this) — the teacher zips `questions.json` (from step 4) and the rendered `media/qN.png` files (from the separate phase 5) and uploads via Question Bank → Upload questions. You are not involved in this step.';
        } else {
            $steps[] = 'PACKAGE ZIP (downstream — teacher does this) — the teacher uploads `questions.json` (from step 4) via Question Bank → Upload questions. You are not involved in this step.';
        }

        $numbered = [];
        foreach ($steps as $i => $s) {
            $numbered[] = ($i + 1).'. '.$s;
        }

        return "== WORKFLOW (procedure — follow in order) ==\n".implode("\n\n", $numbered);
    }

    private static function buildNoveltyBlock(): string
    {
        return "== NOVELTY (HARD REQUIREMENT) ==\n"
            ."Every question in your reply must be BRAND NEW for this generation. Do NOT:\n"
            ."- Reuse, paraphrase, or pattern-match any question you have produced before (for this teacher, this exam, this subject, or anyone else).\n"
            ."- Recycle the same numbers, names, places, or scenarios across questions in this reply.\n"
            ."- Lift example problems verbatim from a textbook or from the source URLs.\n"
            ."\n"
            ."Instead:\n"
            ."- Invent fresh contexts, numbers, settings, and phrasing for every single question.\n"
            ."- Vary the structure (some questions ask \"find X\", some \"compare\", some \"explain why\", some \"given Y, predict Z\").\n"
            ."- If you would naturally repeat a scenario, change at least the numbers AND one structural element (units, frame of reference, variable names, etc.).\n"
            ."- Treat each invocation of this prompt as starting from scratch — no continuity with anything previously generated.";
    }

    /**
     * @param  string[]  $urls
     */
    private static function buildSourceUrlsBlock(array $urls): string
    {
        $cleaned = array_values(array_filter(array_map('trim', $urls), fn ($u) => $u !== ''));
        if (count($cleaned) === 0) {
            return '';
        }
        $list = implode("\n", array_map(fn ($u) => "- {$u}", $cleaned));

        return "\n== SOURCE MATERIAL — STUDY FIRST, THEN GENERATE ==\n"
            ."Treat the following URLs as authoritative source material. BEFORE you author any question, study these sources to internalise the logic, laws, equations, formulas, theories, and concepts they teach. Only after you have read and understood them should you start composing questions.\n"
            ."{$list}\n"
            ."- If the URLs are reachable in your environment, read them in full; otherwise rely on your training knowledge of those resources, treating them as the definitive interpretation.\n"
            ."- Prefer facts, definitions, formulas, derivations, and worked examples drawn from these sources over general background knowledge.\n"
            ."- Quote exact values (dates, names, numbers, units, constants) as they appear in the sources.\n"
            ."- If two sources disagree, prefer the most specific / most recent.\n"
            ."- You MAY combine more than one topic or subtopic within a single question when the sources support it (e.g. a question that uses kinematics AND energy conservation together). Do not force separation when the underlying physics, math, or chemistry naturally couples concepts.\n"
            ."- Do NOT include the URLs themselves in any question prompt — they are background context only.\n";
    }

    private static function buildOlympiadIntensityNote(int $count, string $intensity): string
    {
        if ($count <= 0) {
            return '';
        }
        $desc = self::OLYMPIAD_INTENSITY_DESCRIPTIONS[$intensity] ?? self::OLYMPIAD_INTENSITY_DESCRIPTIONS['moderate'];

        return " Aim for {$desc}.";
    }

    /**
     * @param  array<int,array{topic:string,subtopic:?string,text:string}>|null  $los
     */
    private static function buildLearningObjectivesBlock(?array $los, int $totalCount): string
    {
        if (! $los || count($los) === 0) {
            return '';
        }
        $count = count($los);
        $numberedLines = [];
        foreach ($los as $i => $lo) {
            $prefix = ! empty($lo['subtopic'])
                ? "[{$lo['topic']} > {$lo['subtopic']}]"
                : "[{$lo['topic']}]";
            $n = $i + 1;
            $numberedLines[] = "LO {$n}: {$prefix} {$lo['text']}";
        }
        $numbered = implode("\n", $numberedLines);

        $rule2 = $count <= $totalCount
            ? 'Cover each LO at least once before doubling up on any single LO. Some LOs naturally support multiple questions (different scenarios, numbers, framings) and others are best assessed with a single well-crafted question.'
            : "You have {$count} LOs but only {$totalCount} questions, so you cannot cover them all — prioritise LOs that combine multiple skills or that the teacher would most expect to see in a representative exam.";

        return "\n== LEARNING OBJECTIVES (CONTENT SCOPE — HARD CONSTRAINT) ==\n"
            ."The teacher has hand-picked the following {$count} learning objective(s) from the school's curriculum for this exam. Your job is to generate {$totalCount} exam questions that, taken together, test these specific objectives. The selection below is the ONLY content the questions may cover.\n"
            ."\n"
            ."CHOSEN LEARNING OBJECTIVES ({$count}):\n"
            ."{$numbered}\n"
            ."\n"
            ."GENERATION RULES (read carefully):\n"
            ."1. EVERY question you generate MUST concretely test ONE of the LOs above (LO 1, LO 2, … LO {$count}). It is NOT enough for the question to be \"about the topic\" — it must directly assess the specific skill or knowledge described in a chosen LO.\n"
            ."2. Distribute the {$totalCount} questions across the LOs sensibly. {$rule2}\n"
            ."3. Do NOT invent or pull in any learning objectives that aren't listed above. If a topic from your training would normally cover extra LOs, ignore them. Stay strictly within the chosen scope.\n"
            ."4. Each question's \"topic\" + \"subtopic\" JSON fields MUST be COPIED from the LO it addresses (the bracketed prefix above). Do NOT use the free-text Topic / Subtopic from REQUIREMENTS — those are ignored when LOs are present.\n"
            ."5. Cognitive level (Bloom's) is INDEPENDENT of which LO a question tests. Match the level mix specified in REQUIREMENTS — every LO can be authored at any cognitive level (remember, understand, apply, analyze, evaluate, create, olympiad). Do NOT infer the level from the LO's wording (e.g. \"explain\" doesn't mean understand; \"derive\" doesn't mean analyze — those map to Bloom's verbs, but the calling teacher's per-level counts override any verb-matching heuristic).\n"
            ."\n"
            ."EXAMPLE: If a chosen LO is \"use V = Q/(4πε₀r) for the electric potential in the field due to a point charge\", a question testing it could be authored at any cognitive level — remember (\"write the formula for the electric potential at distance r from a point charge\"), apply (\"calculate V at r = 0.10 m from a +2.0 µC charge\"), or analyze (\"two point charges +Q and −Q are separated by 2a along the x-axis; find the potential at a point on the perpendicular bisector at distance d from the midpoint and show that it vanishes\"). All three test the SAME LO; the level comes from REQUIREMENTS, not the LO text.\n";
    }

    private static function buildTablesBlock(?int $mediaTableCount, int $totalCount): string
    {
        $n = (is_int($mediaTableCount) && $mediaTableCount > 0) ? $mediaTableCount : 0;
        if ($n <= 0) {
            return '';
        }
        $capped = min($n, $totalCount);
        $rest = $totalCount - $capped;

        return "\n== TABLES (HARD COUNT CONSTRAINT) ==\n"
            ."EXACTLY {$capped} of the {$totalCount} questions must include a GFM markdown table inside the \"prompt\" field — data table, observation results, comparison matrix, periodic-table excerpt, kinematics measurements, etc. Pick the {$capped} questions where a tabular layout genuinely aids reasoning (multi-row data, paired columns, time-series). The other {$rest} questions must have NO markdown table in their prompt.\n"
            ."\n"
            ."Table format (must parse as GFM):\n"
            ."- A header row separated from the body by a row of dashes/pipes (`| --- | --- |`).\n"
            ."- Use real column headers (e.g. \"Trial\", \"Mass (kg)\", \"Velocity (m/s)\").\n"
            ."- Numbers should be realistic and consistent with the question's physics / chemistry / math.\n"
            ."- Keep tables small (2–5 columns, 3–8 rows) so they render well on a small screen.\n"
            ."- Do NOT wrap the table in code fences. It must be raw GFM inline in the prompt string.\n"
            ."- The table is part of the QUESTION TEXT — don't put it in explanationText or in a new field.\n";
    }

    private static function buildStructuredEssayBlock(int $essayCount): string
    {
        if ($essayCount <= 0) {
            return '';
        }

        return "\n== ESSAY / STRUCTURED QUESTION FORMAT (Cambridge AS/A Level Paper 2 style) ==\n"
            ."Every question with \"type\": \"essay\" must be authored as a multi-part STRUCTURED question following the Cambridge Paper 2 layout. (This applies ONLY to type=\"essay\" entries; single_choice, multi_select, short_text, numeric questions keep their normal one-shot format and NEVER use sub-part markers.)\n"
            ."\n"
            ."STRUCTURED SUB-PARTS (HARD FORMAT RULE — read carefully):\n"
            ."- If an essay question naturally breaks into multiple sub-tasks (e.g. \"calculate X, then calculate Y, then explain Z, then sketch a graph\"), each sub-task MUST be on its OWN line and MUST start with `(a) `, `(b) `, `(c) `, … — lowercase single letters in PLAIN parentheses, followed by a single space, followed by the sub-task text.\n"
            ."- The line break BEFORE each marker is required. Sub-parts are split on newlines, not on punctuation.\n"
            ."- Do NOT bold or italicise the marker. Write `(a) ` as plain text — never `**(a)**`, `*(a)*`, `__(a)__`. The downstream parser only reliably detects unstyled markers.\n"
            ."- Do NOT use any other format: `(ii)` / `(iii)` (multi-letter Roman), `(A)` / `(B)` (uppercase), `1.` / `2.` (numeric), `Part a)` / `a)` / `(a.)`, indented sub-sub-parts, etc. will NOT be detected. Use only `(a) (b) (c) …` in plain parentheses.\n"
            ."- If a sub-task would naturally split further (e.g. \"(b)(i)\", \"(b)(ii)\"), promote each piece to its own top-level letter instead: `(b)` becomes the first piece and `(c)` becomes the second piece. Keep markers strictly sequential a → b → c → d → …\n"
            ."- A single-task essay (one self-contained question with no natural sub-parts) MAY omit markers entirely — the UI falls back to a single editor in that case. Only add markers when the question genuinely has multiple sub-tasks.\n"
            ."\n"
            ."REQUIRED structure of the \"prompt\" field:\n"
            ."- Open with a short context paragraph that sets the scenario (1–3 sentences). Include any data values, diagrams referenced, or definitions the sub-parts will use. This intro has NO marker — only sub-task lines get markers.\n"
            ."- Then 2–5 sub-task lines following the STRUCTURED SUB-PARTS rule above. Each sub-task line begins with `(a) `, `(b) `, `(c) `, … and ends with its mark allocation in plain square brackets, e.g. ` [2]`, ` [3]`.\n"
            ."- Separate sub-parts with blank lines (`\\n\\n`) so the marker is always at the start of its own line.\n"
            ."- Inside a sub-task line you MAY use any markdown: bold/italic for emphasis on KEY WORDS, bullet lists nested under the sub-task, GFM tables for measurement data, inline math like `v = u + at`, or block math when needed. Just don't bold the `(a)` marker itself.\n"
            ."- If the question refers to a diagram, include the diagram via the normal mediaPrompt field (only when MEDIA is enabled).\n"
            ."\n"
            ."REQUIRED structure of the \"explanationText\" field (mark scheme):\n"
            ."- A per-part mark scheme using the SAME sub-part labels in the SAME order as the prompt.\n"
            ."- For each sub-part: state the model answer, then break down how marks are awarded (e.g. \"1 mark for correct equation, 1 mark for substitution, 1 mark for final answer with unit\").\n"
            ."- Format each part as a line beginning with the plain marker, e.g.: `(a) [model answer] — [mark breakdown]. [Total: N]`. You may bold/italicise the answer text but keep the leading `(a) ` marker plain so the teacher can scan the mark scheme quickly.\n"
            ."- Use the same markdown formatting (math, bold, tables) as the prompt inside the answer text.\n"
            ."\n"
            ."REQUIRED \"points\" value:\n"
            ."- \"points\" = SUM of every sub-part's marks. Count carefully.\n"
            ."- Typical Cambridge Paper 2 questions total 6–15 marks; a short multi-part can be 4–6, a longer one can reach 15.\n"
            ."\n"
            ."REQUIRED \"correctAnswer\" value:\n"
            ."- Always \"\" (empty string). Essays are graded manually by the teacher using the explanationText mark scheme — there is no auto-scoreable single answer.\n"
            ."\n"
            ."EXAMPLE essay prompt (worth 8 marks total — note the plain `(a) `, `(b) `, `(c) `, `(d) ` markers, each on its own line, NEVER bolded):\n"
            ."\"\"\"\n"
            ."A car of mass 1200 kg is travelling at 25 m s⁻¹ along a straight horizontal road. The driver applies the brakes and the car decelerates uniformly to rest in 5.0 s.\n"
            ."\n"
            ."(a) Calculate the deceleration of the car. [2]\n"
            ."\n"
            ."(b) Calculate the magnitude of the average braking force acting on the car. [2]\n"
            ."\n"
            ."(c) State the direction of the average braking force relative to the car's motion. [1]\n"
            ."\n"
            ."(d) The driver's reaction time before applying the brakes was 0.6 s. Calculate the total distance the car travels from the moment the driver sees the hazard to the moment the car comes to rest. [3]\n"
            ."\"\"\"\n"
            ."\n"
            ."EXAMPLE matching explanationText (same plain markers, same order):\n"
            ."\"\"\"\n"
            ."(a) a = (v − u) / t = (0 − 25) / 5.0 = −5.0 m s⁻². Accept magnitude 5.0 m s⁻². — 1 mark for correct formula; 1 mark for correct numerical value with units. [Total: 2]\n"
            ."\n"
            ."(b) F = ma = 1200 × 5.0 = 6.0 × 10³ N. — 1 mark for F = ma; 1 mark for correct value with unit. [Total: 2]\n"
            ."\n"
            ."(c) Opposite to the direction of the car's motion. — 1 mark. [Total: 1]\n"
            ."\n"
            ."(d) Reaction distance s₁ = v × t_react = 25 × 0.6 = 15 m. Braking distance s₂ = ½(u + v)t = ½ × 25 × 5.0 = 62.5 m. Total = 77.5 m (≈ 78 m). — 1 mark for s₁ correctly computed; 1 mark for s₂; 1 mark for correct total with unit. [Total: 3]\n"
            ."\"\"\"\n"
            ."\n"
            ."For this example: \"points\" = 2 + 2 + 1 + 3 = 8.\n"
            ."\n"
            ."COGNITIVE-LEVEL MAPPING for essays (calibration, Bloom's revised taxonomy):\n"
            ."- remember:   1–2 sub-parts, ≤ 4 marks, state definitions / write a formula / name parts of a diagram.\n"
            ."- understand: 1–2 sub-parts, 4–6 marks, explain or paraphrase a concept, give an example in own words.\n"
            ."- apply:      2–3 sub-parts, 6–10 marks, use a procedure on a standard scenario (substitute values into a known formula, etc.).\n"
            ."- analyze:    3–4 sub-parts, 8–12 marks, break down a system, distinguish causes from effects, compare two scenarios.\n"
            ."- evaluate:   3–4 sub-parts, 8–12 marks, justify a choice between alternatives, critique a claim, weigh trade-offs.\n"
            ."- create:     3–5 sub-parts, 10–15 marks, design or construct a new artefact (procedure, experiment, derivation, proof).\n"
            ."- olympiad:   3–5 sub-parts, 10–15 marks, deep insight, non-obvious technique, or rigorous derivation — beyond textbook level.\n"
            ."\n"
            ."== ESSAY MIXED-TOPIC RULE (HARD REQUIREMENT) ==\n"
            ."Every essay / structured question MUST INTEGRATE MULTIPLE TOPICS rather than focus on a single isolated topic. This applies regardless of whether the teacher provided Learning Objectives:\n"
            ."- When Learning Objectives are present: each essay's sub-parts should weave together at least TWO different chosen LOs (preferably from different subtopics, or different topics where the physics / math / chemistry naturally couples). Use the essay's intro paragraph to establish a unifying scenario, then let each sub-part (a)/(b)/(c)/… probe a different LO so the whole question is a cross-topic synthesis.\n"
            ."- When NO LOs are present (free-form topic / subtopic only): treat the requested Topic as the umbrella, and have each essay span at least TWO related sub-areas under that umbrella. For example, a Physics essay on \"Mechanics\" should ideally touch kinematics + energy + forces inside one structured question rather than only kinematics.\n"
            ."- Mixed-topic essays are the WHOLE POINT of the structured format: a single essay can fairly test integration across the curriculum in a way that single_choice / multi_select / short_text / numeric cannot. Use the multi-part (a)/(b)/(c)/… layout to make this integration explicit.\n"
            ."- The JSON `topic` field for a mixed-topic essay must list the PRIMARY topic; the `subtopic` field must be the integration label (e.g. \"Cross-topic\", or the secondary topic's name). Do NOT leave subtopic as a single LO's subtopic when the essay spans multiple LOs — that misrepresents the question.\n"
            ."\n"
            ."Keep the rest of the JSON schema unchanged.\n";
    }

    private static function buildMediaBlock(?int $mediaImageCount, int $totalCount): string
    {
        $n = (is_int($mediaImageCount) && $mediaImageCount > 0) ? $mediaImageCount : 0;
        if ($n <= 0) {
            return "== MEDIA ==\n"
                .'Set "mediaPrompt" to null for every question. Questions must be self-contained text only. (Image generation is disabled for this run.)';
        }
        $cappedCount = min($n, $totalCount);
        $rest = $totalCount - $cappedCount;

        return "== MEDIA (image descriptions, 1:1 with the question) ==\n"
            ."HARD COUNT CONSTRAINT: EXACTLY {$cappedCount} of the {$totalCount} questions must have a \"mediaPrompt\" (a non-null string describing the image the question needs). The other {$rest} must have \"mediaPrompt\": null. Do not exceed {$cappedCount} prompts; do not fall short either.\n"
            ."\n"
            ."Choose WHICH questions to illustrate based on pedagogical value — pick the {$cappedCount} questions that benefit most from a visual (geometry diagrams, free-body diagrams, circuits, vector fields, graphs, labelled apparatus, etc.). Text-only questions (definitions, derivations, word problems) should stay text-only.\n"
            ."\n"
            ."Authoring order: finish writing the QUESTION (prompt + options + correctAnswer + explanationText) FIRST, then derive the mediaPrompt from that finished question. The image must illustrate what the question already says — never the other way around.\n"
            ."\n"
            ."For each question that genuinely benefits from a visual (diagram, chart, photo, geometric figure, graph, labelled illustration, …):\n"
            ."  - Set \"mediaPrompt\" (string) to a detailed, self-contained prompt that a teacher (or an image-generation AI) could use to render the picture (DALL·E, Midjourney, Stable Diffusion, Bing Image Creator).\n"
            ."  - The mediaPrompt must describe exactly what to draw, including: subject, layout, labels with values, colour palette, style (\"textbook diagram\", \"minimal\", \"no extra decoration\"), and any text labels that must appear.\n"
            ."  - Do NOT use copyrighted characters, real people, or brand logos.\n"
            ."  - Do NOT include any filename, file extension, or path in the mediaPrompt — image filenames are assigned later in a separate phase, not by you.\n"
            ."\n"
            ."IMAGE DESCRIPTION QUALITY RULES — every mediaPrompt MUST explicitly specify:\n"
            ."  - LABEL POSITIONING: every text label (variable names, numbers, units, axis titles, legend entries) is placed in CLEAR EMPTY SPACE — NEVER overlapping any graph line, vector arrow, axis, bounding box, geometric edge, diagram element, or other label. State this in the mediaPrompt with words like \"labels placed in empty margin space, not crossing any line or shape\".\n"
            ."  - LABEL ALIGNMENT: text labels are aligned either horizontally (parallel to the x-axis) or vertically (parallel to the y-axis) with the object they describe. No rotated/skewed/diagonal text unless the object itself is diagonal AND alignment makes it readable. State this in the mediaPrompt explicitly (\"label horizontally aligned with the vector tail\", \"axis title vertically aligned to the left of the y-axis\", etc.).\n"
            ."  - CONTRAST COLOURS FOR DISTINCT VECTORS / QUANTITIES: when the diagram has multiple vectors, forces, currents, fields, or other distinct quantities that the student must distinguish, assign each one a DIFFERENT high-contrast colour (e.g. velocity = blue, acceleration = red, force = green, electric field = purple). State the colour mapping inside the mediaPrompt. Do NOT colour-code distinct quantities with similar shades. If the diagram only has one quantity, default to \"black on white\".\n"
            ."  - READABILITY: minimal background, no decorative shading behind labels, font large enough to read at exam scale.\n"
            ."\n"
            ."UNIQUENESS RULES (apply to every question that has a mediaPrompt):\n"
            ."  - EXACTLY ONE mediaPrompt per question — never share a prompt between two questions.\n"
            ."  - Every mediaPrompt must describe a DISTINCT image. Do not copy/paste the same mediaPrompt or near-duplicate (same subject + same labels) across questions.\n"
            ."  - If two questions ask about similar concepts, the supporting images must still differ in numbers, orientation, colours, or layout so the image generator produces two genuinely different files.\n"
            ."  - Do NOT reuse mediaPrompts from any previous generation — treat this reply as starting fresh.\n"
            ."\n"
            ."For questions that do NOT need an image:\n"
            ."  - Set \"mediaPrompt\" to null.\n"
            ."\n"
            .'DO NOT generate images. Only return the JSON questions array. Image generation happens in a separate later step.';
    }

    // ────────────────────────────────────────────────────────────────────
    // Phase 1 — QUESTION PROMPT BUILDER
    // ────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $input
     */
    public static function buildQuestionPrompt(array $input): string
    {
        $diff = $input['difficultyCounts'];
        $typ = $input['typeCounts'];
        $totalRequested =
            (int) ($diff['remember'] ?? 0) +
            (int) ($diff['understand'] ?? 0) +
            (int) ($diff['apply'] ?? 0) +
            (int) ($diff['analyze'] ?? 0) +
            (int) ($diff['evaluate'] ?? 0) +
            (int) ($diff['create'] ?? 0) +
            (int) ($diff['olympiad'] ?? 0);
        $totalTypes =
            (int) $typ['single_choice'] +
            (int) $typ['multi_select'] +
            (int) $typ['short_text'] +
            (int) $typ['numeric'] +
            (int) $typ['essay'];

        $lang = trim((string) ($input['language'] ?? '')) !== '' ? trim((string) $input['language']) : 'English';
        $totalCount = (int) $input['totalCount'];

        $subtopicLine = trim((string) ($input['subtopic'] ?? '')) !== ''
            ? 'Subtopic: '.trim((string) $input['subtopic'])."\n"
            : '';
        $gradeLine = trim((string) ($input['gradeLevel'] ?? '')) !== ''
            ? 'Grade / level: '.trim((string) $input['gradeLevel'])."\n"
            : '';
        $extraLine = trim((string) ($input['extraInstructions'] ?? '')) !== ''
            ? "\nAdditional instructions:\n".trim((string) $input['extraInstructions'])."\n"
            : '';

        $mediaImageCount = (int) ($input['mediaImageCount'] ?? 0);
        $wantsMedia = $mediaImageCount > 0;
        $sample = $wantsMedia ? self::SCHEMA_SAMPLE_WITH_MEDIA : self::SCHEMA_SAMPLE_NO_MEDIA;

        $mediaSchemaLine = $wantsMedia
            ? '  "mediaPrompt":     string | null     // when this question needs an image: a detailed description of what to draw (subject, layout, labels, colours, style). null when the question is text-only. Do NOT include any filename — Phase 2 assigns those.'
            : '  "mediaPrompt":     null               // always null when no media is requested';

        $los = $input['selectedLearningObjectives'] ?? null;
        $hasLOs = is_array($los) && count($los) > 0;

        $topicLine = $hasLOs
            ? "Topic / Subtopic: see LEARNING OBJECTIVES section below — those LOs are the authoritative content scope. Ignore any leftover Topic/Subtopic values you might see in the form.\n"
            : 'Topic: '.(($input['topic'] ?? '') !== '' ? $input['topic'] : '(unspecified)')."\n".$subtopicLine;

        $subjectVal = ($input['subject'] ?? '') !== '' ? $input['subject'] : '(unspecified)';
        $topicSchemaComment = $hasLOs
            ? 'COPY from the bracketed [Topic > Subtopic] prefix of the LO this question targets'
            : 'copy the requested topic';
        $subtopicSchemaComment = $hasLOs
            ? 'COPY from the bracketed [Topic > Subtopic] prefix of the LO this question targets, or null if that LO has no subtopic'
            : 'null if not applicable';

        $sourceUrls = is_array($input['sourceUrls'] ?? null) ? $input['sourceUrls'] : [];
        $hasSources = false;
        foreach ($sourceUrls as $u) {
            if (trim((string) $u) !== '') {
                $hasSources = true;
                break;
            }
        }

        $olympiadIntensity = (string) ($input['olympiadIntensity'] ?? 'moderate');
        $olympiadNote = self::buildOlympiadIntensityNote((int) $diff['olympiad'], $olympiadIntensity);

        $workflow = self::buildWorkflowBlock($wantsMedia, $hasSources, $hasLOs, $totalCount);
        $novelty = self::buildNoveltyBlock();
        $sourceBlock = self::buildSourceUrlsBlock($sourceUrls);
        $languageBlock = self::buildLanguageBlock($lang);
        $mediaBlock = self::buildMediaBlock($mediaImageCount, $totalCount);
        $tablesBlock = self::buildTablesBlock((int) ($input['mediaTableCount'] ?? 0), $totalCount);
        $essayBlock = self::buildStructuredEssayBlock((int) $typ['essay']);
        $loBlock = self::buildLearningObjectivesBlock($hasLOs ? $los : null, $totalCount);

        // Checklist tail — mirrors the original's conditional numbering.
        $checklist7a = $hasLOs
            ? "\n7a. CURRICULUM SCOPE: every question's content must come from ONE of the ".count($los)." chosen Learning Objectives listed above. Every question's \"topic\" + \"subtopic\" JSON fields must be COPIED from the bracketed prefix [Topic > Subtopic] of the LO it addresses. Do NOT introduce content outside the chosen LOs."
            : '';

        if ($wantsMedia) {
            $cappedMedia = min($mediaImageCount, $totalCount);
            $checklistMedia = "8. EXACTLY {$cappedMedia} questions must have \"mediaPrompt\" filled with a non-null description; ALL others must have \"mediaPrompt\": null. Count them before you reply. Each question gets its OWN unique mediaPrompt — no duplicates across questions, no filenames or paths inside the description.\n9. Every question is BRAND NEW — fresh scenarios, numbers, names, phrasing. No reuse of anything you have generated previously.\n10.";
        } else {
            $checklistMedia = "8. Every question is BRAND NEW — fresh scenarios, numbers, names, phrasing. No reuse of anything you have generated previously.\n9.";
        }

        return "You are an expert exam-question author. Generate {$totalCount} brand-new exam questions following the requirements below, and return ONLY the JSON array described at the bottom. No markdown wrappers, no commentary, no surrounding prose.\n"
            ."\n"
            ."== REQUIREMENTS ==\n"
            ."Language: {$lang}\n"
            ."Subject: {$subjectVal}\n"
            ."{$topicLine}{$gradeLine}\n"
            ."Cognitive level mix (Bloom's revised taxonomy + olympiad — must sum to {$totalRequested}, which should equal {$totalCount}):\n"
            ."- remember:   {$diff['remember']} question(s)   (Bloom's 1 — recall facts, terms, basic definitions)\n"
            ."- understand: {$diff['understand']} question(s)   (Bloom's 2 — explain, paraphrase, summarise, compare)\n"
            ."- apply:      {$diff['apply']} question(s)   (Bloom's 3 — use a procedure in a new situation; solve a standard problem)\n"
            ."- analyze:    {$diff['analyze']} question(s)   (Bloom's 4 — decompose, distinguish, examine relations between parts)\n"
            ."- evaluate:   {$diff['evaluate']} question(s)   (Bloom's 5 — justify, critique, assess, judge merits)\n"
            ."- create:     {$diff['create']} question(s)   (Bloom's 6 — design, construct, plan, produce something new)\n"
            ."- olympiad:   {$diff['olympiad']} question(s)   (beyond Bloom's — contest-level: non-obvious technique, deep insight){$olympiadNote}\n"
            ."\n"
            ."Question-type mix (must sum to {$totalTypes}, which should equal {$totalCount}):\n"
            ."- single_choice: {$typ['single_choice']}  (5 options A–E, exactly one correct)\n"
            ."- multi_select:  {$typ['multi_select']}  (up to 6 options A–F, two or more correct)\n"
            ."- short_text:    {$typ['short_text']}  (single word/phrase answer; exact match)\n"
            ."- numeric:       {$typ['numeric']}  (single number answer)\n"
            ."- essay:         {$typ['essay']}  (open-ended; the teacher will grade manually)\n"
            ."{$extraLine}\n"
            ."{$workflow}\n"
            ."\n"
            ."{$novelty}\n"
            ."{$sourceBlock}\n"
            ."{$languageBlock}\n"
            ."\n"
            ."{$mediaBlock}\n"
            ."{$tablesBlock}\n"
            ."{$essayBlock}\n"
            ."{$loBlock}\n"
            ."\n"
            ."== OUTPUT SCHEMA ==\n"
            ."Return a JSON ARRAY where every element matches this exact shape:\n"
            ."\n"
            ."{\n"
            ."  \"type\":            \"single_choice\" | \"multi_select\" | \"short_text\" | \"numeric\" | \"essay\",\n"
            ."  \"language\":        string,            // copy the requested language\n"
            ."  \"subject\":         string,            // copy the requested subject\n"
            ."  \"topic\":           string,            // {$topicSchemaComment}\n"
            ."  \"subtopic\":        string | null,     // {$subtopicSchemaComment}\n"
            ."  \"difficulty\":      \"remember\" | \"understand\" | \"apply\" | \"analyze\" | \"evaluate\" | \"create\" | \"olympiad\",\n"
            ."  \"points\":          number,            // 1–5, weighted by difficulty\n"
            ."  \"prompt\":          string,            // the question text. For essay\n"
            ."                                        // questions with multiple sub-tasks,\n"
            ."                                        // each sub-task must be on its own\n"
            ."                                        // line beginning with \"(a) \", \"(b) \",\n"
            ."                                        // \"(c) \", … (plain lowercase letters\n"
            ."                                        // in parentheses, NOT bolded — see\n"
            ."                                        // ESSAY / STRUCTURED QUESTION FORMAT\n"
            ."                                        // section above). Single-task essays\n"
            ."                                        // and all non-essay types omit markers.\n"
            ."  \"options\":         [{\"id\": \"A\", \"text\": \"...\"}, ...] | null,\n"
            ."                                        // required for single_choice + multi_select; null otherwise\n"
            ."  \"correctAnswer\":   string | string[] | number,\n"
            ."                                        //   single_choice: option id, e.g. \"B\"\n"
            ."                                        //   multi_select:  array of option ids, e.g. [\"A\",\"C\"]\n"
            ."                                        //   short_text:    string answer\n"
            ."                                        //   numeric:       a number\n"
            ."                                        //   essay:         \"\" (empty string — teacher grades)\n"
            ."  \"explanationText\": string,            // why this is correct, shown after submission\n"
            ."{$mediaSchemaLine}\n"
            ."}\n"
            ."\n"
            ."== EXAMPLE OUTPUT ==\n"
            ."{$sample}\n"
            ."\n"
            ."== CHECKLIST BEFORE YOU REPLY ==\n"
            ."1. Return EXACTLY {$totalCount} questions, no more, no less.\n"
            ."2. Distribution by difficulty and by type must match the counts above.\n"
            ."3. Every option id is an UPPERCASE letter starting from \"A\" with no gaps.\n"
            ."4. For single_choice the correctAnswer is a single letter; for multi_select an array of two or more letters.\n"
            ."5. For numeric, correctAnswer is a JSON number (not a string).\n"
            ."6. For essay, correctAnswer must be the empty string \"\".\n"
            ."7. Author content NATIVELY in {$lang}; do NOT machine-translate from English.{$checklist7a}\n"
            ."{$checklistMedia} Output ONLY the JSON array. No backticks, no ```json fences, no headings, no commentary, no trailing text.\n"
            ."\n"
            .'DO NOT generate images. Only return the JSON questions array. Image generation happens in a separate later step.';
    }

    // ────────────────────────────────────────────────────────────────────
    // Phase 2 — IMAGE PROMPT BUILDER
    // ────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $input
     */
    public static function buildImagePrompt(array $input): string
    {
        $lang = trim((string) ($input['language'] ?? '')) !== '' ? trim((string) $input['language']) : 'English';
        $subject = trim((string) ($input['subject'] ?? '')) !== '' ? trim((string) $input['subject']) : '(unspecified)';
        $totalCount = (int) $input['totalCount'];
        $cappedCount = max(0, min((int) ($input['mediaImageCount'] ?? 0), $totalCount));

        if ($cappedCount <= 0) {
            return "You are an image-generation assistant. The exam package for this run does NOT require any images — every question in the accompanying questions.json has \"mediaPrompt\": null.\n"
                ."\n"
                .'No image generation is needed. The teacher can upload the questions.json directly via Question Bank → Upload questions.';
        }

        return "You are an image-generation assistant for an exam package. The teacher will provide you with a `questions.json` array (produced by an earlier question-generation step). Your job is to render ONE image for each question whose `mediaPrompt` field is a non-null string, and to bundle the results into a `media/` folder ready to zip alongside the questions.json.\n"
            ."\n"
            ."== CONTEXT ==\n"
            ."Subject: {$subject}\n"
            ."Language: {$lang}\n"
            ."Expected number of images: {$cappedCount} (one per question with a non-null mediaPrompt).\n"
            ."\n"
            ."== WORKFLOW ==\n"
            ."1. Read the accompanying `questions.json`. It is a JSON ARRAY of question objects.\n"
            ."2. For EACH question in array order, check its `mediaPrompt` field:\n"
            ."   - If `mediaPrompt` is null, skip — that question is text-only.\n"
            ."   - If `mediaPrompt` is a non-null string, render exactly ONE image from that description.\n"
            ."3. Save each rendered image as `q{position}.png` where `{position}` is the 1-based index of the question in the array. Examples:\n"
            ."   - Question at index 0 → `q1.png`\n"
            ."   - Question at index 4 → `q5.png`\n"
            ."   The numbering follows the question's POSITION in the array, NOT a running counter of images. So if only questions 1, 3 and 5 have mediaPrompts you produce `q1.png`, `q3.png`, `q5.png` (NOT `q1.png`, `q2.png`, `q3.png`).\n"
            ."4. Place every produced image inside a folder called `media/` at the package root. Final layout:\n"
            ."\n"
            ."   ```\n"
            ."   questions.json\n"
            ."   media/\n"
            ."     q1.png\n"
            ."     q3.png\n"
            ."     q5.png\n"
            ."     …\n"
            ."   ```\n"
            ."\n"
            ."5. ALSO update the questions.json: on every question you rendered an image for, set its `mediaFile` field to the matching filename (e.g. `\"mediaFile\": \"q1.png\"`). For questions you skipped, leave `mediaFile` absent or null. The `mediaPrompt` field can stay or be removed once you have rendered it.\n"
            ."\n"
            ."== IMAGE STYLE GUIDANCE ==\n"
            ."- Clean, textbook-style diagrams. Black lines on white background by default.\n"
            ."- Labels placed in clear empty space, not overlapping any line, vector, or shape.\n"
            ."- Text labels aligned horizontally or vertically with the object they describe (never rotated/skewed unless the object itself is diagonal).\n"
            ."- When the diagram shows multiple distinct quantities (vectors, forces, currents, fields), assign each a different high-contrast colour (e.g. velocity = blue, acceleration = red, force = green).\n"
            ."- No decorative shading behind labels. Minimal background.\n"
            ."- Font large enough to read at exam scale.\n"
            ."- Consistent visual style across ALL images in the set — same line weight, same label font, same colour palette conventions.\n"
            ."- Aspect ratio: prefer a roughly square (1:1) or 4:3 canvas so the image renders well inline with question text on both desktop and mobile.\n"
            ."- Output format: PNG, 1024×1024 or similar. Transparent or white background — never a coloured fill that competes with labels.\n"
            ."\n"
            ."== CONTENT RULES ==\n"
            ."- Render EXACTLY what the `mediaPrompt` describes. Do NOT add decorative elements, watermarks, or text not specified in the prompt.\n"
            ."- Do NOT use copyrighted characters, real people, or brand logos.\n"
            ."- If the mediaPrompt is ambiguous, prefer the simplest, most textbook-conventional interpretation.\n"
            ."\n"
            ."== DELIVERABLE ==\n"
            ."A folder containing:\n"
            ."- `questions.json` (the input, with `mediaFile` filled in for every rendered question)\n"
            ."- `media/` (containing one PNG per rendered question, named q{position}.png)\n"
            ."\n"
            .'The teacher will then zip both items and upload via the exam dashboard\'s Question Bank → Upload questions flow.';
    }

    // ────────────────────────────────────────────────────────────────────
    // Back-compat thin wrapper — returns the question prompt (Phase 1).
    // ────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $input
     */
    public static function buildAiPrompt(array $input): string
    {
        return self::buildQuestionPrompt($input);
    }
}
