import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown, ChevronRight, ImageIcon, Library, Pencil, Plus, Save, Trash2, Upload, X,
} from 'lucide-react';
import { FormEvent, useMemo, useRef, useState } from 'react';
import TeacherShell from '@/components/TeacherShell';
import MarkdownContent from '@/components/MarkdownContent';
import MediaRenderer from '@/components/MediaRenderer';
import { useUIState } from '@/lib/useUIState';

// ---- Types mirroring the controller payload ----
type QuestionType = 'single_choice' | 'multi_select' | 'short_text' | 'numeric' | 'essay';
// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
type Difficulty = 'remember' | 'understand' | 'apply' | 'analyze' | 'evaluate' | 'create' | 'olympiad';
type QuestionOption = { id: string; text: string };
type MediaGroup = 'with' | 'without';

type BankQ = {
    id: string;
    type: QuestionType;
    language: string;
    subject: string;
    topic: string;
    subtopic: string | null;
    difficulty: Difficulty;
    tags: string[];
    prompt: string;
    options: QuestionOption[] | null;
    points: number;
    correctAnswer: unknown;
    explanationText: string;
    createdByName: string;
    uploadedBy: string | null;
    uploadedByName: string | null;
    createdAt: string | null;
    sourceFileName: string | null;
    mediaUrl: string | null;
    mediaType: 'image' | 'audio' | 'video' | null;
    canManage: boolean;
};

type FilterOptions = {
    languages: string[];
    subjects: string[];
    topics: string[];
    subtopics: string[];
    difficulties: Difficulty[];
    types: QuestionType[];
};

type Filters = {
    language?: string; subject?: string; topic?: string;
    subtopic?: string; difficulty?: string; type?: string; search?: string;
};

type PageProps = {
    questions: BankQ[];
    topicOrder: string[];
    filterOptions: FilterOptions;
    filters: Filters;
    isAdmin: boolean;
    lockedSubject: string | null;
    subjectChoices: string[];
    flash?: { success?: string | null; error?: string | null };
    importResult?: { added: number; fileName: string; warnings: string[] } | null;
};

const TYPE_LABELS: Record<QuestionType, string> = {
    single_choice: 'Single choice',
    multi_select: 'Multi select',
    short_text: 'Short text',
    numeric: 'Numeric',
    essay: 'Structured / Essay',
};

const DIFFICULTY_LABELS: Record<Difficulty, string> = {
    remember: 'Remember',
    understand: 'Understand',
    apply: 'Apply',
    analyze: 'Analyze',
    evaluate: 'Evaluate',
    create: 'Create',
    olympiad: 'Olympiad',
};

const DIFFICULTY_ORDER: Difficulty[] = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create', 'olympiad'];

function formatAnswer(answer: unknown): string {
    if (answer === null || answer === undefined) return '—';
    if (Array.isArray(answer)) return answer.join(', ');
    return String(answer);
}

export default function Bank() {
    const props = usePage().props as unknown as PageProps;
    const {
        questions, topicOrder, filterOptions, filters,
        isAdmin, lockedSubject, subjectChoices, flash, importResult,
    } = props;

    const myUid = ((usePage().props as any).auth?.user?.uid as string) ?? null;

    const total = questions.length;
    const activeFilters = Object.keys(filters).length;

    // --- Tree expansion state (5 levels + per-question) ---
    // Persisted as string arrays so JSON-based storage round-trips correctly
    // (Set<string> would serialize to `{}` and lose every entry).
    const [openSubjects, setOpenSubjects] = useUIState<string[]>('teacher.bank.openSubjects', []);
    const [openTopics, setOpenTopics] = useUIState<string[]>('teacher.bank.openTopics', []);
    const [openSubtopics, setOpenSubtopics] = useUIState<string[]>('teacher.bank.openSubtopics', []);
    const [openDifficulties, setOpenDifficulties] = useUIState<string[]>('teacher.bank.openDifficulties', []);
    const [openMediaGroups, setOpenMediaGroups] = useUIState<string[]>('teacher.bank.openMediaGroups', []);
    const [openQuestions, setOpenQuestions] = useUIState<string[]>('teacher.bank.openQuestions', []);

    function toggleIn(setter: React.Dispatch<React.SetStateAction<string[]>>, key: string) {
        setter((prev) => {
            if (prev.includes(key)) return prev.filter((k) => k !== key);
            return [...prev, key];
        });
    }
    function purgePrefix(setter: React.Dispatch<React.SetStateAction<string[]>>, prefix: string) {
        setter((prev) => {
            const next = prev.filter((k) => !k.startsWith(prefix));
            return next.length === prev.length ? prev : next;
        });
    }

    // --- Edit / create form state ---
    const [editingId, setEditingId] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);

    // --- Filters: server round-trip on change (preserve scroll + state) ---
    function setFilter(key: keyof Filters, value: string) {
        const next: Record<string, string> = { ...(filters as Record<string, string>) };
        if (!value) delete next[key];
        else next[key] = value;
        router.get('/teacher/bank', next, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    }
    function clearFilters() {
        router.get('/teacher/bank', {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    // --- Build the subject→topic→subtopic→difficulty→media tree ---
    type DiffBucket = Record<MediaGroup, BankQ[]>;
    type TopicMap = Map<string, Map<string, Map<Difficulty, DiffBucket>>>;
    type SubjectTree = Map<string, TopicMap>;

    const tree: SubjectTree = useMemo(() => {
        const norm = (s: string) => s.trim().toLowerCase();
        type Bucket = Map<Difficulty, DiffBucket>;
        const subjectBuckets = new Map<string, {
            label: string;
            topics: Map<string, { label: string; subtopics: Map<string, { label: string; bucket: Bucket }> }>;
        }>();

        for (const q of questions) {
            const subjectRaw = q.subject || '(no subject)';
            const topicRaw = q.topic || '(no topic)';
            const subtopicRaw = q.subtopic || '(no subtopic)';
            const sKey = norm(subjectRaw);
            const tKey = norm(topicRaw);
            const stKey = norm(subtopicRaw);
            const diff = q.difficulty;
            const mediaKey: MediaGroup = q.mediaUrl ? 'with' : 'without';

            let sEntry = subjectBuckets.get(sKey);
            if (!sEntry) { sEntry = { label: subjectRaw, topics: new Map() }; subjectBuckets.set(sKey, sEntry); }
            let tEntry = sEntry.topics.get(tKey);
            if (!tEntry) { tEntry = { label: topicRaw, subtopics: new Map() }; sEntry.topics.set(tKey, tEntry); }
            let stEntry = tEntry.subtopics.get(stKey);
            if (!stEntry) { stEntry = { label: subtopicRaw, bucket: new Map() }; tEntry.subtopics.set(stKey, stEntry); }
            if (!stEntry.bucket.has(diff)) stEntry.bucket.set(diff, { with: [], without: [] });
            stEntry.bucket.get(diff)![mediaKey].push(q);
        }

        const orderIndex = new Map<string, number>();
        topicOrder.forEach((t, idx) => orderIndex.set(norm(t), idx));

        const root: SubjectTree = new Map();
        for (const [, sEntry] of subjectBuckets) {
            const topicMap: TopicMap = new Map();
            const sortedTopics = Array.from(sEntry.topics.entries()).sort(([ka, a], [kb, b]) => {
                const ia = orderIndex.get(ka);
                const ib = orderIndex.get(kb);
                if (ia !== undefined && ib !== undefined) return ia - ib;
                if (ia !== undefined) return -1;
                if (ib !== undefined) return 1;
                return a.label.localeCompare(b.label);
            });
            for (const [, tEntry] of sortedTopics) {
                const subMap = new Map<string, Map<Difficulty, DiffBucket>>();
                const sortedSubs = Array.from(tEntry.subtopics.values()).sort((a, b) => {
                    const an = a.label === '(no subtopic)';
                    const bn = b.label === '(no subtopic)';
                    if (an !== bn) return an ? 1 : -1;
                    return a.label.localeCompare(b.label);
                });
                for (const sub of sortedSubs) subMap.set(sub.label, sub.bucket);
                topicMap.set(tEntry.label, subMap);
            }
            root.set(sEntry.label, topicMap);
        }
        return root;
    }, [questions, topicOrder]);

    // When any filter is active, auto-expand everything so results are visible.
    const autoExpand = activeFilters > 0;

    const isSubjectOpen = (s: string) => autoExpand || openSubjects.includes(s);
    const isTopicOpen = (s: string, t: string) => autoExpand || openTopics.includes(`${s}::${t}`);
    const isSubtopicOpen = (s: string, t: string, st: string) => autoExpand || openSubtopics.includes(`${s}::${t}::${st}`);
    const isDiffOpen = (s: string, t: string, st: string, d: Difficulty) =>
        autoExpand || openDifficulties.includes(`${s}::${t}::${st}::${d}`);
    const isMediaOpen = (s: string, t: string, st: string, d: Difficulty, m: MediaGroup) =>
        autoExpand || openMediaGroups.includes(`${s}::${t}::${st}::${d}::${m}`);

    function toggleSubject(s: string) {
        if (openSubjects.includes(s)) {
            toggleIn(setOpenSubjects, s);
            const p = `${s}::`;
            purgePrefix(setOpenTopics, p); purgePrefix(setOpenSubtopics, p);
            purgePrefix(setOpenDifficulties, p); purgePrefix(setOpenMediaGroups, p);
        } else toggleIn(setOpenSubjects, s);
    }
    function toggleTopic(s: string, t: string) {
        const key = `${s}::${t}`;
        if (openTopics.includes(key)) {
            toggleIn(setOpenTopics, key);
            const p = `${key}::`;
            purgePrefix(setOpenSubtopics, p); purgePrefix(setOpenDifficulties, p); purgePrefix(setOpenMediaGroups, p);
        } else toggleIn(setOpenTopics, key);
    }
    function toggleSubtopic(s: string, t: string, st: string) {
        const key = `${s}::${t}::${st}`;
        if (openSubtopics.includes(key)) {
            toggleIn(setOpenSubtopics, key);
            const p = `${key}::`;
            purgePrefix(setOpenDifficulties, p); purgePrefix(setOpenMediaGroups, p);
        } else toggleIn(setOpenSubtopics, key);
    }
    function toggleDiff(s: string, t: string, st: string, d: Difficulty) {
        const key = `${s}::${t}::${st}::${d}`;
        if (openDifficulties.includes(key)) {
            toggleIn(setOpenDifficulties, key);
            purgePrefix(setOpenMediaGroups, `${key}::`);
        } else toggleIn(setOpenDifficulties, key);
    }
    function toggleMedia(s: string, t: string, st: string, d: Difficulty, m: MediaGroup) {
        toggleIn(setOpenMediaGroups, `${s}::${t}::${st}::${d}::${m}`);
    }
    function toggleQuestion(id: string) { toggleIn(setOpenQuestions, id); }

    function onDelete(id: string) {
        if (!window.confirm("Delete this bank question? It won't affect exams that already include it.")) return;
        router.delete(`/teacher/bank/${id}`, { preserveScroll: true });
    }

    function countBucket(b: DiffBucket) { return b.with.length + b.without.length; }
    function countDiffMap(dm: Map<Difficulty, DiffBucket>) {
        let n = 0; dm.forEach((b) => { n += countBucket(b); }); return n;
    }
    function countSubMap(sm: Map<string, Map<Difficulty, DiffBucket>>) {
        let n = 0; sm.forEach((dm) => { n += countDiffMap(dm); }); return n;
    }
    function countTopicMap(tm: TopicMap) {
        let n = 0; tm.forEach((sm) => { n += countSubMap(sm); }); return n;
    }

    // --- per-question render (teaser / expanded / edit form) ---
    function renderQuestion(q: BankQ) {
        if (editingId === q.id) {
            return (
                <li key={q.id} className="question-list-item">
                    <QuestionForm
                        mode="edit"
                        question={q}
                        subjectChoices={subjectChoices}
                        onClose={() => setEditingId(null)}
                    />
                </li>
            );
        }
        if (!openQuestions.includes(q.id)) {
            const teaser = q.prompt
                .replace(/\*+|`+|_{2,}|#+\s|>\s/g, '')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 90);
            return (
                <li key={q.id} className="bank-tree-question">
                    <button
                        type="button"
                        className="bank-tree-row bank-tree-row--question"
                        aria-expanded={false}
                        onClick={() => toggleQuestion(q.id)}
                        title="Click to expand the full question"
                    >
                        <ChevronRight size={12} aria-hidden />
                        <span className={`difficulty-pill ${q.difficulty}`}>{DIFFICULTY_LABELS[q.difficulty]}</span>
                        <span className="bank-tree-teaser">{teaser}…</span>
                        <span className="bank-tree-count">{q.points}pt</span>
                    </button>
                </li>
            );
        }
        const canManage = q.canManage || (myUid != null && q.uploadedBy === myUid);
        return (
            <li key={q.id} className="question-list-item">
                <button
                    type="button"
                    className="bank-tree-row bank-tree-row--question bank-tree-row--open"
                    aria-expanded
                    onClick={() => toggleQuestion(q.id)}
                    title="Click to collapse"
                    style={{ marginBottom: 8 }}
                >
                    <ChevronDown size={12} aria-hidden />
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>Click to collapse</span>
                </button>
                <div className="question-list-meta">
                    <span className="question-type-pill">{TYPE_LABELS[q.type]}</span>
                    <span className="language-pill">{q.language}</span>
                    <span style={{ color: 'var(--muted)' }}>{q.subject}</span>
                    <span style={{ color: 'var(--muted)' }}>· {q.topic}</span>
                    {q.subtopic ? <span style={{ color: 'var(--muted)' }}>· {q.subtopic}</span> : null}
                    <span className={`difficulty-pill ${q.difficulty}`}>{DIFFICULTY_LABELS[q.difficulty]}</span>
                    <span style={{ color: 'var(--muted)' }}>{q.points} pt{q.points === 1 ? '' : 's'}</span>
                </div>
                <MarkdownContent text={q.prompt} className="question-list-prompt" />
                {q.mediaUrl && q.mediaType ? (
                    <MediaRenderer media={{ id: q.id, type: q.mediaType, url: q.mediaUrl }} />
                ) : null}
                {q.options ? (
                    <ul className="question-list-options">
                        {q.options.map((option) => {
                            const isCorrect = Array.isArray(q.correctAnswer)
                                ? (q.correctAnswer as string[]).includes(option.id)
                                : q.correctAnswer === option.id;
                            return (
                                <li key={option.id} className={isCorrect ? 'is-correct' : ''}>
                                    <span className="option-id-chip">{option.id}</span>
                                    {option.text}
                                    {isCorrect ? <span className="correct-flag">correct</span> : null}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="question-list-answer">
                        Correct answer: <strong>{formatAnswer(q.correctAnswer)}</strong>
                    </p>
                )}
                {q.explanationText ? (
                    <MarkdownContent text={q.explanationText} className="question-list-explanation" />
                ) : null}
                {canManage ? (
                    <div style={{ display: 'flex', gap: 6 }}>
                        <button className="ghost-button" type="button" onClick={() => { setCreating(false); setEditingId(q.id); }}>
                            <Pencil size={14} aria-hidden /> Edit
                        </button>
                        <button className="ghost-button danger" type="button" onClick={() => onDelete(q.id)}>
                            <Trash2 size={14} aria-hidden /> Delete
                        </button>
                    </div>
                ) : (
                    <p style={{ margin: 0, fontSize: '0.78rem', color: 'var(--muted)' }}>
                        {q.uploadedByName
                            ? `Uploaded by ${q.uploadedByName} — admin database, read-only for you.`
                            : 'Admin database — read-only for you.'}
                    </p>
                )}
            </li>
        );
    }

    function renderTopics(subject: string, topicMap: TopicMap) {
        return Array.from(topicMap.entries()).map(([topic, subtopicMap], topicIdx) => {
            const topicCount = countSubMap(subtopicMap);
            const topicOpen = isTopicOpen(subject, topic);
            return (
                <div key={topic} className="bank-tree-topic">
                    <button
                        type="button"
                        className="bank-tree-row bank-tree-row--topic"
                        aria-expanded={topicOpen}
                        onClick={() => toggleTopic(subject, topic)}
                    >
                        {topicOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
                        <span className="bank-tree-index">{topicIdx + 1}.</span>
                        <span className="bank-tree-label">{topic}</span>
                        <span className="bank-tree-count">{topicCount}</span>
                    </button>
                    {topicOpen
                        ? Array.from(subtopicMap.entries()).map(([sub, diffMap]) => {
                            const subCount = countDiffMap(diffMap);
                            const subOpen = isSubtopicOpen(subject, topic, sub);
                            return (
                                <div key={sub} className="bank-tree-subtopic">
                                    <button
                                        type="button"
                                        className="bank-tree-row bank-tree-row--subtopic"
                                        aria-expanded={subOpen}
                                        onClick={() => toggleSubtopic(subject, topic, sub)}
                                    >
                                        {subOpen ? <ChevronDown size={13} aria-hidden /> : <ChevronRight size={13} aria-hidden />}
                                        <span className="bank-tree-label">{sub}</span>
                                        <span className="bank-tree-count">{subCount}</span>
                                    </button>
                                    {subOpen
                                        ? DIFFICULTY_ORDER.filter((d) => diffMap.has(d)).map((diff) => {
                                            const bucket = diffMap.get(diff)!;
                                            const diffCount = countBucket(bucket);
                                            const diffOpen = isDiffOpen(subject, topic, sub, diff);
                                            return (
                                                <div key={diff} className="bank-tree-difficulty">
                                                    <button
                                                        type="button"
                                                        className={`bank-tree-row bank-tree-row--difficulty difficulty-${diff}`}
                                                        aria-expanded={diffOpen}
                                                        onClick={() => toggleDiff(subject, topic, sub, diff)}
                                                    >
                                                        {diffOpen ? <ChevronDown size={12} aria-hidden /> : <ChevronRight size={12} aria-hidden />}
                                                        <span className="bank-tree-label">{DIFFICULTY_LABELS[diff]}</span>
                                                        <span className="bank-tree-count">{diffCount}</span>
                                                    </button>
                                                    {diffOpen
                                                        ? ([
                                                            { key: 'with' as MediaGroup, list: bucket.with, label: 'With image' },
                                                            { key: 'without' as MediaGroup, list: bucket.without, label: 'Without image' },
                                                        ])
                                                            .filter((g) => g.list.length > 0)
                                                            .map((g) => {
                                                                const mediaOpen = isMediaOpen(subject, topic, sub, diff, g.key);
                                                                return (
                                                                    <div key={g.key} className="bank-tree-media">
                                                                        <button
                                                                            type="button"
                                                                            className="bank-tree-row bank-tree-row--media"
                                                                            aria-expanded={mediaOpen}
                                                                            onClick={() => toggleMedia(subject, topic, sub, diff, g.key)}
                                                                        >
                                                                            {mediaOpen ? <ChevronDown size={12} aria-hidden /> : <ChevronRight size={12} aria-hidden />}
                                                                            {g.key === 'with' ? <ImageIcon size={12} aria-hidden /> : null}
                                                                            <span className="bank-tree-label">{g.label}</span>
                                                                            <span className="bank-tree-count">{g.list.length}</span>
                                                                        </button>
                                                                        {mediaOpen ? (
                                                                            <ul className="bank-tree-questions">
                                                                                {g.list.map((q) => renderQuestion(q))}
                                                                            </ul>
                                                                        ) : null}
                                                                    </div>
                                                                );
                                                            })
                                                        : null}
                                                </div>
                                            );
                                        })
                                        : null}
                                </div>
                            );
                        })
                        : null}
                </div>
            );
        });
    }

    return (
        <TeacherShell>
            <Head title="Teacher · Question Bank" />
            <header className="teacher-page-header">
                <div>
                    <h1>Question Bank</h1>
                    <p>
                        {total} question{total === 1 ? '' : 's'} shown. Filter by subject, topic, subtopic, difficulty, or type.
                        {isAdmin
                            ? ' Shared bank — admin database.'
                            : ' Read-only shared bank. Questions you upload show here too, and you can edit / delete those.'}
                    </p>
                </div>
                <div style={{ display: 'flex', gap: '8px' }}>
                    <button
                        className="ghost-button"
                        type="button"
                        onClick={() => { setEditingId(null); setCreating((c) => !c); }}
                    >
                        <Plus size={17} aria-hidden /> New question
                    </button>
                    <UploadButton />
                </div>
            </header>

            {flash?.error ? <p className="form-error">{flash.error}</p> : null}
            {flash?.success ? <p className="form-success">{flash.success}</p> : null}

            {importResult ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Import result: {importResult.fileName}</h2>
                            <p>{importResult.added} question(s) added.</p>
                        </div>
                    </div>
                    {importResult.warnings.length > 0 ? (
                        <ul className="import-preview-list">
                            {importResult.warnings.slice(0, 10).map((w, i) => (
                                <li key={i}><span style={{ color: 'var(--muted)' }}>{w}</span></li>
                            ))}
                            {importResult.warnings.length > 10 ? (
                                <li><span style={{ color: 'var(--muted)' }}>+{importResult.warnings.length - 10} more…</span></li>
                            ) : null}
                        </ul>
                    ) : (
                        <p style={{ color: 'var(--muted)' }}>No warnings.</p>
                    )}
                </section>
            ) : (
                <details className="admin-panel package-format-help">
                    <summary style={{ cursor: 'pointer', fontSize: '0.9rem', color: 'var(--muted)' }}>
                        Upload format reference (click to expand)
                    </summary>
                    <p style={{ margin: '10px 0 0', color: 'var(--muted)', fontSize: '0.88rem' }}>
                        <strong>Formats accepted:</strong> a <code>.zip</code> with <code>questions.json</code> at the root
                        (plus an optional media folder named <code>media/</code>, <code>images/</code>, <code>img/</code>,
                        <code>assets/</code> — or anything; files match by name), a <code>.zip</code> with a
                        Questions <code>.xlsx</code> + media, or a standalone <code>.xlsx</code>. Each question has{' '}
                        <code>type</code>, <code>language</code>, <code>subject</code>, <code>topic</code>, optional{' '}
                        <code>subtopic</code>, <code>difficulty</code> (Bloom&apos;s: remember/understand/apply/analyze/evaluate/create/olympiad — legacy easy/medium/hard/hots also accepted on import), <code>prompt</code>,{' '}
                        <code>options</code> (choice types), <code>correctAnswer</code>, <code>explanation</code>. Set{' '}
                        <code>mediaFile</code> to a filename (e.g. <code>q1.png</code>) present anywhere in the zip.
                    </p>
                </details>
            )}

            {creating ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>New bank question</h2>
                            <p>Added to the shared admin database; you keep edit / delete rights on it.</p>
                        </div>
                    </div>
                    <QuestionForm
                        mode="create"
                        subjectChoices={subjectChoices}
                        lockedSubject={lockedSubject}
                        onClose={() => setCreating(false)}
                    />
                </section>
            ) : null}

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Bank questions</h2>
                        <p>{activeFilters > 0 ? `${activeFilters} filter(s) active.` : 'No filters.'}</p>
                    </div>
                    {activeFilters > 0 ? (
                        <button className="ghost-button" type="button" onClick={clearFilters}>Clear filters</button>
                    ) : null}
                </div>

                <div className="bank-filter-row">
                    <select value={filters.language ?? ''} onChange={(e) => setFilter('language', e.target.value)}>
                        <option value="">All languages</option>
                        {filterOptions.languages.map((l) => <option key={l} value={l}>{l}</option>)}
                    </select>
                    <select value={filters.subject ?? ''} onChange={(e) => setFilter('subject', e.target.value)}>
                        <option value="">All subjects</option>
                        {filterOptions.subjects.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select value={filters.topic ?? ''} onChange={(e) => setFilter('topic', e.target.value)}>
                        <option value="">All topics</option>
                        {filterOptions.topics.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select value={filters.subtopic ?? ''} onChange={(e) => setFilter('subtopic', e.target.value)}>
                        <option value="">All subtopics</option>
                        {filterOptions.subtopics.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select value={filters.difficulty ?? ''} onChange={(e) => setFilter('difficulty', e.target.value)}>
                        <option value="">All difficulties</option>
                        {filterOptions.difficulties.map((d) => <option key={d} value={d}>{DIFFICULTY_LABELS[d]}</option>)}
                    </select>
                    <select value={filters.type ?? ''} onChange={(e) => setFilter('type', e.target.value)}>
                        <option value="">All types</option>
                        {filterOptions.types.map((t) => <option key={t} value={t}>{TYPE_LABELS[t]}</option>)}
                    </select>
                    <SearchBox value={filters.search ?? ''} onSubmit={(v) => setFilter('search', v)} />
                </div>

                {questions.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>
                        <Library size={16} aria-hidden style={{ verticalAlign: '-3px', marginRight: 6 }} />
                        No questions match these filters. Upload a zip/xlsx, add one, or clear filters.
                    </p>
                ) : (
                    <ol className="bank-tree">
                        {Array.from(tree.entries()).map(([subject, topicMap], subjectIdx) => {
                            const subjectCount = countTopicMap(topicMap);
                            const subjectOpen = isAdmin ? isSubjectOpen(subject) : true;
                            if (!isAdmin) {
                                return (
                                    <li key={subject} className="bank-tree-subject">
                                        {renderTopics(subject, topicMap)}
                                    </li>
                                );
                            }
                            return (
                                <li key={subject} className="bank-tree-subject">
                                    <button
                                        type="button"
                                        className="bank-tree-row bank-tree-row--subject"
                                        aria-expanded={subjectOpen}
                                        onClick={() => toggleSubject(subject)}
                                    >
                                        {subjectOpen ? <ChevronDown size={15} aria-hidden /> : <ChevronRight size={15} aria-hidden />}
                                        <span className="bank-tree-index">{subjectIdx + 1}.</span>
                                        <span className="bank-tree-label">{subject}</span>
                                        <span className="bank-tree-count">{subjectCount}</span>
                                    </button>
                                    {subjectOpen ? renderTopics(subject, topicMap) : null}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </section>
        </TeacherShell>
    );
}

// ------------------------------------------------------------------
// Search box (debounce-free; commits on Enter or blur to avoid a
// server round-trip per keystroke)
// ------------------------------------------------------------------
function SearchBox({ value, onSubmit }: { value: string; onSubmit: (v: string) => void }) {
    const [local, setLocal] = useState(value);
    return (
        <input
            type="search"
            placeholder="Search prompt / topic…"
            value={local}
            onChange={(e) => setLocal(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') onSubmit(local.trim()); }}
            onBlur={() => { if (local.trim() !== value) onSubmit(local.trim()); }}
        />
    );
}

// ------------------------------------------------------------------
// Upload button + hidden file input. Posts multipart to the server
// (zip parsed + inserted server-side), then Inertia reloads the page
// with the importResult flash.
// ------------------------------------------------------------------
function UploadButton() {
    const inputRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    function onFile(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;
        setBusy(true);
        router.post('/teacher/bank/upload', { file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                if (inputRef.current) inputRef.current.value = '';
            },
        });
    }

    return (
        <>
            <input ref={inputRef} type="file" accept=".zip,.xlsx,.xls" hidden onChange={onFile} />
            <button className="primary-button" type="button" onClick={() => inputRef.current?.click()} disabled={busy}>
                <Upload size={17} aria-hidden /> {busy ? 'Uploading…' : 'Upload questions'}
            </button>
        </>
    );
}

// ------------------------------------------------------------------
// Create / edit form. Handles all 5 question types: options editor +
// per-type correct-answer picker. On edit, type + media are immutable.
// ------------------------------------------------------------------
type FormState = {
    type: QuestionType;
    prompt: string;
    explanationText: string;
    points: number;
    topic: string;
    subtopic: string;
    difficulty: Difficulty;
    language: string;
    subject: string;
    options: QuestionOption[];
    correctSingle: string;
    correctMulti: string[];
    correctText: string;
    correctNumeric: number;
};

function blankOptions(): QuestionOption[] {
    return [
        { id: 'A', text: '' },
        { id: 'B', text: '' },
        { id: 'C', text: '' },
        { id: 'D', text: '' },
    ];
}

function QuestionForm({
    mode, question, subjectChoices, lockedSubject, onClose,
}: {
    mode: 'create' | 'edit';
    question?: BankQ;
    subjectChoices: string[];
    lockedSubject?: string | null;
    onClose: () => void;
}) {
    const initial: FormState = useMemo(() => {
        if (mode === 'edit' && question) {
            const ca = question.correctAnswer;
            return {
                type: question.type,
                prompt: question.prompt,
                explanationText: question.explanationText,
                points: question.points,
                topic: question.topic,
                subtopic: question.subtopic ?? '',
                difficulty: question.difficulty,
                language: question.language,
                subject: question.subject,
                options: question.options ? question.options.map((o) => ({ ...o })) : blankOptions(),
                correctSingle: typeof ca === 'string' ? ca : '',
                correctMulti: Array.isArray(ca) ? (ca as string[]) : [],
                correctText: typeof ca === 'string' ? ca : '',
                correctNumeric: typeof ca === 'number' ? ca : Number(ca) || 0,
            };
        }
        return {
            type: 'single_choice',
            prompt: '',
            explanationText: '',
            points: 1,
            topic: '',
            subtopic: '',
            difficulty: 'understand',
            language: 'English',
            subject: lockedSubject ?? '',
            options: blankOptions(),
            correctSingle: 'A',
            correctMulti: [],
            correctText: '',
            correctNumeric: 0,
        };
    }, [mode, question, lockedSubject]);

    const { data, setData, processing, errors, transform, post, put, reset } = useForm<FormState>(initial);

    const isChoice = data.type === 'single_choice' || data.type === 'multi_select';
    const isMulti = data.type === 'multi_select';

    function buildCorrect(): unknown {
        if (data.type === 'single_choice') return data.correctSingle;
        if (data.type === 'multi_select') return data.correctMulti;
        if (data.type === 'short_text') return data.correctText;
        if (data.type === 'numeric') return data.correctNumeric;
        return '';
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        transform((d) => {
            const payload: Record<string, unknown> = {
                prompt: d.prompt,
                explanationText: d.explanationText,
                points: d.points,
                topic: d.topic,
                subtopic: d.subtopic,
                difficulty: d.difficulty,
                language: d.language,
                subject: d.subject,
                correctAnswer: buildCorrect(),
                options: isChoice ? d.options.filter((o) => o.text.trim() !== '') : null,
            };
            if (mode === 'create') payload.type = d.type;
            return payload as unknown as FormState;
        });
        if (mode === 'create') {
            post('/teacher/bank', {
                preserveScroll: true,
                onSuccess: () => { reset(); onClose(); },
            });
        } else if (question) {
            put(`/teacher/bank/${question.id}`, {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
        }
    }

    function updateOptionText(id: string, text: string) {
        setData('options', data.options.map((o) => (o.id === id ? { ...o, text } : o)));
    }
    function addOption() {
        const nextId = String.fromCharCode('A'.charCodeAt(0) + data.options.length);
        setData('options', [...data.options, { id: nextId, text: '' }]);
    }
    function removeOption(id: string) {
        // Re-letter remaining options so ids stay A,B,C…
        const remaining = data.options.filter((o) => o.id !== id);
        const relettered = remaining.map((o, i) => ({ id: String.fromCharCode('A'.charCodeAt(0) + i), text: o.text }));
        setData('options', relettered);
        // Drop stale correct-answer references.
        setData('correctMulti', data.correctMulti.filter((c) => relettered.some((o) => o.id === c)));
        if (!relettered.some((o) => o.id === data.correctSingle)) {
            setData('correctSingle', relettered[0]?.id ?? '');
        }
    }

    return (
        <form className="question-form" onSubmit={submit}>
            <p style={{ margin: 0, fontSize: '0.78rem', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.04em', fontWeight: 600 }}>
                {mode === 'edit'
                    ? 'Editing bank question — saved changes write back to the admin database.'
                    : 'New bank question — written to the admin database.'}
            </p>

            {mode === 'create' ? (
                <label>
                    Type
                    <select value={data.type} onChange={(e) => setData('type', e.target.value as QuestionType)}>
                        {(Object.keys(TYPE_LABELS) as QuestionType[]).map((t) => (
                            <option key={t} value={t}>{TYPE_LABELS[t]}</option>
                        ))}
                    </select>
                </label>
            ) : (
                <p style={{ margin: 0, fontSize: '0.82rem', color: 'var(--muted)' }}>
                    Type: <strong>{TYPE_LABELS[data.type]}</strong> (immutable)
                </p>
            )}

            <label>
                Prompt
                <textarea rows={4} value={data.prompt} onChange={(e) => setData('prompt', e.target.value)} />
            </label>
            {errors.prompt ? <p className="form-error">{errors.prompt}</p> : null}

            {isChoice ? (
                <div className="options-block">
                    <div className="options-block-header">
                        <strong>Options</strong>
                        <button className="ghost-button" type="button" onClick={addOption} style={{ padding: '4px 10px' }}>
                            <Plus size={13} aria-hidden /> Add option
                        </button>
                    </div>
                    {data.options.map((opt) => (
                        <div key={opt.id} className="option-row-edit">
                            <span className="option-id-chip">{opt.id}</span>
                            <input value={opt.text} onChange={(e) => updateOptionText(opt.id, e.target.value)} placeholder={`Option ${opt.id}`} />
                            <label className="option-correct-toggle">
                                {isMulti ? (
                                    <input
                                        type="checkbox"
                                        checked={data.correctMulti.includes(opt.id)}
                                        onChange={(e) => {
                                            const next = e.target.checked
                                                ? [...data.correctMulti, opt.id].sort()
                                                : data.correctMulti.filter((x) => x !== opt.id);
                                            setData('correctMulti', next);
                                        }}
                                    />
                                ) : (
                                    <input
                                        type="radio"
                                        name="correct-single"
                                        checked={data.correctSingle === opt.id}
                                        onChange={() => setData('correctSingle', opt.id)}
                                    />
                                )}
                                correct
                            </label>
                            <button className="ghost-button danger" type="button" onClick={() => removeOption(opt.id)} style={{ padding: '4px 8px' }}>
                                <X size={13} aria-hidden />
                            </button>
                        </div>
                    ))}
                    {errors.options ? <p className="form-error">{errors.options}</p> : null}
                </div>
            ) : data.type === 'short_text' ? (
                <label>
                    Correct answer (exact text)
                    <input value={data.correctText} onChange={(e) => setData('correctText', e.target.value)} />
                </label>
            ) : data.type === 'numeric' ? (
                <label>
                    Correct answer (number)
                    <input type="number" value={data.correctNumeric} onChange={(e) => setData('correctNumeric', Number(e.target.value))} />
                </label>
            ) : (
                <p style={{ margin: 0, color: 'var(--muted)', fontSize: '0.85rem' }}>
                    Essay questions are graded manually — no correct-answer key.
                </p>
            )}

            <label>
                Explanation
                <textarea rows={3} value={data.explanationText} onChange={(e) => setData('explanationText', e.target.value)} />
            </label>

            <div className="question-form-row" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 10 }}>
                <label>
                    Points
                    <input type="number" min={1} max={100} value={data.points} onChange={(e) => setData('points', Number(e.target.value) || 1)} />
                </label>
                <label>
                    Topic
                    <input value={data.topic} onChange={(e) => setData('topic', e.target.value)} />
                </label>
                <label>
                    Subtopic
                    <input value={data.subtopic} onChange={(e) => setData('subtopic', e.target.value)} />
                </label>
                <label>
                    Difficulty
                    <select value={data.difficulty} onChange={(e) => setData('difficulty', e.target.value as Difficulty)}>
                        {DIFFICULTY_ORDER.map((d) => <option key={d} value={d}>{DIFFICULTY_LABELS[d]}</option>)}
                    </select>
                </label>
                <label>
                    Language
                    <input value={data.language} onChange={(e) => setData('language', e.target.value)} />
                </label>
                <label>
                    Subject
                    <input list="bank-subject-choices" value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                    <datalist id="bank-subject-choices">
                        {subjectChoices.map((s) => <option key={s} value={s} />)}
                    </datalist>
                </label>
            </div>
            {errors.topic ? <p className="form-error">{errors.topic}</p> : null}

            <div className="question-form-actions">
                <button className="primary-button" type="submit" disabled={processing}>
                    <Save size={14} aria-hidden /> {processing ? 'Saving…' : mode === 'create' ? 'Create question' : 'Save'}
                </button>
                <button className="ghost-button" type="button" onClick={onClose} disabled={processing}>
                    <X size={14} aria-hidden /> Cancel
                </button>
            </div>
        </form>
    );
}
