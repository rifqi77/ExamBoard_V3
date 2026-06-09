import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft, ChevronDown, ChevronRight, Copy, Download, Library, Sparkles,
} from 'lucide-react';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import TeacherShell from '@/components/TeacherShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------------------
// Teacher · Generate exam with AI — port of the original AiGenerateClient.
//
// Two phases:
//   Phase 1 — download a question-prompt JSON, run it in ChatGPT/Claude/Gemini.
//   Phase 2 — copy an image prompt + that questions.json into an image AI.
// When the server has an API key for the active provider (auto mode) the
// teacher can hit "Generate now" and get a live preview of generated
// questions (auto-saved to the Question Bank) without leaving the page.
//
// The rich 2-level LO picker (Topic → Subtopic → LOs), tri-state group
// toggles, filter, collapse/expand/select-all/clear, and all caps-gated
// parameter inputs live inline in this file (no shared component per the
// porting constraints).
// ---------------------------------------------------------------------------

type OlympiadIntensity = 'intro' | 'moderate' | 'extreme';

const OLYMPIAD_INTENSITIES: Array<{ value: OlympiadIntensity; label: string; hint: string }> = [
    { value: 'intro', label: 'Intro', hint: 'First-round olympiad: rigorous but accessible' },
    { value: 'moderate', label: 'Moderate', hint: 'National final tier (default)' },
    { value: 'extreme', label: 'Extreme', hint: 'IMO / IPhO / IChO style — hardest' },
];

// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
const DIFFICULTY_KEYS = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'] as const;
const TYPE_KEYS = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'] as const;

const DIFFICULTY_LABELS: Record<(typeof DIFFICULTY_KEYS)[number], string> = {
    remember: 'Remember',
    understand: 'Understand',
    apply: 'Apply',
    analyze: 'Analyze',
    evaluate: 'Evaluate',
    create: 'Create',
    olympiad: 'Olympiad',
};
const TYPE_LABELS: Record<(typeof TYPE_KEYS)[number], string> = {
    single_choice: 'Single choice', multi_select: 'Multi select', short_text: 'Short text',
    numeric: 'Numeric', essay: 'Essay',
};

type CurriculumKey = 'none' | 'kurikulum_merdeka' | 'as_a_level' | 'ib' | 'olympiad';
const CURRICULUM_OPTIONS: Array<{ key: CurriculumKey; label: string }> = [
    { key: 'none', label: '— none (use Topic / Subtopic) —' },
    { key: 'kurikulum_merdeka', label: 'Kurikulum Merdeka' },
    { key: 'as_a_level', label: 'AS / A Level (Cambridge)' },
    { key: 'ib', label: 'IB' },
    { key: 'olympiad', label: 'Olympiad' },
];

type LoCatalogRow = {
    id: string;
    curriculum: Exclude<CurriculumKey, 'none'>;
    topic: string;
    subtopic: string | null;
    text: string;
    subject: string;
};

type GeneratedQuestion = {
    type: string;
    language: string;
    subject: string;
    topic: string;
    subtopic: string | null;
    difficulty: string;
    points: number;
    prompt: string;
    options: Array<{ id: string; text: string }> | null;
    correctAnswer: unknown;
    explanationText: string;
};

type PageProps = {
    basePath: string;
    isAdmin: boolean;
    capabilities: Record<string, boolean>;
    accountSubject: string | null;
    autoMode: { autoEnabled: boolean; provider: string; model: string };
    learningObjectives: LoCatalogRow[];
};

// Hare–Niemeyer distribution of a total across percentage buckets so the
// parts sum exactly to total (port of distributeByPercents).
function distributeByPercents(total: number, percents: Record<string, number>): Record<string, number> {
    const out: Record<string, number> = {};
    let floors = 0;
    const remainders: Array<{ key: string; r: number }> = [];
    for (const key of Object.keys(percents)) {
        const raw = (total * (percents[key] ?? 0)) / 100;
        const floor = Math.floor(raw);
        out[key] = floor;
        floors += floor;
        remainders.push({ key, r: raw - floor });
    }
    let leftover = total - floors;
    remainders.sort((a, b) => b.r - a.r);
    for (let i = 0; i < remainders.length && leftover > 0; i += 1) {
        out[remainders[i].key] += 1;
        leftover -= 1;
    }
    return out;
}

// Tri-state group checkbox: checked when all the group's ids are selected,
// indeterminate when some are, unchecked when none. (Port of GroupToggle.)
function GroupToggle({
    ids, selectedIds, onChange, label, level,
}: {
    ids: string[];
    selectedIds: string[];
    onChange: (next: { add?: string[]; remove?: string[] }) => void;
    label: ReactNode;
    level: 'topic' | 'subtopic';
}) {
    const ref = useRef<HTMLInputElement>(null);
    const sel = new Set(selectedIds);
    const selectedCount = ids.reduce((n, id) => (sel.has(id) ? n + 1 : n), 0);
    const total = ids.length;
    const allOn = selectedCount === total && total > 0;
    const allOff = selectedCount === 0;
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = !allOn && !allOff;
    }, [allOn, allOff]);
    return (
        <label className={`lo-picker-group lo-picker-group--${level}`} onClick={(e) => e.stopPropagation()}>
            <input
                ref={ref}
                type="checkbox"
                checked={allOn}
                onChange={(e) => (e.target.checked ? onChange({ add: ids }) : onChange({ remove: ids }))}
            />
            <span className="lo-picker-group-label">{label}</span>
            <span className="lo-picker-group-count">
                {selectedCount}/{total}
            </span>
        </label>
    );
}

// The full AI-generate body, shared verbatim between the teacher + admin
// pages. `basePath` drives the (relative) endpoint + back-links.
export function AiGenerateBody() {
    const { basePath, isAdmin, capabilities, accountSubject, autoMode: initialAutoMode, learningObjectives } =
        usePage().props as unknown as PageProps;

    const cap = (key: string) => capabilities[key] === true;
    const aiEnabled = isAdmin || cap('ai.generate');

    // Per-parameter gates (admin sees every cap as true).
    const difficultyAllowed = useMemo(
        () => ({
            remember: cap('ai.param.difficulty.remember'),
            understand: cap('ai.param.difficulty.understand'),
            apply: cap('ai.param.difficulty.apply'),
            analyze: cap('ai.param.difficulty.analyze'),
            evaluate: cap('ai.param.difficulty.evaluate'),
            create: cap('ai.param.difficulty.create'),
            olympiad: cap('ai.param.difficulty.olympiad'),
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [capabilities],
    );
    const typeAllowed = useMemo(
        () => ({
            single_choice: cap('ai.param.type.single'),
            multi_select: cap('ai.param.type.multi'),
            short_text: cap('ai.param.type.short_text'),
            numeric: cap('ai.param.type.numeric'),
            essay: cap('ai.param.type.essay'),
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [capabilities],
    );
    const mediaImageAllowed = cap('ai.param.media.image');
    const mediaTableAllowed = cap('ai.param.media.table');
    const curriculumAllowed = cap('curriculum.manage');
    const languageAdminAllowed = cap('ai.param.language');
    const subjectAdminAllowed = cap('ai.param.subject');
    const totalCap = 2000;

    const acctSubject = (accountSubject ?? '').trim();
    const subjectAccountLocked = acctSubject.length > 0;

    // Scope persisted UI-state keys under `${basePath}.ai-generate.*` so that
    // /admin/ai-generate and /teacher/ai-generate (which share this body) get
    // separate storage and don't overwrite each other.
    const uiKey = (name: string) => `${basePath}.ai-generate.${name}`;

    const [language, setLanguage] = useUIState(uiKey('language'), 'English');
    const [topic, setTopic] = useUIState(uiKey('topic'), '');
    const [subtopic, setSubtopic] = useUIState(uiKey('subtopic'), '');
    const [gradeLevel, setGradeLevel] = useUIState(uiKey('gradeLevel'), '');
    const [totalCount, setTotalCount] = useUIState(uiKey('totalCount'), 10);
    // Default % split across Bloom's revised taxonomy + olympiad (sums to 100).
    const [difficultyPercents, setDifficultyPercents] = useUIState(uiKey('difficultyPercents'), {
        remember: 15, understand: 25, apply: 25, analyze: 15, evaluate: 10, create: 7, olympiad: 3,
    });
    const [typeCounts, setTypeCounts] = useUIState(uiKey('typeCounts'), {
        single_choice: 6, multi_select: 2, short_text: 1, numeric: 1, essay: 0,
    });
    const [mediaImageCount, setMediaImageCount] = useUIState(uiKey('mediaImageCount'), 0);
    const [mediaTableCount, setMediaTableCount] = useUIState(uiKey('mediaTableCount'), 0);
    const [extraInstructions, setExtraInstructions] = useUIState(uiKey('extraInstructions'), '');
    const [sourceUrlsText, setSourceUrlsText] = useUIState(uiKey('sourceUrlsText'), '');
    const [olympiadIntensity, setOlympiadIntensity] = useUIState<OlympiadIntensity>(uiKey('olympiadIntensity'), 'moderate');
    const [imageCopied, setImageCopied] = useState(false);
    const [error, setError] = useState('');

    const [autoMode, setAutoMode] = useState(initialAutoMode);

    // Curriculum + LO picker state.
    const [activeCurriculum, setActiveCurriculum] = useUIState<CurriculumKey>(uiKey('activeCurriculum'), 'none');
    const [loCatalog, setLoCatalog] = useState<LoCatalogRow[] | null>(
        learningObjectives.length > 0 ? learningObjectives : null,
    );
    const [selectedLoIds, setSelectedLoIds] = useUIState<string[]>(uiKey('selectedLoIds'), []);
    const [loFilter, setLoFilter] = useUIState(uiKey('loFilter'), '');
    const [expandedTopics, setExpandedTopics] = useUIState<string[]>(uiKey('expandedTopics'), []);
    const [expandedSubtopics, setExpandedSubtopics] = useUIState<string[]>(uiKey('expandedSubtopics'), []);
    function toggleTopic(t: string) {
        setExpandedTopics((prev) => (prev.includes(t) ? prev.filter((x) => x !== t) : [...prev, t]));
    }
    function toggleSubtopic(key: string) {
        setExpandedSubtopics((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));
    }

    const [generating, setGenerating] = useState(false);
    const [generateError, setGenerateError] = useState('');
    const [lastResult, setLastResult] = useState<null | {
        count: number; bankInserted: number; imageCount: number; imageFailures: number;
        provider: string; model: string;
    }>(null);
    const [preview, setPreview] = useState<GeneratedQuestion[]>([]);

    // Fetch the LO catalog when the chosen curriculum changes (parity with
    // the original fetch to /api/teacher/learning-objectives).
    useEffect(() => {
        if (!curriculumAllowed || activeCurriculum === 'none') {
            setLoCatalog(null);
            return;
        }
        const params = new URLSearchParams();
        params.set('curriculum', activeCurriculum);
        if (acctSubject) params.set('subject', acctSubject);
        fetch(`${basePath}/ai-generate/learning-objectives?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((j: { learningObjectives?: LoCatalogRow[] } | null) => setLoCatalog(j?.learningObjectives ?? []))
            .catch(() => setLoCatalog([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [curriculumAllowed, activeCurriculum]);

    // Mask raw form state with the admin gates so the prompt math uses 0 for
    // any locked difficulty/type.
    const maskedDifficultyPercents = useMemo(
        () => ({
            remember: difficultyAllowed.remember ? difficultyPercents.remember : 0,
            understand: difficultyAllowed.understand ? difficultyPercents.understand : 0,
            apply: difficultyAllowed.apply ? difficultyPercents.apply : 0,
            analyze: difficultyAllowed.analyze ? difficultyPercents.analyze : 0,
            evaluate: difficultyAllowed.evaluate ? difficultyPercents.evaluate : 0,
            create: difficultyAllowed.create ? difficultyPercents.create : 0,
            olympiad: difficultyAllowed.olympiad ? difficultyPercents.olympiad : 0,
        }),
        [difficultyAllowed, difficultyPercents],
    );
    const maskedTypeCounts = useMemo(
        () => ({
            single_choice: typeAllowed.single_choice ? typeCounts.single_choice : 0,
            multi_select: typeAllowed.multi_select ? typeCounts.multi_select : 0,
            short_text: typeAllowed.short_text ? typeCounts.short_text : 0,
            numeric: typeAllowed.numeric ? typeCounts.numeric : 0,
            essay: typeAllowed.essay ? typeCounts.essay : 0,
        }),
        [typeAllowed, typeCounts],
    );
    const effectiveMediaImageCount = useMemo(
        () => (mediaImageAllowed ? Math.max(0, Math.min(mediaImageCount, totalCount)) : 0),
        [mediaImageAllowed, mediaImageCount, totalCount],
    );
    const effectiveMediaTableCount = useMemo(
        () => (mediaTableAllowed ? Math.max(0, Math.min(mediaTableCount, totalCount)) : 0),
        [mediaTableAllowed, mediaTableCount, totalCount],
    );
    const includeMedia = effectiveMediaImageCount > 0;

    const selectedLearningObjectives = useMemo(() => {
        if (!curriculumAllowed || !loCatalog || selectedLoIds.length === 0) return [];
        const byId = new Map(loCatalog.map((l) => [l.id, l]));
        return selectedLoIds
            .map((id) => byId.get(id))
            .filter((l): l is LoCatalogRow => !!l)
            .map((l) => ({ topic: l.topic, subtopic: l.subtopic, text: l.text }));
    }, [curriculumAllowed, loCatalog, selectedLoIds]);

    const difficultyPercentSum =
        maskedDifficultyPercents.remember + maskedDifficultyPercents.understand + maskedDifficultyPercents.apply +
        maskedDifficultyPercents.analyze + maskedDifficultyPercents.evaluate + maskedDifficultyPercents.create +
        maskedDifficultyPercents.olympiad;
    const typeTotal =
        maskedTypeCounts.single_choice + maskedTypeCounts.multi_select + maskedTypeCounts.short_text +
        maskedTypeCounts.numeric + maskedTypeCounts.essay;

    const effectiveDifficultyCounts = useMemo(
        () => distributeByPercents(totalCount, maskedDifficultyPercents) as typeof difficultyPercents,
        [totalCount, maskedDifficultyPercents],
    );

    const sourceUrls = useMemo(
        () => sourceUrlsText.split(/\r?\n/).map((u) => u.trim()).filter((u) => u.length > 0),
        [sourceUrlsText],
    );

    const isCurriculumActive = activeCurriculum !== 'none';
    const uniqueLoTopics = useMemo(() => {
        if (!isCurriculumActive) return [];
        const seen = new Set<string>();
        const ordered: string[] = [];
        for (const lo of selectedLearningObjectives) {
            const key = lo.topic.trim();
            if (!key) continue;
            const lower = key.toLowerCase();
            if (seen.has(lower)) continue;
            seen.add(lower);
            ordered.push(key);
        }
        return ordered;
    }, [isCurriculumActive, selectedLearningObjectives]);
    const uniqueLoSubtopics = useMemo(() => {
        if (!isCurriculumActive) return [];
        const seen = new Set<string>();
        const ordered: string[] = [];
        for (const lo of selectedLearningObjectives) {
            const key = (lo.subtopic ?? '').trim();
            if (!key) continue;
            const lower = key.toLowerCase();
            if (seen.has(lower)) continue;
            seen.add(lower);
            ordered.push(key);
        }
        return ordered;
    }, [isCurriculumActive, selectedLearningObjectives]);
    const effectiveTopic = isCurriculumActive ? uniqueLoTopics.join(', ') : topic;
    const effectiveSubtopic = isCurriculumActive ? uniqueLoSubtopics.join(', ') : subtopic;
    const effectiveSubject = subjectAccountLocked ? acctSubject : '';

    // Body sent to the server's run endpoint AND used to derive the manual
    // prompt download (the server is the single source of prompt truth).
    const requestBody = useMemo(
        () => ({
            language,
            subject: effectiveSubject,
            topic: effectiveTopic,
            subtopic: effectiveSubtopic,
            gradeLevel,
            totalCount,
            difficultyCounts: effectiveDifficultyCounts,
            typeCounts: maskedTypeCounts,
            mediaImageCount: effectiveMediaImageCount,
            mediaTableCount: effectiveMediaTableCount,
            selectedLoIds: isCurriculumActive ? selectedLoIds : [],
            extraInstructions,
            sourceUrls,
            olympiadIntensity,
        }),
        [
            language, effectiveSubject, effectiveTopic, effectiveSubtopic, gradeLevel, totalCount,
            effectiveDifficultyCounts, maskedTypeCounts, effectiveMediaImageCount, effectiveMediaTableCount,
            isCurriculumActive, selectedLoIds, extraInstructions, sourceUrls, olympiadIntensity,
        ],
    );

    async function onGenerateNow() {
        setGenerating(true);
        setGenerateError('');
        setLastResult(null);
        setPreview([]);
        try {
            const res = await fetch(`${basePath}/ai-generate/run`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(requestBody),
            });
            const j = (await res.json().catch(() => ({}))) as Record<string, unknown>;
            if (!res.ok) {
                if (res.status === 503) setAutoMode((prev) => ({ ...prev, autoEnabled: false }));
                throw new Error((j.error as string) ?? `Generate failed (${res.status}).`);
            }
            setPreview((j.questions as GeneratedQuestion[]) ?? []);
            setLastResult({
                count: Number(j.count ?? 0),
                bankInserted: Number(j.bankInserted ?? 0),
                imageCount: Number(j.imageCount ?? 0),
                imageFailures: Number(j.imageFailures ?? 0),
                provider: String(j.provider ?? ''),
                model: String(j.model ?? ''),
            });
        } catch (err) {
            setGenerateError(err instanceof Error ? err.message : 'Generate failed.');
        } finally {
            setGenerating(false);
        }
    }

    // Phase 1 download: ask the server for the exact prompt JSON. We POST the
    // same body and stash the returned prompt into a JSON envelope.
    async function onDownloadQuestionPromptJson() {
        try {
            const res = await fetch(`${basePath}/ai-generate/prompt`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(requestBody),
            });
            if (!res.ok) {
                const j = (await res.json().catch(() => ({}))) as { error?: string };
                throw new Error(j.error ?? `Could not build prompt (${res.status}).`);
            }
            const j = (await res.json()) as { questionPrompt: string; config: unknown };
            const payload = {
                version: 1,
                type: 'exam-question-generation',
                generatedAt: new Date().toISOString(),
                config: j.config,
                prompt: j.questionPrompt,
            };
            downloadBlob(
                JSON.stringify(payload, null, 2),
                `${safeName(effectiveSubject || 'exam')}-question-prompt.json`,
                'application/json',
            );
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not build the prompt.');
        }
    }

    const [imagePrompt, setImagePrompt] = useState('');
    // Keep the image prompt in sync with the form by asking the server (the
    // prompt builder is server-side). Debounced lightly via requestBody deps.
    useEffect(() => {
        let cancelled = false;
        fetch(`${basePath}/ai-generate/prompt`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(requestBody),
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((j: { imagePrompt?: string } | null) => {
                if (!cancelled && j?.imagePrompt) setImagePrompt(j.imagePrompt);
            })
            .catch(() => {});
        return () => {
            cancelled = true;
        };
    }, [requestBody, basePath]);

    async function onCopyImagePrompt() {
        try {
            await navigator.clipboard.writeText(imagePrompt);
            setImageCopied(true);
            setTimeout(() => setImageCopied(false), 1800);
        } catch {
            setError('Could not access clipboard. Select the text and copy manually.');
        }
    }

    if (!aiEnabled) {
        return (
            <>
                <header className="teacher-page-header">
                    <div>
                        <h1>Generate exam with AI</h1>
                        <p>This feature is disabled for your account.</p>
                    </div>
                    <a className="ghost-button" href={`${basePath}/exams`}>
                        <ArrowLeft size={17} aria-hidden /> Back to exams
                    </a>
                </header>
                <section className="admin-panel">
                    <p>
                        Your administrator has not enabled the <code>ai.generate</code> capability for this account. Ask
                        them to turn it on in <strong>Admin → Teachers → Capabilities</strong>.
                    </p>
                </section>
            </>
        );
    }

    const loPickerVisible = curriculumAllowed && activeCurriculum !== 'none' && loCatalog && loCatalog.length > 0;

    return (
        <>
            <header className="teacher-page-header">
                <div>
                    <h1>Generate exam with AI</h1>
                    <p>
                        Two phases. <strong>Phase 1</strong>: download the question prompt JSON and run it in ChatGPT /
                        Claude / Gemini to get a <code>questions.json</code>. <strong>Phase 2</strong>: copy the image
                        prompt + that questions.json into an AI image generator to produce a <code>media/</code> folder.
                        Zip them together and upload via <strong>Question Bank → Upload questions</strong>.
                    </p>
                </div>
                <a className="ghost-button" href={`${basePath}/exams`}>
                    <ArrowLeft size={17} aria-hidden /> Back to exams
                </a>
            </header>

            {error ? <p className="form-error">{error}</p> : null}

            <section className="admin-panel ai-three-col-section">
                <div className="section-title-row">
                    <div>
                        <h2>Parameters</h2>
                        <p>Edit anything you need. The prompt regenerates as you type.</p>
                    </div>
                    <Sparkles size={20} aria-hidden />
                </div>
                <div className="ai-three-col">
                    {/* ----- Panel 1 · Scope ----- */}
                    <fieldset className="ai-param-card">
                        <legend>Scope</legend>
                        {curriculumAllowed ? (
                            <div style={{ marginTop: 10 }}>
                                <label className="ai-param-field" style={{ maxWidth: 480, margin: 0 }}>
                                    <span className="ai-param-label">
                                        Curriculum
                                        {activeCurriculum !== 'none' ? (
                                            <span className="ai-param-hint">
                                                Topic &amp; Subtopic come from the LOs you pick below
                                            </span>
                                        ) : null}
                                    </span>
                                    <select
                                        value={activeCurriculum}
                                        onChange={(e) => {
                                            setActiveCurriculum(e.target.value as CurriculumKey);
                                            setSelectedLoIds([]);
                                        }}
                                    >
                                        {CURRICULUM_OPTIONS.map((opt) => (
                                            <option key={opt.key} value={opt.key}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                        ) : null}

                        {loPickerVisible ? (
                            <div className="ai-param-field ai-card-grow ai-lo-wrapper" style={{ marginTop: 12 }}>
                                <span className="ai-param-label">
                                    Learning objectives
                                    {selectedLearningObjectives.length > 0 ? (
                                        <span className="ai-param-hint">
                                            {selectedLearningObjectives.length} of {loCatalog!.length} selected — these
                                            drive the AI&apos;s content scope
                                        </span>
                                    ) : (
                                        <span className="ai-param-hint">
                                            {loCatalog!.length} available · tick any to include in the exam
                                        </span>
                                    )}
                                </span>
                                <input
                                    type="search"
                                    placeholder="Filter by topic, subtopic, or LO text…"
                                    value={loFilter}
                                    onChange={(e) => setLoFilter(e.target.value)}
                                    style={{ width: '100%', marginBottom: 4 }}
                                />
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center', marginBottom: 6 }}>
                                    <button
                                        type="button"
                                        className="ghost-button"
                                        onClick={() => {
                                            const topics = Array.from(new Set(loCatalog!.map((l) => l.topic)));
                                            const subs = Array.from(
                                                new Set(
                                                    loCatalog!
                                                        .filter((l) => l.subtopic)
                                                        .map((l) => `${l.topic}::${l.subtopic ?? ''}`),
                                                ),
                                            );
                                            setExpandedTopics(topics);
                                            setExpandedSubtopics(subs);
                                        }}
                                        style={{ padding: '3px 8px', fontSize: '0.74rem', width: 'auto', minHeight: 24 }}
                                    >
                                        Expand
                                    </button>
                                    <button
                                        type="button"
                                        className="ghost-button"
                                        onClick={() => {
                                            setExpandedTopics([]);
                                            setExpandedSubtopics([]);
                                        }}
                                        disabled={expandedTopics.length === 0 && expandedSubtopics.length === 0}
                                        style={{ padding: '3px 8px', fontSize: '0.74rem', width: 'auto', minHeight: 24 }}
                                    >
                                        Collapse
                                    </button>
                                    <button
                                        type="button"
                                        className="ghost-button"
                                        onClick={() => setSelectedLoIds(loCatalog!.map((l) => l.id))}
                                        disabled={selectedLoIds.length === loCatalog!.length}
                                        style={{ padding: '3px 8px', fontSize: '0.74rem', width: 'auto', minHeight: 24 }}
                                    >
                                        Select all
                                    </button>
                                    <button
                                        type="button"
                                        className="ghost-button"
                                        onClick={() => setSelectedLoIds([])}
                                        disabled={selectedLoIds.length === 0}
                                        style={{ padding: '3px 8px', fontSize: '0.74rem', width: 'auto', minHeight: 24 }}
                                    >
                                        Clear
                                    </button>
                                </div>
                                <div className="lo-picker">
                                    {(() => {
                                        const f = loFilter.trim().toLowerCase();
                                        const filtered = f
                                            ? loCatalog!.filter(
                                                  (l) =>
                                                      l.topic.toLowerCase().includes(f) ||
                                                      (l.subtopic ?? '').toLowerCase().includes(f) ||
                                                      l.text.toLowerCase().includes(f),
                                              )
                                            : loCatalog!;
                                        const grouped = new Map<string, Map<string, LoCatalogRow[]>>();
                                        for (const l of filtered) {
                                            const t = l.topic;
                                            const s = l.subtopic ?? '(general)';
                                            if (!grouped.has(t)) grouped.set(t, new Map());
                                            const sub = grouped.get(t)!;
                                            if (!sub.has(s)) sub.set(s, []);
                                            sub.get(s)!.push(l);
                                        }
                                        if (grouped.size === 0) {
                                            return (
                                                <p style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
                                                    No learning objectives match &quot;{loFilter}&quot;.
                                                </p>
                                            );
                                        }
                                        const applyGroup = (change: { add?: string[]; remove?: string[] }) =>
                                            setSelectedLoIds((prev) => {
                                                const set = new Set(prev);
                                                change.add?.forEach((id) => set.add(id));
                                                change.remove?.forEach((id) => set.delete(id));
                                                return Array.from(set);
                                            });
                                        const filterActive = f.length > 0;
                                        return Array.from(grouped.entries()).map(([t, sm]) => {
                                            const topicIds: string[] = [];
                                            for (const list of sm.values()) for (const l of list) topicIds.push(l.id);
                                            const topicOpen = filterActive || expandedTopics.includes(t);
                                            return (
                                                <div className="lo-picker-topic" key={t}>
                                                    <div className="lo-picker-row-head">
                                                        <button
                                                            type="button"
                                                            className="lo-picker-chev"
                                                            aria-expanded={topicOpen}
                                                            aria-label={`${topicOpen ? 'Collapse' : 'Expand'} ${t}`}
                                                            onClick={() => toggleTopic(t)}
                                                        >
                                                            {topicOpen ? (
                                                                <ChevronDown size={14} aria-hidden />
                                                            ) : (
                                                                <ChevronRight size={14} aria-hidden />
                                                            )}
                                                        </button>
                                                        <GroupToggle
                                                            ids={topicIds}
                                                            selectedIds={selectedLoIds}
                                                            onChange={applyGroup}
                                                            label={t}
                                                            level="topic"
                                                        />
                                                    </div>
                                                    {topicOpen
                                                        ? Array.from(sm.entries()).map(([sub, items]) => {
                                                              const subtopicIds = items.map((l) => l.id);
                                                              const subKey = `${t}::${sub}`;
                                                              const subtopicOpen =
                                                                  filterActive || expandedSubtopics.includes(subKey);
                                                              if (sub === '(general)') {
                                                                  return (
                                                                      <ul key={sub} className="lo-picker-list">
                                                                          {items.map((l) => (
                                                                              <li key={l.id}>
                                                                                  <label className="lo-picker-row">
                                                                                      <input
                                                                                          type="checkbox"
                                                                                          checked={selectedLoIds.includes(l.id)}
                                                                                          onChange={(e) =>
                                                                                              setSelectedLoIds((prev) =>
                                                                                                  e.target.checked
                                                                                                      ? Array.from(new Set([...prev, l.id]))
                                                                                                      : prev.filter((id) => id !== l.id),
                                                                                              )
                                                                                          }
                                                                                      />
                                                                                      <span>{l.text}</span>
                                                                                  </label>
                                                                              </li>
                                                                          ))}
                                                                      </ul>
                                                                  );
                                                              }
                                                              return (
                                                                  <div className="lo-picker-subtopic" key={sub}>
                                                                      <div className="lo-picker-row-head">
                                                                          <button
                                                                              type="button"
                                                                              className="lo-picker-chev"
                                                                              aria-expanded={subtopicOpen}
                                                                              aria-label={`${subtopicOpen ? 'Collapse' : 'Expand'} ${sub}`}
                                                                              onClick={() => toggleSubtopic(subKey)}
                                                                          >
                                                                              {subtopicOpen ? (
                                                                                  <ChevronDown size={13} aria-hidden />
                                                                              ) : (
                                                                                  <ChevronRight size={13} aria-hidden />
                                                                              )}
                                                                          </button>
                                                                          <GroupToggle
                                                                              ids={subtopicIds}
                                                                              selectedIds={selectedLoIds}
                                                                              onChange={applyGroup}
                                                                              label={sub}
                                                                              level="subtopic"
                                                                          />
                                                                      </div>
                                                                      {subtopicOpen ? (
                                                                          <ul className="lo-picker-list">
                                                                              {items.map((l) => (
                                                                                  <li key={l.id}>
                                                                                      <label className="lo-picker-row">
                                                                                          <input
                                                                                              type="checkbox"
                                                                                              checked={selectedLoIds.includes(l.id)}
                                                                                              onChange={(e) =>
                                                                                                  setSelectedLoIds((prev) =>
                                                                                                      e.target.checked
                                                                                                          ? Array.from(new Set([...prev, l.id]))
                                                                                                          : prev.filter((id) => id !== l.id),
                                                                                                  )
                                                                                              }
                                                                                          />
                                                                                          <span>{l.text}</span>
                                                                                      </label>
                                                                                  </li>
                                                                              ))}
                                                                          </ul>
                                                                      ) : null}
                                                                  </div>
                                                              );
                                                          })
                                                        : null}
                                                </div>
                                            );
                                        });
                                    })()}
                                </div>
                            </div>
                        ) : null}

                        {curriculumAllowed && activeCurriculum !== 'none' && loCatalog && loCatalog.length === 0 ? (
                            <p className="form-error" style={{ marginTop: 12, fontWeight: 400 }}>
                                No learning objectives uploaded yet for this curriculum + subject combination. Go to{' '}
                                <strong>Curriculum</strong> in the sidebar to upload an Excel for{' '}
                                <em>{CURRICULUM_OPTIONS.find((c) => c.key === activeCurriculum)?.label}</em>.
                            </p>
                        ) : null}

                        <label className="ai-param-field">
                            <span className="ai-param-label">
                                Language
                                {!languageAdminAllowed ? <span className="ai-param-hint">locked by admin</span> : null}
                            </span>
                            <input
                                value={language}
                                onChange={(e) => {
                                    if (!languageAdminAllowed) return;
                                    setLanguage(e.target.value);
                                }}
                                placeholder="English"
                                readOnly={!languageAdminAllowed}
                                aria-readonly={!languageAdminAllowed}
                                title={
                                    !languageAdminAllowed
                                        ? "Language is locked. Ask your admin to enable 'AI generation parameters → Language'."
                                        : undefined
                                }
                            />
                        </label>

                        {/* Subject: locked to account subject; admin gate also applies.
                            When neither account-locked nor admin-allowed it's shown read-only. */}
                        <label className="ai-param-field">
                            <span className="ai-param-label">
                                Subject
                                {subjectAccountLocked ? (
                                    <span className="ai-param-hint">from your account</span>
                                ) : !subjectAdminAllowed ? (
                                    <span className="ai-param-hint">locked by admin</span>
                                ) : null}
                            </span>
                            <input
                                value={effectiveSubject}
                                readOnly
                                aria-readonly
                                placeholder={subjectAccountLocked ? undefined : '(set on your account)'}
                                title="Subject is driven by your account subject."
                            />
                        </label>

                        <label className="ai-param-field">
                            <span className="ai-param-label">
                                Grade / level
                                <span className="ai-param-hint">optional</span>
                            </span>
                            <input
                                value={gradeLevel}
                                onChange={(e) => setGradeLevel(e.target.value.toUpperCase())}
                                placeholder="GRADE 10 / SMA"
                                style={{ textTransform: 'uppercase' }}
                            />
                        </label>

                        {activeCurriculum === 'none' ? (
                            <>
                                <label className="ai-param-field">
                                    <span className="ai-param-label">Topic</span>
                                    <input
                                        value={topic}
                                        onChange={(e) => setTopic(e.target.value.toUpperCase())}
                                        placeholder="ALGEBRA"
                                        style={{ textTransform: 'uppercase' }}
                                    />
                                </label>
                                <label className="ai-param-field">
                                    <span className="ai-param-label">
                                        Subtopic
                                        <span className="ai-param-hint">optional</span>
                                    </span>
                                    <input
                                        value={subtopic}
                                        onChange={(e) => setSubtopic(e.target.value.toUpperCase())}
                                        placeholder="LINEAR EQUATIONS"
                                        style={{ textTransform: 'uppercase' }}
                                    />
                                </label>
                            </>
                        ) : null}
                        {!loPickerVisible ? <div className="ai-card-grow" aria-hidden /> : null}
                    </fieldset>

                    {/* ----- Panel 2 · Question mix ----- */}
                    <fieldset className="ai-param-card">
                        <legend>Question mix</legend>
                        <label className="ai-param-field">
                            <span className="ai-param-label">Total questions</span>
                            <input
                                type="number"
                                min={1}
                                max={Math.max(1, totalCap)}
                                value={totalCount}
                                onChange={(e) =>
                                    setTotalCount(Math.max(1, Math.min(totalCap, Number(e.target.value) || 0)))
                                }
                            />
                        </label>

                        <div className="composition-grid" style={{ marginTop: 12 }}>
                            <div className="composition-block">
                                <span>
                                    Difficulty (%){' '}
                                    {difficultyPercentSum !== 100 ? (
                                        <em style={{ color: 'var(--amber, #b45309)' }}>
                                            (sum {difficultyPercentSum}% ≠ 100%)
                                        </em>
                                    ) : null}
                                </span>
                                <ul>
                                    {DIFFICULTY_KEYS.map((key) => {
                                        const allowed = difficultyAllowed[key];
                                        const percent = allowed ? difficultyPercents[key] : 0;
                                        const effective = effectiveDifficultyCounts[key];
                                        return (
                                            <li key={key}>
                                                <label style={{ display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'space-between' }}>
                                                    <span>
                                                        {DIFFICULTY_LABELS[key]}
                                                        {!allowed ? (
                                                            <em style={{ color: 'var(--muted)', fontSize: '0.78rem', marginLeft: 6 }}>
                                                                (locked by admin)
                                                            </em>
                                                        ) : (
                                                            <em
                                                                style={{ color: 'var(--muted)', fontSize: '0.78rem', marginLeft: 6 }}
                                                                title={`Effective count = ${percent}% × ${totalCount} total = ${effective} question${effective === 1 ? '' : 's'}.`}
                                                            >
                                                                ≈ {effective} q
                                                            </em>
                                                        )}
                                                    </span>
                                                    <input
                                                        type="number"
                                                        min={0}
                                                        max={100}
                                                        step={1}
                                                        disabled={!allowed}
                                                        style={{ width: 80 }}
                                                        value={percent}
                                                        onChange={(e) =>
                                                            setDifficultyPercents({
                                                                ...difficultyPercents,
                                                                [key]: Math.max(0, Math.min(100, Number(e.target.value) || 0)),
                                                            })
                                                        }
                                                    />
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                            <div className="composition-block">
                                <span>
                                    Type counts{' '}
                                    {typeTotal !== totalCount ? (
                                        <em style={{ color: 'var(--amber, #b45309)' }}>
                                            (sum {typeTotal} ≠ total {totalCount})
                                        </em>
                                    ) : null}
                                </span>
                                <ul>
                                    {TYPE_KEYS.map((key) => {
                                        const allowed = typeAllowed[key];
                                        const value = allowed ? typeCounts[key] : 0;
                                        return (
                                            <li key={key}>
                                                <label style={{ display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'space-between' }}>
                                                    <span>
                                                        {TYPE_LABELS[key]}
                                                        {!allowed ? (
                                                            <em style={{ color: 'var(--muted)', fontSize: '0.78rem', marginLeft: 6 }}>
                                                                (locked by admin)
                                                            </em>
                                                        ) : null}
                                                    </span>
                                                    <input
                                                        type="number"
                                                        min={0}
                                                        max={totalCap}
                                                        disabled={!allowed}
                                                        style={{ width: 80 }}
                                                        value={value}
                                                        onChange={(e) =>
                                                            setTypeCounts({
                                                                ...typeCounts,
                                                                [key]: Math.max(0, Math.min(totalCap, Number(e.target.value) || 0)),
                                                            })
                                                        }
                                                    />
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        </div>

                        <div className="composition-block" style={{ marginTop: 12 }}>
                            <span>Media</span>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, alignItems: 'center' }}>
                                <label
                                    style={{ display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'space-between', margin: 0 }}
                                    title={mediaImageAllowed ? undefined : 'Image suggestions are disabled for your account.'}
                                >
                                    <span style={{ fontSize: '0.86rem' }}>
                                        Images
                                        {!mediaImageAllowed ? (
                                            <em style={{ color: 'var(--muted)', fontSize: '0.78rem', marginLeft: 6 }}>
                                                (locked by admin)
                                            </em>
                                        ) : null}
                                    </span>
                                    <input
                                        type="number"
                                        min={0}
                                        max={totalCount}
                                        disabled={!mediaImageAllowed}
                                        value={mediaImageAllowed ? mediaImageCount : 0}
                                        onChange={(e) =>
                                            setMediaImageCount(Math.max(0, Math.min(totalCount, Number(e.target.value) || 0)))
                                        }
                                    />
                                </label>
                                <label
                                    style={{ display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'space-between', margin: 0 }}
                                    title={mediaTableAllowed ? undefined : 'Markdown tables are disabled for your account.'}
                                >
                                    <span style={{ fontSize: '0.86rem' }}>
                                        Tables
                                        {!mediaTableAllowed ? (
                                            <em style={{ color: 'var(--muted)', fontSize: '0.78rem', marginLeft: 6 }}>
                                                (locked by admin)
                                            </em>
                                        ) : null}
                                    </span>
                                    <input
                                        type="number"
                                        min={0}
                                        max={totalCount}
                                        disabled={!mediaTableAllowed}
                                        value={mediaTableAllowed ? mediaTableCount : 0}
                                        onChange={(e) =>
                                            setMediaTableCount(Math.max(0, Math.min(totalCount, Number(e.target.value) || 0)))
                                        }
                                    />
                                </label>
                            </div>
                            {effectiveMediaImageCount > 0 || effectiveMediaTableCount > 0 ? (
                                <small style={{ display: 'block', marginTop: 6, color: 'var(--muted)', fontSize: '0.75rem' }}>
                                    {effectiveMediaImageCount > 0 ? `${effectiveMediaImageCount} of ${totalCount} with image` : ''}
                                    {effectiveMediaImageCount > 0 && effectiveMediaTableCount > 0 ? ' · ' : ''}
                                    {effectiveMediaTableCount > 0 ? `${effectiveMediaTableCount} of ${totalCount} with table` : ''}
                                </small>
                            ) : null}
                        </div>

                        {effectiveDifficultyCounts.olympiad > 0 ? (
                            <div className="ai-param-field" style={{ marginTop: 16 }}>
                                <span className="ai-param-label">
                                    Olympiad intensity
                                    <span className="ai-param-hint">keeps olympiad-tagged questions from drifting too hard</span>
                                </span>
                                <div
                                    role="radiogroup"
                                    aria-label="Olympiad intensity"
                                    style={{ display: 'grid', gridTemplateColumns: `repeat(${OLYMPIAD_INTENSITIES.length}, 1fr)`, gap: 6 }}
                                >
                                    {OLYMPIAD_INTENSITIES.map((opt) => {
                                        const active = olympiadIntensity === opt.value;
                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                role="radio"
                                                aria-checked={active}
                                                title={opt.hint}
                                                className={active ? 'primary-button' : 'ghost-button'}
                                                style={{ padding: '6px 12px', fontSize: '0.85rem', width: 'auto', minHeight: 34 }}
                                                onClick={() => setOlympiadIntensity(opt.value)}
                                            >
                                                {opt.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : null}

                        <div className="ai-card-grow" aria-hidden />
                    </fieldset>

                    {/* ----- Panel 3 · Generated prompts ----- */}
                    <fieldset className="ai-param-card">
                        <legend>Generated prompts</legend>
                        <p className="ai-prompt-tagline" style={{ marginBottom: 4 }}>
                            {autoMode.autoEnabled ? (
                                `Auto mode: the server can call ${autoMode.provider} (${autoMode.model}) end-to-end and show you a live preview. Or run the two phases manually below.`
                            ) : (
                                <>
                                    Two manual phases — see &quot;What to do next&quot; below.{' '}
                                    {!autoMode.autoEnabled ? (
                                        <em style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                            (Server-side &quot;Generate now&quot; is unavailable because no API key is
                                            configured for the active provider.)
                                        </em>
                                    ) : null}
                                </>
                            )}
                        </p>

                        <div className="ai-phase-block">
                            <h3>Phase 1 · Generate questions</h3>
                            <p>
                                Download the JSON prompt file and upload it to your AI (ChatGPT / Claude / Gemini). Save
                                the AI&apos;s reply as <code>questions.json</code>.
                            </p>
                            <button
                                type="button"
                                className="primary-button"
                                onClick={onDownloadQuestionPromptJson}
                                style={{ width: 'auto', alignSelf: 'flex-start' }}
                            >
                                <Download size={15} aria-hidden /> Download question prompt (JSON)
                            </button>
                        </div>

                        <div className="ai-phase-block ai-card-grow">
                            <h3>Phase 2 · Generate images</h3>
                            <p>
                                After you have the <code>questions.json</code> from Phase 1, copy the image prompt below
                                into an AI image generator (DALL·E, Midjourney, Bing Image Creator, …) along with the
                                questions.json. The AI should produce <code>q1.png</code>, <code>q2.png</code>, … inside a{' '}
                                <code>media/</code> folder. Zip <code>questions.json</code> + <code>media/</code> and
                                upload via Question Bank.
                            </p>
                            <div className="ai-prompt-header">
                                <button className="ghost-button" type="button" onClick={onCopyImagePrompt}>
                                    <Copy size={14} aria-hidden />
                                    {imageCopied ? 'Copied!' : 'Copy'}
                                </button>
                            </div>
                            <textarea readOnly className="ai-prompt-output ai-card-grow" value={imagePrompt} spellCheck={false} />
                        </div>

                        {autoMode.autoEnabled ? (
                            <>
                                {generateError ? <p className="form-error">{generateError}</p> : null}
                                {lastResult ? (
                                    <p className="form-success" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <Sparkles size={15} aria-hidden />
                                        Generated {lastResult.count} question{lastResult.count === 1 ? '' : 's'}
                                        {lastResult.imageCount > 0
                                            ? ` + ${lastResult.imageCount} image${lastResult.imageCount === 1 ? '' : 's'}`
                                            : ''}
                                        {lastResult.imageFailures > 0
                                            ? ` (${lastResult.imageFailures} image${lastResult.imageFailures === 1 ? '' : 's'} failed to render)`
                                            : ''}
                                        .{' '}
                                        {lastResult.bankInserted > 0 ? (
                                            <>
                                                <strong>{lastResult.bankInserted}</strong> added to Question Bank
                                                automatically.
                                            </>
                                        ) : (
                                            <>Bank auto-insert skipped (see server logs).</>
                                        )}
                                    </p>
                                ) : null}
                                <button className="primary-button" type="button" onClick={onGenerateNow} disabled={generating}>
                                    <Sparkles size={17} aria-hidden />
                                    {generating
                                        ? `Calling ${autoMode.provider}…`
                                        : lastResult
                                          ? 'Generate again'
                                          : 'Generate now'}
                                </button>
                            </>
                        ) : null}
                    </fieldset>
                </div>
            </section>

            {/* Preview of generated questions (auto mode result) */}
            {preview.length > 0 ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Preview · {preview.length} generated question{preview.length === 1 ? '' : 's'}</h2>
                            <p>These have been added to your Question Bank. Review them there to edit, delete, or attach to an exam.</p>
                        </div>
                    </div>
                    <ol className="import-preview-list">
                        {preview.map((q, i) => (
                            <li key={i}>
                                <span>
                                    <strong>{q.type}</strong> · {q.difficulty} · {q.points} pt
                                    {q.topic ? ` · ${q.topic}` : ''}
                                    {q.subtopic ? ` › ${q.subtopic}` : ''}
                                </span>
                                <span>{q.prompt}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}

            {/* Bottom row — notes & sources + workflow steps */}
            <section className="admin-panel ai-bottom-row-section">
                <div className="ai-two-col-bottom">
                    <fieldset className="ai-param-card">
                        <legend>Notes &amp; sources</legend>
                        <label className="ai-param-field">
                            <span className="ai-param-label">
                                Source URLs
                                <span className="ai-param-hint">optional</span>
                            </span>
                            <textarea
                                rows={3}
                                value={sourceUrlsText}
                                onChange={(e) => setSourceUrlsText(e.target.value)}
                                placeholder={'https://example.com/syllabus.pdf\nhttps://en.wikipedia.org/wiki/Photosynthesis'}
                                spellCheck={false}
                            />
                            <small>
                                One URL per line. The AI will treat these as authoritative source material (textbook
                                page, syllabus, lecture notes, etc.).{' '}
                                {sourceUrls.length > 0
                                    ? `${sourceUrls.length} URL${sourceUrls.length === 1 ? '' : 's'} will be included.`
                                    : "Leave blank to use the AI's general knowledge."}
                            </small>
                        </label>

                        <label className="ai-param-field ai-card-grow">
                            <span className="ai-param-label">
                                Extra instructions
                                <span className="ai-param-hint">optional</span>
                            </span>
                            <textarea
                                rows={3}
                                value={extraInstructions}
                                onChange={(e) => setExtraInstructions(e.target.value.toUpperCase())}
                                style={{ textTransform: 'uppercase' }}
                                placeholder="E.G. INCLUDE WORD PROBLEMS. AVOID QUESTIONS THAT REQUIRE A CALCULATOR. USE REAL-WORLD CONTEXTS."
                            />
                        </label>

                        {includeMedia ? (
                            <p style={{ color: 'var(--muted)', fontSize: '0.82rem', marginTop: 4 }}>
                                With <strong>{effectiveMediaImageCount}</strong> image
                                {effectiveMediaImageCount === 1 ? '' : 's'} requested, the Phase 1 AI will fill{' '}
                                <code>mediaPrompt</code> with a description of what to draw on those chosen questions.
                                Phase 2 then takes those descriptions and renders them into <code>q1.png</code>,{' '}
                                <code>q2.png</code>, … inside a <code>media/</code> folder.
                            </p>
                        ) : null}
                    </fieldset>

                    <fieldset className="ai-param-card">
                        <legend>What to do next</legend>
                        <ol className="ai-steps">
                            <li>
                                <strong>1. Download the question prompt JSON</strong> from Panel 3 above (<em>Phase 1</em>).
                            </li>
                            <li>
                                <strong>2. Run it through your AI</strong> (ChatGPT / Claude / Gemini). Upload the JSON
                                file, let the AI reply with a JSON array, and save that reply as{' '}
                                <code>questions.json</code>. If the AI wraps it in markdown fences ( ```json … ``` ),
                                strip those.
                            </li>
                            {includeMedia ? (
                                <li>
                                    <strong>3. Generate the images</strong> (<em>Phase 2</em>). Copy the image prompt
                                    from Panel 3 and paste it into an AI image generator (DALL·E, Midjourney, Bing Image
                                    Creator, Stable Diffusion) along with your <code>questions.json</code>. Save each
                                    rendered image as <code>q1.png</code>, <code>q2.png</code>, … inside a folder named{' '}
                                    <code>media/</code>.
                                </li>
                            ) : null}
                            <li>
                                <strong>{includeMedia ? '4' : '3'}. Zip and upload.</strong> Put <code>questions.json</code>
                                {includeMedia ? (
                                    <>
                                        {' '}
                                        and the <code>media/</code> folder
                                    </>
                                ) : null}{' '}
                                into a folder, right-click → <em>Send to → Compressed (zipped) folder</em> (Windows) or{' '}
                                <em>Compress</em> (macOS). Then open{' '}
                                <a href={`${basePath}/bank`} style={{ textDecoration: 'underline' }}>
                                    <Library size={13} aria-hidden style={{ verticalAlign: 'middle' }} /> Question Bank
                                </a>{' '}
                                → <strong>Upload questions</strong> and pick the zip.
                            </li>
                        </ol>
                    </fieldset>
                </div>
            </section>
        </>
    );
}

// --- small DOM/util helpers -------------------------------------------------
function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}
function safeName(s: string): string {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'exam';
}
function downloadBlob(content: string, filename: string, mime: string) {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export default function AiGenerate() {
    return (
        <TeacherShell>
            <Head title="Teacher · Generate exam with AI" />
            <AiGenerateBody />
        </TeacherShell>
    );
}
