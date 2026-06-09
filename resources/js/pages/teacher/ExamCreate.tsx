import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ListOrdered, Pause, Save, ShieldAlert, Shuffle, Timer } from 'lucide-react';
import TeacherShell from '@/components/TeacherShell';

// ---------------------------------------------------------------------------
// Capability gate map (computed server-side in ExamManageController::formGates)
// ---------------------------------------------------------------------------
type Gates = {
    showDuration: boolean;
    showPassing: boolean;
    showMode: boolean;
    showShuffleQuestions: boolean;
    showShuffleOptions: boolean;
    showLanguage: boolean;
    showSeb: boolean;
    showTypeSingle: boolean;
    showTypeMulti: boolean;
    showTypeShortText: boolean;
    showTypeNumeric: boolean;
    showTypeEssay: boolean;
    // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
    showDifficultyRemember: boolean;
    showDifficultyUnderstand: boolean;
    showDifficultyApply: boolean;
    showDifficultyAnalyze: boolean;
    showDifficultyEvaluate: boolean;
    showDifficultyCreate: boolean;
    showDifficultyOlympiad: boolean;
    showMediaImage: boolean;
    showMediaTable: boolean;
    showSchedulingRow: boolean;
    showShuffleGroup: boolean;
    showModeRow: boolean;
    showTypeRow: boolean;
    showDifficultyRow: boolean;
    showMediaRow: boolean;
    showCompositionFieldset: boolean;
};

type TypeDistribution = {
    single_choice: number;
    multi_select: number;
    short_text: number;
    numeric: number;
    essay: number;
};
// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
type DifficultyDistribution = {
    remember: number;
    understand: number;
    apply: number;
    analyze: number;
    evaluate: number;
    create: number;
    olympiad: number;
};
type MediaTargets = { images: number; tables: number };

const OTHER_SUBJECT_VALUE = '__other__';

export type ExamFormData = {
    examCode: string;
    name: string;
    durationMinutes: number;
    passingGrade: number;
    generalInstructions: string;
    examMode: 'strict' | 'try_out';
    shuffleQuestions: boolean;
    shuffleOptions: boolean;
    language: string;
    subject: string;
    mediaBaseUrl: string;
    startTime: string;
    endTime: string;
    sebRequired: boolean;
    typeDistribution: TypeDistribution;
    difficultyDistribution: DifficultyDistribution;
    mediaTargets: MediaTargets;
};

export default function ExamCreate() {
    const { gates, subjectChoices, defaults } = usePage().props as any;
    const d = defaults;

    const form = useForm<ExamFormData>({
        examCode: '',
        name: '',
        durationMinutes: d.durationMinutes,
        passingGrade: d.passingGrade,
        generalInstructions: d.generalInstructions,
        examMode: d.examMode,
        shuffleQuestions: d.shuffleQuestions,
        shuffleOptions: d.shuffleOptions,
        language: d.language,
        subject: d.subject,
        mediaBaseUrl: d.mediaBaseUrl ?? '',
        startTime: isoToLocalInput(d.startTime),
        endTime: isoToLocalInput(d.endTime),
        sebRequired: d.sebRequired,
        typeDistribution: d.typeDistribution,
        difficultyDistribution: d.difficultyDistribution,
        mediaTargets: d.mediaTargets,
    });

    function onSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/teacher/exams');
    }

    return (
        <TeacherShell>
            <Head title="Teacher · Create exam" />
            <ExamForm
                mode="create"
                form={form}
                gates={gates as Gates}
                subjectChoices={subjectChoices as string[]}
                onSubmit={onSubmit}
                backHref="/teacher/exams"
            />
        </TeacherShell>
    );
}

// ---------------------------------------------------------------------------
// Shared presentation — used verbatim by ExamCreate and ExamEdit. Lives here
// (exported) and is imported by ExamEdit so the markup never drifts between
// the two flows, matching the original single-component create/edit form.
// ---------------------------------------------------------------------------
export function ExamForm({
    mode,
    form,
    gates: g,
    subjectChoices,
    onSubmit,
    backHref,
    examName,
}: {
    mode: 'create' | 'edit';
    form: ReturnType<typeof useForm<ExamFormData>>;
    gates: Gates;
    subjectChoices: string[];
    onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
    backHref: string;
    examName?: string;
}) {
    const isEdit = mode === 'edit';
    const { data, setData, processing, errors } = form;

    const difficultyTotal =
        data.difficultyDistribution.remember +
        data.difficultyDistribution.understand +
        data.difficultyDistribution.apply +
        data.difficultyDistribution.analyze +
        data.difficultyDistribution.evaluate +
        data.difficultyDistribution.create +
        data.difficultyDistribution.olympiad;

    // Subject select: the curated list always offers an "Other…" escape
    // hatch. When the stored subject isn't in the list we render it as a
    // selected option too so editing doesn't silently drop a custom value.
    const subjectInList = data.subject !== '' && subjectChoices.includes(data.subject);
    const usingOther = data.subject !== '' && !subjectInList;

    return (
        <>
            <header className="teacher-page-header">
                <div>
                    <h1>{isEdit ? 'Edit exam settings' : 'Create exam'}</h1>
                    <p>
                        {isEdit
                            ? examName
                                ? `Update settings for ${examName}.`
                                : 'Update the metadata and composition targets for this exam.'
                            : "Step 1 of 2 — exam metadata. You'll add questions next."}
                    </p>
                </div>
                <Link className="ghost-button" href={backHref}>
                    <ArrowLeft size={17} aria-hidden /> {isEdit ? 'Back to exam' : 'Back to exams'}
                </Link>
            </header>

            <section className="admin-panel">
                <form className="exam-form exam-form--two-col" onSubmit={onSubmit}>
                    {/* Left panel — exam metadata. */}
                    <div className="exam-form-meta">
                        {!isEdit ? (
                            <>
                                <label>
                                    Exam code
                                    <input
                                        value={data.examCode}
                                        onChange={(e) =>
                                            setData('examCode', e.target.value.toUpperCase().replace(/\s+/g, '-'))
                                        }
                                        placeholder="BIOLOGY-MIDTERM-2026"
                                        maxLength={40}
                                        required
                                        autoFocus
                                    />
                                    <small>Uppercase letters, digits, dashes. 3–40 characters.</small>
                                    {errors.examCode ? <small className="form-error">{errors.examCode}</small> : null}
                                </label>
                                <label>
                                    Display name
                                    <input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Biology Midterm 2026"
                                        maxLength={120}
                                        required
                                    />
                                    {errors.name ? <small className="form-error">{errors.name}</small> : null}
                                </label>
                            </>
                        ) : null}

                        {/* Subject — bilingual select (task addition). Not in the
                            original manual form, but required for this port. */}
                        <label>
                            Subject
                            <select
                                value={usingOther ? OTHER_SUBJECT_VALUE : data.subject}
                                onChange={(e) => {
                                    const v = e.target.value;
                                    setData('subject', v === OTHER_SUBJECT_VALUE ? ' ' : v);
                                }}
                            >
                                <option value="">— None —</option>
                                {subjectChoices.map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                                <option value={OTHER_SUBJECT_VALUE}>Other…</option>
                            </select>
                            {usingOther ? (
                                <input
                                    style={{ marginTop: 6 }}
                                    value={data.subject.trimStart()}
                                    onChange={(e) => setData('subject', e.target.value)}
                                    placeholder="ASTRONOMY / ASTRONOMI"
                                    maxLength={60}
                                />
                            ) : null}
                            {errors.subject ? <small className="form-error">{errors.subject}</small> : null}
                        </label>

                        {g.showSchedulingRow ? (
                            <div className="exam-form-row">
                                {g.showDuration ? (
                                    <label>
                                        Duration (minutes)
                                        <input
                                            type="number"
                                            min={1}
                                            max={480}
                                            value={data.durationMinutes}
                                            onChange={(e) => setData('durationMinutes', Number(e.target.value))}
                                            required
                                        />
                                        {errors.durationMinutes ? (
                                            <small className="form-error">{errors.durationMinutes}</small>
                                        ) : null}
                                    </label>
                                ) : null}
                                {g.showPassing ? (
                                    <label>
                                        Passing grade (%)
                                        <input
                                            type="number"
                                            min={0}
                                            max={100}
                                            value={data.passingGrade}
                                            onChange={(e) => setData('passingGrade', Number(e.target.value))}
                                            required
                                        />
                                        {errors.passingGrade ? (
                                            <small className="form-error">{errors.passingGrade}</small>
                                        ) : null}
                                    </label>
                                ) : null}
                            </div>
                        ) : null}

                        {/* Exam scheduling (task addition) — optional window. */}
                        <div className="exam-form-row">
                            <label>
                                Opens at (optional)
                                <input
                                    type="datetime-local"
                                    value={data.startTime}
                                    onChange={(e) => setData('startTime', e.target.value)}
                                />
                            </label>
                            <label>
                                Closes at (optional)
                                <input
                                    type="datetime-local"
                                    value={data.endTime}
                                    onChange={(e) => setData('endTime', e.target.value)}
                                />
                            </label>
                        </div>

                        <label>
                            Media base URL (optional)
                            <input
                                value={data.mediaBaseUrl}
                                onChange={(e) => setData('mediaBaseUrl', e.target.value)}
                                placeholder="https://cdn.example.com/exam-assets/"
                                maxLength={500}
                            />
                            <small>Prefixed to relative media paths in questions.</small>
                        </label>

                        {/* Security section (moved up from below — replaces the former
                            General Instructions slot). Keeping the SEB toggle in this
                            prominent position makes it harder for teachers to overlook
                            when they need it. The original Exam Mode + Security row
                            below stays for backwards-compat with the layout grid. */}
                        {g.showSeb ? (
                            <div className="exam-mode-group" style={{ marginTop: 4 }}>
                                <span className="exam-form-section-title">Security</span>
                                <div className="toggle-card-grid">
                                    <label className={`toggle-card${data.sebRequired ? ' on' : ''}`}>
                                        <input
                                            type="checkbox"
                                            checked={data.sebRequired}
                                            onChange={(e) => setData('sebRequired', e.target.checked)}
                                        />
                                        <ShieldAlert size={18} aria-hidden />
                                        <span>
                                            <strong>Require Safe Exam Browser</strong>
                                            <small>
                                                Students must launch the exam inside SEB. Configure the SEB key on the
                                                exam detail page.
                                            </small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        ) : null}
                    </div>

                    {/* Right panel — language + composition targets. */}
                    {g.showCompositionFieldset ? (
                        <fieldset className="exam-form-section">
                            <legend>Language &amp; composition targets</legend>
                            {g.showLanguage ? (
                                <div className="exam-form-row">
                                    <label>
                                        Language
                                        <input
                                            value={data.language}
                                            onChange={(e) => setData('language', e.target.value)}
                                            placeholder="English"
                                            maxLength={60}
                                            required
                                        />
                                        {errors.language ? (
                                            <small className="form-error">{errors.language}</small>
                                        ) : null}
                                    </label>
                                </div>
                            ) : null}

                            {g.showTypeRow ? (
                                <>
                                    <p className="exam-form-section-title">Total of each type of question</p>
                                    <div className="exam-form-row five">
                                        {g.showTypeSingle ? (
                                            <TypeField
                                                label="Single choice"
                                                value={data.typeDistribution.single_choice}
                                                onChange={(v) =>
                                                    setData('typeDistribution', {
                                                        ...data.typeDistribution,
                                                        single_choice: v,
                                                    })
                                                }
                                            />
                                        ) : null}
                                        {g.showTypeMulti ? (
                                            <TypeField
                                                label="Multi select"
                                                value={data.typeDistribution.multi_select}
                                                onChange={(v) =>
                                                    setData('typeDistribution', {
                                                        ...data.typeDistribution,
                                                        multi_select: v,
                                                    })
                                                }
                                            />
                                        ) : null}
                                        {g.showTypeShortText ? (
                                            <TypeField
                                                label="Short text"
                                                value={data.typeDistribution.short_text}
                                                onChange={(v) =>
                                                    setData('typeDistribution', {
                                                        ...data.typeDistribution,
                                                        short_text: v,
                                                    })
                                                }
                                            />
                                        ) : null}
                                        {g.showTypeNumeric ? (
                                            <TypeField
                                                label="Numeric"
                                                value={data.typeDistribution.numeric}
                                                onChange={(v) =>
                                                    setData('typeDistribution', {
                                                        ...data.typeDistribution,
                                                        numeric: v,
                                                    })
                                                }
                                            />
                                        ) : null}
                                        {g.showTypeEssay ? (
                                            <TypeField
                                                label="Structured / Essay"
                                                value={data.typeDistribution.essay}
                                                onChange={(v) =>
                                                    setData('typeDistribution', {
                                                        ...data.typeDistribution,
                                                        essay: v,
                                                    })
                                                }
                                            />
                                        ) : null}
                                    </div>
                                </>
                            ) : null}

                            {g.showDifficultyRow ? (
                                <>
                                    <p className="exam-form-section-title">
                                        Cognitive-level distribution (Bloom&apos;s, %) — must sum to 100 (current: {difficultyTotal})
                                    </p>
                                    <div className="exam-form-row five">
                                        {([
                                            ['Remember', 'remember', g.showDifficultyRemember],
                                            ['Understand', 'understand', g.showDifficultyUnderstand],
                                            ['Apply', 'apply', g.showDifficultyApply],
                                            ['Analyze', 'analyze', g.showDifficultyAnalyze],
                                            ['Evaluate', 'evaluate', g.showDifficultyEvaluate],
                                            ['Create', 'create', g.showDifficultyCreate],
                                            ['Olympiad', 'olympiad', g.showDifficultyOlympiad],
                                        ] as Array<[string, keyof DifficultyDistribution, boolean]>).map(([label, key, visible]) =>
                                            visible ? (
                                                <PercentField
                                                    key={key}
                                                    label={label}
                                                    value={data.difficultyDistribution[key]}
                                                    onChange={(v) =>
                                                        setData('difficultyDistribution', {
                                                            ...data.difficultyDistribution,
                                                            [key]: v,
                                                        })
                                                    }
                                                />
                                            ) : null,
                                        )}
                                    </div>
                                    {errors.difficultyDistribution ? (
                                        <small className="form-error">{errors.difficultyDistribution}</small>
                                    ) : null}
                                </>
                            ) : null}

                            {g.showMediaRow ? (
                                <>
                                    <p className="exam-form-section-title">Media targets</p>
                                    <div className="exam-form-row">
                                        {g.showMediaImage ? (
                                            <TypeField
                                                label="Images"
                                                value={data.mediaTargets.images}
                                                onChange={(v) =>
                                                    setData('mediaTargets', { ...data.mediaTargets, images: v })
                                                }
                                            />
                                        ) : null}
                                        {g.showMediaTable ? (
                                            <TypeField
                                                label="Tables"
                                                value={data.mediaTargets.tables}
                                                onChange={(v) =>
                                                    setData('mediaTargets', { ...data.mediaTargets, tables: v })
                                                }
                                            />
                                        ) : null}
                                    </div>
                                </>
                            ) : null}
                        </fieldset>
                    ) : null}

                    {/* Full-width strip — Exam mode + Shuffling + SEB. */}
                    {g.showModeRow || g.showSeb ? (
                        <div className="exam-mode-row">
                            {g.showMode ? (
                                <div className="exam-mode-group">
                                    <span className="exam-form-section-title">Exam mode</span>
                                    <div className="mode-card-grid">
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={data.examMode === 'strict'}
                                            className={`mode-card${data.examMode === 'strict' ? ' selected' : ''}`}
                                            onClick={() => setData('examMode', 'strict')}
                                        >
                                            <Timer size={20} aria-hidden />
                                            <strong>Strict (real exam)</strong>
                                            <span>
                                                Wall-clock time limit — the timer keeps running even if the student
                                                closes the tab. No review or explanations shown after submission.
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={data.examMode === 'try_out'}
                                            className={`mode-card${data.examMode === 'try_out' ? ' selected' : ''}`}
                                            onClick={() => setData('examMode', 'try_out')}
                                        >
                                            <Pause size={20} aria-hidden />
                                            <strong>Try Out (practice)</strong>
                                            <span>
                                                Timer pauses when the student closes the tab and resumes when they
                                                reopen. Review with full explanations shown after submission.
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            ) : null}

                            {g.showShuffleGroup ? (
                                <div className="exam-mode-group">
                                    <span className="exam-form-section-title">Shuffling</span>
                                    <div className="toggle-card-grid">
                                        {g.showShuffleQuestions ? (
                                            <label className={`toggle-card${data.shuffleQuestions ? ' on' : ''}`}>
                                                <input
                                                    type="checkbox"
                                                    checked={data.shuffleQuestions}
                                                    onChange={(e) => setData('shuffleQuestions', e.target.checked)}
                                                />
                                                <Shuffle size={18} aria-hidden />
                                                <span>
                                                    <strong>Shuffle questions</strong>
                                                    <small>Each student sees the questions in a different order.</small>
                                                </span>
                                            </label>
                                        ) : null}
                                        {g.showShuffleOptions ? (
                                            <label className={`toggle-card${data.shuffleOptions ? ' on' : ''}`}>
                                                <input
                                                    type="checkbox"
                                                    checked={data.shuffleOptions}
                                                    onChange={(e) => setData('shuffleOptions', e.target.checked)}
                                                />
                                                <ListOrdered size={18} aria-hidden />
                                                <span>
                                                    <strong>Shuffle options</strong>
                                                    <small>
                                                        For single / multi-select questions, the A/B/C/D option order
                                                        varies per student.
                                                    </small>
                                                </span>
                                            </label>
                                        ) : null}
                                    </div>
                                </div>
                            ) : null}

                            {/* Security panel moved up to the General Instructions slot
                                (which is now removed). Intentionally left empty here so
                                the surrounding `exam-mode-row` keeps its grid shape. */}
                        </div>
                    ) : null}

                    <button className="primary-button" type="submit" disabled={processing}>
                        <Save size={17} aria-hidden />
                        {processing
                            ? isEdit
                                ? 'Saving…'
                                : 'Creating…'
                            : isEdit
                              ? 'Save changes'
                              : 'Create exam'}
                    </button>
                </form>
            </section>
        </>
    );
}

function TypeField({ label, value, onChange }: { label: string; value: number; onChange: (v: number) => void }) {
    return (
        <label>
            {label}
            <input
                type="number"
                min={0}
                max={500}
                value={value}
                onChange={(e) => onChange(Math.max(0, Number(e.target.value) || 0))}
            />
        </label>
    );
}

function PercentField({ label, value, onChange }: { label: string; value: number; onChange: (v: number) => void }) {
    return (
        <label>
            {label}
            <input
                type="number"
                min={0}
                max={100}
                value={value}
                onChange={(e) => onChange(Math.max(0, Math.min(100, Number(e.target.value) || 0)))}
            />
        </label>
    );
}

export function isoToLocalInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return '';
    // datetime-local wants "YYYY-MM-DDTHH:mm" in local time.
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
}
