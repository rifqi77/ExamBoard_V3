import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    CircleX,
    Clock,
    FileText,
    KeyRound,
    Library,
    Plus,
    Save,
    Settings,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import MarkdownContent from '@/components/MarkdownContent';
import MediaRenderer from '@/components/MediaRenderer';
import TeacherShell from '@/components/TeacherShell';
import { useUIState } from '@/lib/useUIState';

// ============================================================================
// Types (mirror of the original TeacherExamDetail / TeacherQuestionSummary /
// TeacherTokenSummary / TeacherBankQuestionSummary shapes the controller emits)
// ============================================================================

type QuestionType = 'single_choice' | 'multi_select' | 'short_text' | 'numeric' | 'essay';
// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
type Difficulty = 'remember' | 'understand' | 'apply' | 'analyze' | 'evaluate' | 'create' | 'olympiad';
type AnswerValue = string | number | string[] | null;
type QuestionOption = { id: string; text: string };

type QMedia = {
    id: string;
    type: 'image' | 'video' | 'audio';
    url: string;
    altText: string | null;
    caption: string | null;
};

type TeacherQuestionSummary = {
    id: string;
    position: number;
    type: QuestionType;
    topic: string;
    subtopic: string | null;
    difficulty: Difficulty | null;
    prompt: string;
    points: number;
    options: QuestionOption[] | null;
    correctAnswer: AnswerValue;
    explanationText: string;
    sourceBankQuestionId: string | null;
    media: QMedia[];
};

type TeacherTokenSummary = {
    id: string;
    code: string;
    examDatabaseId: string;
    examId: string;
    examName: string;
    createdByName: string;
    createdAt: string;
    expiresAt: string | null;
    maxUses: number;
    usedCount: number;
    active: boolean;
};

type ExamTypeDistribution = Record<QuestionType, number>;
type ExamDifficultyDistribution = Record<Difficulty, number>;
type ExamMediaTargets = { images: number; tables: number };

type TeacherExamDetail = {
    examDatabaseId: string;
    examId: string;
    name: string;
    durationMinutes: number;
    passingGrade: number;
    generalInstructions: string;
    startTime: string | null;
    endTime: string | null;
    active: boolean;
    examMode: 'strict' | 'try_out';
    shuffleQuestions: boolean;
    shuffleOptions: boolean;
    language: string;
    subject: string;
    typeDistribution: ExamTypeDistribution;
    difficultyDistribution: ExamDifficultyDistribution;
    mediaTargets: ExamMediaTargets;
    sebRequired: boolean;
    sebSecret: string | null;
    questionCount: number;
    totalSubmissions: number;
    averagePercent: number | null;
    passedCount: number;
    activeTokenCount: number;
};

type TeacherBankQuestionSummary = {
    id: string;
    type: QuestionType;
    language: string;
    subject: string;
    topic: string;
    subtopic: string | null;
    difficulty: Difficulty;
    prompt: string;
    points: number;
    options: QuestionOption[] | null;
    correctAnswer: AnswerValue;
    explanationText: string;
    mediaUrl: string | null;
    mediaType: 'image' | 'audio' | 'video' | null;
    createdByName: string;
    uploadedBy: string | null;
    uploadedByName: string | null;
    createdAt: string;
    sourceFileName: string | null;
};

type BankFilters = {
    language?: string;
    subject?: string;
    topic?: string;
    subtopic?: string;
    difficulty?: Difficulty;
    type?: QuestionType;
};

type BankFilterOptions = {
    languages: string[];
    subjects: string[];
    topics: string[];
    subtopics: string[];
    difficulties: Difficulty[];
    types: QuestionType[];
};

// ============================================================================
// Small helpers — same-origin JSON fetch
// ============================================================================

// Same-origin JSON fetch. CSRF is Origin/Referer-based in this app (no token
// header needed); we just send + accept JSON and throw the server's error
// message on non-2xx so the callers can surface it.
async function apiJson<T>(url: string, init?: RequestInit): Promise<T> {
    const res = await fetch(url, {
        ...init,
        headers: {
            Accept: 'application/json',
            ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
            ...(init?.headers ?? {}),
        },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error((data as { error?: string }).error ?? 'Request failed.');
    }
    return data as T;
}

// ============================================================================
// Classification + formatting (verbatim from the original client)
// ============================================================================

const QUESTION_TYPE_ORDER: QuestionType[] = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];

// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
const DIFFICULTY_ORDER = [
    { key: 'remember', label: 'Remember', pill: 'remember' },
    { key: 'understand', label: 'Understand', pill: 'understand' },
    { key: 'apply', label: 'Apply', pill: 'apply' },
    { key: 'analyze', label: 'Analyze', pill: 'analyze' },
    { key: 'evaluate', label: 'Evaluate', pill: 'evaluate' },
    { key: 'create', label: 'Create', pill: 'create' },
    { key: 'olympiad', label: 'Olympiad', pill: 'olympiad' },
    { key: 'unspecified', label: 'Unspecified', pill: '' },
] as const;

const TYPE_LABELS: Record<QuestionType, string> = {
    single_choice: 'Single choice',
    multi_select: 'Multiple select',
    short_text: 'Short text',
    numeric: 'Numeric',
    essay: 'Structured / Essay',
};

const TYPE_SHORT: Record<QuestionType, string> = {
    single_choice: 'Single',
    multi_select: 'Multi',
    short_text: 'Text',
    numeric: 'Numeric',
    essay: 'Essay',
};

// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
const DIFFICULTY_LABELS: Record<Difficulty, string> = {
    remember: 'Remember',
    understand: 'Understand',
    apply: 'Apply',
    analyze: 'Analyze',
    evaluate: 'Evaluate',
    create: 'Create',
    olympiad: 'Olympiad',
};

const OPTION_LIMITS: Record<'single_choice' | 'multi_select', number> = {
    single_choice: 5,
    multi_select: 6,
};

function formatDate(value: string): string {
    const ms = new Date(value).getTime();
    if (Number.isNaN(ms) || ms < 86_400_000) return '—';
    return new Date(value).toLocaleString();
}

function promptPreview(text: string): string {
    const oneLine = (text || '')
        .replace(/[#*_`>$]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
    return oneLine.length > 90 ? `${oneLine.slice(0, 90)}…` : oneLine;
}

function formatType(type: string): string {
    switch (type) {
        case 'single_choice':
            return 'Single choice';
        case 'multi_select':
            return 'Multi select';
        case 'short_text':
            return 'Short text';
        case 'numeric':
            return 'Numeric';
        case 'essay':
            return 'Essay';
        default:
            return type;
    }
}

function formatAnswer(answer: unknown): string {
    if (answer === null || answer === undefined) return '—';
    if (Array.isArray(answer)) return answer.join(', ');
    return String(answer);
}

// Minimal SEB .seb plist builder + download (port of seb-config.ts).
function escapeXml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

function slugify(value: string): string {
    return (
        value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 40) || 'exam'
    );
}

function downloadSebConfig(examName: string, examUrl: string, hashKey: string): void {
    const xml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>originatorVersion</key>
  <string>Exam Dashboard</string>
  <key>startURL</key>
  <string>${escapeXml(examUrl)}</string>
  <key>hashedAdminPassword</key>
  <string></string>
  <key>hashedQuitPassword</key>
  <string></string>
  <key>browserExamKey</key>
  <string>${escapeXml(hashKey)}</string>
  <key>sendBrowserExamKey</key>
  <true/>
  <key>quitURL</key>
  <string></string>
  <key>enableQuitURL</key>
  <true/>
  <key>browserViewMode</key>
  <integer>0</integer>
  <key>browserWindowAllowReload</key>
  <true/>
  <key>browserWindowShowURL</key>
  <integer>0</integer>
  <key>copyToClipboard</key>
  <false/>
  <key>cutToClipboard</key>
  <false/>
  <key>pasteFromClipboard</key>
  <false/>
  <key>enableF12</key>
  <false/>
  <key>enablePrintScreen</key>
  <false/>
  <key>showTaskBar</key>
  <false/>
  <key>showTime</key>
  <true/>
  <key>showReloadButton</key>
  <true/>
  <key>title</key>
  <string>${escapeXml(examName)}</string>
</dict>
</plist>
`;
    const blob = new Blob([xml], { type: 'application/seb' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${slugify(examName)}.seb`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// ============================================================================
// Page
// ============================================================================

const EXAMS_BASE = '/teacher/exams';
const SCORES_BASE = '/teacher/scores';

export default function ExamDetail() {
    const page = usePage();
    const props = page.props as any;
    const auth = props.auth;
    const user = auth?.user;
    const caps: Record<string, boolean> | null = user?.capabilities ?? null;
    const hasCap = (key: string): boolean => !!caps && caps[key] === true;

    const notFound: boolean = !!props.notFound;
    const examId: string = props.detail?.examId ?? '';

    const [detail, setDetail] = useState<TeacherExamDetail | null>(props.detail ?? null);
    const [tokens, setTokens] = useState<TeacherTokenSummary[] | null>(props.tokens ?? null);
    const [questions, setQuestions] = useState<TeacherQuestionSummary[] | null>(props.questions ?? null);
    const [topicOrder, setTopicOrder] = useState<string[]>(props.topicOrder ?? []);

    const [editingQuestionId, setEditingQuestionId] = useState<string | null>(null);
    const [openQuestionIds, setOpenQuestionIds] = useUIState<string[]>(
        `teacher.exam-detail.${examId}.openQuestionIds`,
        [],
    );
    const toggleQuestion = (id: string): void =>
        setOpenQuestionIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const [showComposition, setShowComposition] = useUIState<boolean>(
        `teacher.exam-detail.${examId}.showComposition`,
        false,
    );
    const [error, setError] = useState('');

    const [selectedNodeKey, setSelectedNodeKey] = useUIState<string>(
        `teacher.exam-detail.${examId}.selectedQuestionNode`,
        '',
    );
    const [expandedKeys, setExpandedKeys] = useUIState<string[]>(
        `teacher.exam-detail.${examId}.expandedQuestionNodes`,
        [],
    );
    const toggleExpand = (key: string): void =>
        setExpandedKeys(expandedKeys.includes(key) ? expandedKeys.filter((k) => k !== key) : [...expandedKeys, key]);

    const [formMaxUses, setFormMaxUses] = useState<number>(50);
    const [formExpiryDays, setFormExpiryDays] = useState<number>(30);
    const [formBusy, setFormBusy] = useState(false);
    const [formMessage, setFormMessage] = useState('');

    const [changingQuestionId, setChangingQuestionId] = useState<string | null>(null);
    const [pickingForQuestionId, setPickingForQuestionId] = useState<string | null>(null);

    async function refresh() {
        if (!examId) return;
        try {
            const [d, t, q] = await Promise.all([
                apiJson<{ exam: TeacherExamDetail }>(`${EXAMS_BASE}/${encodeURIComponent(examId)}/detail`),
                apiJson<{ tokens: TeacherTokenSummary[] }>(`${EXAMS_BASE}/${encodeURIComponent(examId)}/tokens`),
                apiJson<{ questions: TeacherQuestionSummary[]; topicOrder: string[] }>(
                    `${EXAMS_BASE}/${encodeURIComponent(examId)}/questions`,
                ),
            ]);
            setDetail(d.exam);
            setTokens(t.tokens);
            setQuestions(q.questions);
            setTopicOrder(q.topicOrder);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not load exam.');
        }
    }

    async function onAutoFill() {
        setError('');
        try {
            const result = await apiJson<{ added: number; warnings: string[] }>(
                `${EXAMS_BASE}/${encodeURIComponent(examId)}/auto-fill`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            const warn =
                result.warnings.length > 0
                    ? ` Warnings: ${result.warnings.slice(0, 2).join('; ')}${result.warnings.length > 2 ? '…' : ''}`
                    : '';
            setFormMessage(`Auto-filled ${result.added} question(s) from the bank.${warn}`);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not auto-fill.');
        }
    }

    async function onDeleteQuestion(questionId: string) {
        if (
            !window.confirm(
                'Delete this question? If it was sourced from the bank, the bank entry will also be removed (unless another exam still uses it). Cannot be undone.',
            )
        )
            return;
        setError('');
        try {
            await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examId)}/questions/${questionId}`, {
                method: 'DELETE',
            });
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not delete question.');
        }
    }

    async function onAutoReplace(questionId: string) {
        setError('');
        try {
            await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examId)}/questions/${questionId}/replace`, {
                method: 'POST',
                body: JSON.stringify({ mode: 'auto' }),
            });
            setChangingQuestionId(null);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not auto-replace this question.');
        }
    }

    async function onManualReplace(questionId: string, bankId: string) {
        setError('');
        try {
            await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examId)}/questions/${questionId}/replace`, {
                method: 'POST',
                body: JSON.stringify({ mode: 'manual', bankId }),
            });
            setChangingQuestionId(null);
            setPickingForQuestionId(null);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not replace this question.');
        }
    }

    async function onGenerateToken(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!detail) return;
        setFormBusy(true);
        setFormMessage('');
        setError('');
        try {
            const { token } = await apiJson<{ token: TeacherTokenSummary }>(
                `${EXAMS_BASE}/${encodeURIComponent(detail.examId)}/tokens`,
                {
                    method: 'POST',
                    body: JSON.stringify({ maxUses: formMaxUses, expiresInDays: formExpiryDays }),
                },
            );
            setFormMessage(`Generated token ${token.code}.`);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not generate token.');
        } finally {
            setFormBusy(false);
        }
    }

    async function onDeleteToken(tokenCode: string, tokenId: string) {
        if (
            !window.confirm(
                `Delete token "${tokenCode}"?\n\nStudents using this code will lose access. Cannot be undone.`,
            )
        ) {
            return;
        }
        setError('');
        try {
            await apiJson(`/teacher/tokens/${tokenId}`, { method: 'DELETE' });
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not delete token.');
        }
    }

    async function onRegenerateToken(tokenId: string) {
        setError('');
        try {
            const { token } = await apiJson<{ token: TeacherTokenSummary }>(
                `/teacher/tokens/${tokenId}/regenerate`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            setFormMessage(`Regenerated token ${token.code}.`);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not regenerate token.');
        }
    }

    async function onToggleSeb(nextEnabled: boolean) {
        setError('');
        try {
            await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examId)}/seb`, {
                method: 'PATCH',
                body: JSON.stringify({ enabled: nextEnabled }),
            });
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not update SEB setting.');
        }
    }

    async function onRotateSebKey() {
        setError('');
        try {
            await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examId)}/seb`, {
                method: 'PATCH',
                body: JSON.stringify({ enabled: true, rotate: true }),
            });
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not rotate SEB key.');
        }
    }

    function onDownloadSeb() {
        if (!detail || !detail.sebSecret) return;
        const studentUrl = `${window.location.origin}/`;
        downloadSebConfig(detail.name, studentUrl, detail.sebSecret);
    }

    // ---- Topic → Subtopic → Question tree (curriculum-ordered) ----
    type SubtopicBucket = { label: string; key: string; questions: TeacherQuestionSummary[] };
    type TopicBucket = { label: string; key: string; subtopics: SubtopicBucket[]; totalQuestions: number };

    const questionTree: TopicBucket[] = useMemo(() => {
        if (!questions) return [];
        const NO_TOPIC = '(No topic)';
        const NO_SUBTOPIC = '(No subtopic)';
        const map = new Map<string, Map<string, TeacherQuestionSummary[]>>();
        for (const q of questions) {
            const topic = (q.topic || '').trim() || NO_TOPIC;
            const subtopic = (q.subtopic || '').trim() || NO_SUBTOPIC;
            let subMap = map.get(topic);
            if (!subMap) {
                subMap = new Map<string, TeacherQuestionSummary[]>();
                map.set(topic, subMap);
            }
            const list = subMap.get(subtopic) ?? [];
            list.push(q);
            subMap.set(subtopic, list);
        }
        const topicOrderIndex = new Map<string, number>();
        topicOrder.forEach((t, idx) => topicOrderIndex.set(t.trim().toLowerCase(), idx));
        const topicRank = (label: string): number => {
            if (label === NO_TOPIC) return Number.MAX_SAFE_INTEGER;
            const idx = topicOrderIndex.get(label.trim().toLowerCase());
            return idx === undefined ? Number.MAX_SAFE_INTEGER - 1 : idx;
        };
        const sortLabel = (a: string, b: string, sentinel: string) => {
            if (a === sentinel && b !== sentinel) return 1;
            if (b === sentinel && a !== sentinel) return -1;
            return a.localeCompare(b);
        };
        return Array.from(map.entries())
            .sort(([a], [b]) => {
                const ra = topicRank(a);
                const rb = topicRank(b);
                if (ra !== rb) return ra - rb;
                return a.localeCompare(b);
            })
            .map(([topic, subMap]) => {
                const subtopics: SubtopicBucket[] = Array.from(subMap.entries())
                    .sort(([a], [b]) => sortLabel(a, b, NO_SUBTOPIC))
                    .map(([sub, qs]) => ({
                        label: sub,
                        key: `${topic}::${sub}`,
                        questions: [...qs].sort((x, y) => x.position - y.position),
                    }));
                const totalQuestions = subtopics.reduce((acc, s) => acc + s.questions.length, 0);
                return { label: topic, key: topic, subtopics, totalQuestions } as TopicBucket;
            });
    }, [questions, topicOrder]);

    // Categories tree: Topic → Type → Difficulty (each node carries its rows).
    const categoryTree = useMemo(
        () =>
            questionTree.map((topic) => {
                const topicQuestions = topic.subtopics.flatMap((s) => s.questions);
                const types = QUESTION_TYPE_ORDER.map((type) => {
                    const ofType = topicQuestions.filter((q) => q.type === type);
                    if (ofType.length === 0) return null;
                    const difficulties = DIFFICULTY_ORDER.map((d) => {
                        const ofDiff = ofType.filter((q) => (q.difficulty ?? 'unspecified') === d.key);
                        if (ofDiff.length === 0) return null;
                        return { key: d.key, label: d.label, pill: d.pill, count: ofDiff.length, questions: ofDiff };
                    }).filter((d): d is NonNullable<typeof d> => d !== null);
                    return { type, label: formatType(type), count: ofType.length, difficulties, questions: ofType };
                }).filter((y): y is NonNullable<typeof y> => y !== null);
                return { key: topic.key, label: topic.label, count: topic.totalQuestions, types, questions: topicQuestions };
            }),
        [questionTree],
    );

    // Resolve the persisted selectedNodeKey ("ti" / "ti::type" / "ti::type::diff").
    const selectedNode = (() => {
        if (!selectedNodeKey || categoryTree.length === 0) return null;
        const parts = selectedNodeKey.split('::');
        const ti = Number(parts[0]);
        const topic = categoryTree[Number.isFinite(ti) ? ti : 0] ?? categoryTree[0];
        if (parts.length <= 1) return { label: topic.label, questions: topic.questions };
        const type = topic.types.find((y) => y.type === parts[1]);
        if (!type) return { label: topic.label, questions: topic.questions };
        if (parts.length <= 2) return { label: `${topic.label} · ${type.label}`, questions: type.questions };
        const diff = type.difficulties.find((d) => d.key === parts[2]);
        if (!diff) return { label: `${topic.label} · ${type.label}`, questions: type.questions };
        return { label: `${topic.label} · ${type.label} · ${diff.label}`, questions: diff.questions };
    })();

    if (notFound) {
        return (
            <TeacherShell>
                <Head title="Exam not found" />
                <header className="teacher-page-header">
                    <div>
                        <h1>Exam not found</h1>
                        <p>This exam doesn&apos;t exist or has been removed.</p>
                    </div>
                    <Link className="ghost-button" href={EXAMS_BASE}>
                        <ArrowLeft size={17} aria-hidden /> Back to exams
                    </Link>
                </header>
            </TeacherShell>
        );
    }

    if (!detail) {
        return (
            <TeacherShell>
                <Head title="Loading exam…" />
                <header className="teacher-page-header">
                    <div>
                        <h1>{error ? 'Cannot open exam' : 'Loading exam…'}</h1>
                        {error ? <p>{error}</p> : null}
                    </div>
                    <Link className="ghost-button" href={EXAMS_BASE}>
                        <ArrowLeft size={17} aria-hidden /> Back to exams
                    </Link>
                </header>
            </TeacherShell>
        );
    }

    const visibleTokens = tokens === null ? null : tokens.filter((t) => t.active);

    return (
        <TeacherShell>
            <Head title={`Exam · ${detail.name}`} />
            <header className="teacher-page-header">
                <div>
                    <h1>{detail.name}</h1>
                    <p>
                        <code>{detail.examId}</code>
                    </p>
                </div>
                <Link className="ghost-button" href={EXAMS_BASE}>
                    <ArrowLeft size={17} aria-hidden /> Back to exams
                </Link>
            </header>

            {error ? <p className="form-error">{error}</p> : null}

            <section className="admin-metrics">
                <div className="admin-panel metric-card">
                    <FileText size={20} aria-hidden />
                    <span>Questions</span>
                    <strong>{detail.questionCount}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <Clock size={20} aria-hidden />
                    <span>Duration</span>
                    <strong>{detail.durationMinutes} min</strong>
                </div>
                <div className="admin-panel metric-card">
                    <CheckCircle2 size={20} aria-hidden />
                    <span>Passing</span>
                    <strong>{detail.passingGrade}%</strong>
                </div>
                <div className="admin-panel metric-card">
                    <KeyRound size={20} aria-hidden />
                    <span>Active tokens</span>
                    <strong>{detail.activeTokenCount}</strong>
                </div>
            </section>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Exam details</h2>
                        <p>General instructions shown to students before they start.</p>
                    </div>
                    <Link className="ghost-button" href={`${EXAMS_BASE}/${detail.examId}/edit`}>
                        <Settings size={15} aria-hidden /> Edit settings
                    </Link>
                    <Link
                        className="ghost-button"
                        href={`${EXAMS_BASE}/${detail.examId}/audit`}
                        title="Compare raw answer drafts vs the submitted snapshot to prove no answer was lost"
                    >
                        <ShieldCheck size={15} aria-hidden /> Answer audit
                    </Link>
                    <Link
                        className="ghost-button"
                        href={`${EXAMS_BASE}/${detail.examId}/live`}
                        title="Live class monitor — scores each student's current answers on the fly while they sit the exam"
                    >
                        <Activity size={15} aria-hidden /> Live monitor
                    </Link>
                </div>
                <p style={{ marginTop: 8, lineHeight: 1.55 }}>{detail.generalInstructions}</p>
                <div className="exam-detail-grid">
                    <div>
                        <span>Status</span>
                        <strong>
                            {detail.active ? (
                                <span className="status-item neutral">
                                    <CheckCircle2 size={14} aria-hidden /> Active
                                </span>
                            ) : (
                                <span className="status-item warning">
                                    <CircleX size={14} aria-hidden /> Inactive
                                </span>
                            )}
                        </strong>
                    </div>
                    <div>
                        <span>Mode</span>
                        <strong>{detail.examMode === 'strict' ? 'Strict (real exam)' : 'Try Out'}</strong>
                    </div>
                    <div>
                        <span>Shuffle</span>
                        <strong>
                            {detail.shuffleQuestions || detail.shuffleOptions
                                ? [detail.shuffleQuestions ? 'questions' : null, detail.shuffleOptions ? 'options' : null]
                                      .filter(Boolean)
                                      .join(' + ')
                                : 'off'}
                        </strong>
                    </div>
                    <div>
                        <span>Submissions</span>
                        <strong>{detail.totalSubmissions}</strong>
                    </div>
                    <div>
                        <span>Average score</span>
                        <strong>{detail.averagePercent === null ? '—' : `${detail.averagePercent}%`}</strong>
                    </div>
                    <div>
                        <span>Passed</span>
                        <strong>{detail.passedCount}</strong>
                    </div>
                </div>
            </section>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Composition targets</h2>
                        <p>What this exam aims for — used as a checklist when adding questions.</p>
                    </div>
                    <button
                        type="button"
                        className="ghost-button"
                        onClick={() => setShowComposition((v) => !v)}
                        aria-expanded={showComposition}
                    >
                        {showComposition ? (
                            <>
                                <ChevronDown size={15} aria-hidden /> Hide
                            </>
                        ) : (
                            <>
                                <ChevronRight size={15} aria-hidden /> Show composition targets
                            </>
                        )}
                    </button>
                </div>
                {showComposition ? (
                    <div className="composition-grid">
                        <div className="composition-block">
                            <span>Language &amp; subject</span>
                            <strong>
                                {detail.language}
                                {detail.subject ? (
                                    <>
                                        {' · '}
                                        {detail.subject}
                                    </>
                                ) : (
                                    <span style={{ color: 'var(--muted)', fontWeight: 500 }}>{' · any subject'}</span>
                                )}
                            </strong>
                        </div>
                        <div className="composition-block">
                            <span>Type targets</span>
                            <ul>
                                <li>
                                    Single choice: <strong>{detail.typeDistribution.single_choice}</strong>
                                </li>
                                <li>
                                    Multi select: <strong>{detail.typeDistribution.multi_select}</strong>
                                </li>
                                <li>
                                    Short text: <strong>{detail.typeDistribution.short_text}</strong>
                                </li>
                                <li>
                                    Numeric: <strong>{detail.typeDistribution.numeric}</strong>
                                </li>
                                <li>
                                    Essay: <strong>{detail.typeDistribution.essay}</strong>
                                </li>
                            </ul>
                        </div>
                        <div className="composition-block">
                            <span>Cognitive level mix (Bloom&apos;s)</span>
                            <ul>
                                {(
                                    [
                                        ['remember', 'Remember'],
                                        ['understand', 'Understand'],
                                        ['apply', 'Apply'],
                                        ['analyze', 'Analyze'],
                                        ['evaluate', 'Evaluate'],
                                        ['create', 'Create'],
                                        ['olympiad', 'Olympiad'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <li key={key}>
                                        <span className={`difficulty-pill ${key}`}>{label}</span>
                                        <strong>{(detail.difficultyDistribution as any)[key] ?? 0}%</strong>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="composition-block">
                            <span>Media targets</span>
                            <ul>
                                <li>
                                    Images: <strong>{detail.mediaTargets.images}</strong>
                                </li>
                                <li>
                                    Tables: <strong>{detail.mediaTargets.tables}</strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                ) : null}
            </section>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Questions</h2>
                        <p>{questions?.length ?? 0} question(s) in this exam. Students see them in order.</p>
                    </div>
                </div>

                {questions === null ? (
                    <p style={{ color: 'var(--muted)' }}>Loading questions…</p>
                ) : questions.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No questions yet. Add the first one below.</p>
                ) : (
                    <div
                        className="exam-question-panels"
                        style={{
                            display: 'grid',
                            gridTemplateColumns: 'minmax(190px, 260px) 1fr',
                            gap: 16,
                            alignItems: 'start',
                        }}
                    >
                        {/* Panel 1 — question categories */}
                        <div className="admin-panel" style={{ padding: 6 }}>
                            {categoryTree.map((topic, ti) => {
                                const sel = selectedNodeKey;
                                const tKey = `${ti}`;
                                const tExpanded = expandedKeys.includes(tKey);
                                return (
                                    <div key={topic.key} style={{ marginBottom: 2 }}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (topic.types.length > 0) toggleExpand(tKey);
                                                setSelectedNodeKey(tKey);
                                            }}
                                            style={{
                                                width: '100%',
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 6,
                                                textAlign: 'left',
                                                border: 'none',
                                                cursor: 'pointer',
                                                borderRadius: 6,
                                                padding: '8px 10px',
                                                fontSize: '0.9rem',
                                                background: sel === tKey ? 'var(--surface-strong, #eef2ff)' : 'transparent',
                                                borderLeft: sel === tKey ? '3px solid #4f46e5' : '3px solid transparent',
                                            }}
                                        >
                                            {topic.types.length > 0 ? (
                                                tExpanded ? (
                                                    <ChevronDown size={15} aria-hidden />
                                                ) : (
                                                    <ChevronRight size={15} aria-hidden />
                                                )
                                            ) : (
                                                <span style={{ width: 15, display: 'inline-block' }} />
                                            )}
                                            <strong>{topic.label}</strong>
                                            <span style={{ color: 'var(--muted)', fontWeight: 400 }}>· {topic.count}</span>
                                        </button>
                                        {tExpanded &&
                                            topic.types.map((type) => {
                                                const yKey = `${ti}::${type.type}`;
                                                const yExpanded = expandedKeys.includes(yKey);
                                                return (
                                                    <div key={yKey}>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                if (type.difficulties.length > 0) toggleExpand(yKey);
                                                                setSelectedNodeKey(yKey);
                                                            }}
                                                            style={{
                                                                width: '100%',
                                                                display: 'flex',
                                                                alignItems: 'center',
                                                                gap: 6,
                                                                textAlign: 'left',
                                                                border: 'none',
                                                                cursor: 'pointer',
                                                                borderRadius: 6,
                                                                padding: '6px 10px 6px 26px',
                                                                fontSize: '0.85rem',
                                                                background:
                                                                    sel === yKey ? 'var(--surface-strong, #eef2ff)' : 'transparent',
                                                                borderLeft:
                                                                    sel === yKey ? '3px solid #4f46e5' : '3px solid transparent',
                                                            }}
                                                        >
                                                            {type.difficulties.length > 0 ? (
                                                                yExpanded ? (
                                                                    <ChevronDown size={13} aria-hidden />
                                                                ) : (
                                                                    <ChevronRight size={13} aria-hidden />
                                                                )
                                                            ) : (
                                                                <span style={{ width: 13, display: 'inline-block' }} />
                                                            )}
                                                            {type.label}
                                                            <span style={{ color: 'var(--muted)' }}> · {type.count}</span>
                                                        </button>
                                                        {yExpanded &&
                                                            type.difficulties.map((d) => {
                                                                const dKey = `${ti}::${type.type}::${d.key}`;
                                                                return (
                                                                    <button
                                                                        key={dKey}
                                                                        type="button"
                                                                        onClick={() => setSelectedNodeKey(dKey)}
                                                                        style={{
                                                                            width: '100%',
                                                                            display: 'flex',
                                                                            alignItems: 'center',
                                                                            gap: 6,
                                                                            textAlign: 'left',
                                                                            border: 'none',
                                                                            cursor: 'pointer',
                                                                            borderRadius: 6,
                                                                            padding: '5px 10px 5px 44px',
                                                                            background:
                                                                                sel === dKey
                                                                                    ? 'var(--surface-strong, #eef2ff)'
                                                                                    : 'transparent',
                                                                            borderLeft:
                                                                                sel === dKey
                                                                                    ? '3px solid #4f46e5'
                                                                                    : '3px solid transparent',
                                                                        }}
                                                                    >
                                                                        <span className={`difficulty-pill ${d.pill}`}>{d.label}</span>
                                                                        <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                                                                            · {d.count}
                                                                        </span>
                                                                    </button>
                                                                );
                                                            })}
                                                    </div>
                                                );
                                            })}
                                    </div>
                                );
                            })}
                        </div>

                        {/* Panel 2 — questions in the selected category */}
                        <div className="admin-panel" style={{ padding: 16 }}>
                            {selectedNode === null ? (
                                <p style={{ color: 'var(--muted)' }}>Select a category on the left.</p>
                            ) : (
                                <>
                                    <h3 style={{ marginTop: 0 }}>{selectedNode.label}</h3>
                                    {selectedNode.questions.length === 0 ? (
                                        <p style={{ color: 'var(--muted)' }}>No questions in this selection.</p>
                                    ) : (
                                        <ol className="question-list exam-tree-question-list">
                                            {selectedNode.questions.map((question) =>
                                                editingQuestionId === question.id ? (
                                                    <li key={question.id} className="question-list-item">
                                                        <QuestionForm
                                                            examIdentifier={examId}
                                                            initialQuestion={question}
                                                            onSaved={() => {
                                                                setEditingQuestionId(null);
                                                                refresh();
                                                            }}
                                                            onCancel={() => setEditingQuestionId(null)}
                                                        />
                                                    </li>
                                                ) : (
                                                    <li key={question.id} className="question-list-item">
                                                        <div
                                                            onClick={() => toggleQuestion(question.id)}
                                                            style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}
                                                        >
                                                            {openQuestionIds.includes(question.id) ? (
                                                                <ChevronDown size={14} aria-hidden />
                                                            ) : (
                                                                <ChevronRight size={14} aria-hidden />
                                                            )}
                                                            <strong style={{ flexShrink: 0 }}>{question.position}.</strong>
                                                            <span
                                                                style={{
                                                                    flex: 1,
                                                                    minWidth: 0,
                                                                    overflow: 'hidden',
                                                                    textOverflow: 'ellipsis',
                                                                    whiteSpace: 'nowrap',
                                                                }}
                                                            >
                                                                {promptPreview(question.prompt)}
                                                            </span>
                                                            <span className="question-type-pill">{formatType(question.type)}</span>
                                                            {question.difficulty ? (
                                                                <span className={`difficulty-pill ${question.difficulty}`}>
                                                                    {DIFFICULTY_ORDER.find((x) => x.key === question.difficulty)?.label ??
                                                                        question.difficulty}
                                                                </span>
                                                            ) : null}
                                                            <span style={{ color: 'var(--muted)', fontSize: '0.8rem', flexShrink: 0 }}>
                                                                {question.points} pt{question.points === 1 ? '' : 's'}
                                                            </span>
                                                        </div>
                                                        {openQuestionIds.includes(question.id) && (
                                                            <div style={{ marginTop: 8 }}>
                                                                {question.sourceBankQuestionId ? (
                                                                    <p
                                                                        style={{
                                                                            color: 'var(--muted)',
                                                                            fontSize: '0.78rem',
                                                                            margin: '0 0 6px',
                                                                        }}
                                                                    >
                                                                        from bank
                                                                    </p>
                                                                ) : null}
                                                                <div className="question-list-prompt">
                                                                    <MarkdownContent text={question.prompt} className="question-prompt" />
                                                                </div>
                                                                {question.media.length > 0 ? (
                                                                    <div className="exam-tree-question-media">
                                                                        {question.media.map((m) => (
                                                                            <div key={m.id} className="exam-tree-media-figure">
                                                                                <MediaRenderer media={m} />
                                                                            </div>
                                                                        ))}
                                                                    </div>
                                                                ) : null}
                                                                {question.options ? (
                                                                    <div className="option-list">
                                                                        {question.options.map((option, idx) => {
                                                                            const isCorrect = Array.isArray(question.correctAnswer)
                                                                                ? question.correctAnswer.includes(option.id)
                                                                                : question.correctAnswer === option.id;
                                                                            return (
                                                                                <div
                                                                                    key={option.id}
                                                                                    className={`option-row${isCorrect ? ' option-correct' : ''}`}
                                                                                >
                                                                                    <span className="option-key">
                                                                                        {String.fromCharCode(65 + idx)}
                                                                                    </span>
                                                                                    <MarkdownContent text={option.text} />
                                                                                    {isCorrect ? <span className="correct-flag">correct</span> : null}
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                ) : (
                                                                    <p className="question-list-answer">
                                                                        Correct answer: <strong>{formatAnswer(question.correctAnswer)}</strong>
                                                                    </p>
                                                                )}
                                                                <div className="question-list-explanation">
                                                                    <MarkdownContent text={question.explanationText} />
                                                                </div>
                                                                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                                                                    <button
                                                                        className="ghost-button"
                                                                        type="button"
                                                                        onClick={() => setEditingQuestionId(question.id)}
                                                                    >
                                                                        Edit
                                                                    </button>
                                                                    <button
                                                                        className="ghost-button"
                                                                        type="button"
                                                                        onClick={() =>
                                                                            setChangingQuestionId(
                                                                                changingQuestionId === question.id ? null : question.id,
                                                                            )
                                                                        }
                                                                    >
                                                                        Change
                                                                    </button>
                                                                    <button
                                                                        className="ghost-button danger"
                                                                        type="button"
                                                                        onClick={() => onDeleteQuestion(question.id)}
                                                                    >
                                                                        Delete
                                                                    </button>
                                                                </div>
                                                                {changingQuestionId === question.id && pickingForQuestionId !== question.id ? (
                                                                    <div
                                                                        className="exam-tree-change-chooser"
                                                                        style={{
                                                                            display: 'flex',
                                                                            gap: 8,
                                                                            marginTop: 8,
                                                                            padding: 8,
                                                                            background: 'var(--surface-strong)',
                                                                            borderRadius: 6,
                                                                            alignItems: 'center',
                                                                        }}
                                                                    >
                                                                        <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>
                                                                            Change with:
                                                                        </span>
                                                                        <button
                                                                            className="ghost-button"
                                                                            type="button"
                                                                            onClick={() => setPickingForQuestionId(question.id)}
                                                                        >
                                                                            Pick from bank
                                                                        </button>
                                                                        <button
                                                                            className="ghost-button"
                                                                            type="button"
                                                                            onClick={() => onAutoReplace(question.id)}
                                                                            title="Server picks the next available bank question (same difficulty → same subtopic → same topic, skipping anything already in this exam)"
                                                                        >
                                                                            Auto fill
                                                                        </button>
                                                                        <button
                                                                            className="ghost-button"
                                                                            type="button"
                                                                            onClick={() => setChangingQuestionId(null)}
                                                                            style={{ marginLeft: 'auto' }}
                                                                        >
                                                                            Cancel
                                                                        </button>
                                                                    </div>
                                                                ) : null}
                                                                {pickingForQuestionId === question.id ? (
                                                                    <div style={{ marginTop: 8 }}>
                                                                        <BankPicker
                                                                            examIdentifier={examId}
                                                                            onAdded={refresh}
                                                                            replaceMode
                                                                            onPickedForReplace={(bankId) =>
                                                                                onManualReplace(question.id, bankId)
                                                                            }
                                                                            onCancelReplace={() => setPickingForQuestionId(null)}
                                                                        />
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                )}

                <div style={{ marginTop: '18px', display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <QuestionForm examIdentifier={examId} onSaved={refresh} />
                    <BankPicker examIdentifier={examId} onAdded={refresh} />
                    <button className="ghost-button" type="button" onClick={onAutoFill}>
                        Auto-fill from bank
                    </button>
                </div>
            </section>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Tokens for this exam</h2>
                        <p>Generate or disable access tokens scoped to {detail.name}.</p>
                    </div>
                </div>

                <form className="token-form" onSubmit={onGenerateToken}>
                    <label>
                        Max uses
                        <input
                            type="number"
                            min={1}
                            max={5000}
                            value={formMaxUses}
                            onChange={(event) => setFormMaxUses(Number(event.target.value))}
                            required
                        />
                    </label>
                    <label>
                        Expires in (days, 0 = never)
                        <input
                            type="number"
                            min={0}
                            max={3650}
                            value={formExpiryDays}
                            onChange={(event) => setFormExpiryDays(Number(event.target.value))}
                            required
                        />
                    </label>
                    <button className="primary-button" type="submit" disabled={formBusy}>
                        <Plus size={17} aria-hidden />
                        {formBusy ? 'Generating…' : 'Generate token'}
                    </button>
                </form>
                {formMessage ? <p className="form-success">{formMessage}</p> : null}

                {visibleTokens === null ? (
                    <p style={{ color: 'var(--muted)' }}>Loading tokens…</p>
                ) : visibleTokens.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No active tokens for this exam.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Created by</th>
                                <th>Created</th>
                                <th>Expires</th>
                                <th>Uses</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleTokens.map((token) => (
                                <tr key={token.id}>
                                    <td>
                                        <span className="token-pill">
                                            <KeyRound size={13} aria-hidden />
                                            {token.code}
                                        </span>
                                    </td>
                                    <td>{token.createdByName}</td>
                                    <td>{formatDate(token.createdAt)}</td>
                                    <td>{token.expiresAt ? formatDate(token.expiresAt) : '—'}</td>
                                    <td>
                                        {token.usedCount} / {token.maxUses}
                                    </td>
                                    <td style={{ display: 'flex', gap: 8 }}>
                                        <button
                                            className="ghost-button"
                                            type="button"
                                            onClick={() => onRegenerateToken(token.id)}
                                            title="Regenerate this token (issues a fresh code, carrying over remaining uses)"
                                        >
                                            <KeyRound size={14} aria-hidden /> Regenerate
                                        </button>
                                        <button
                                            className="ghost-button danger"
                                            type="button"
                                            onClick={() => onDeleteToken(token.code, token.id)}
                                            title="Delete this token"
                                        >
                                            <Trash2 size={14} aria-hidden /> Delete
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            {hasCap('exam.config.seb') ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Anti-cheating</h2>
                            <p>
                                For strict-mode exams, require Safe Exam Browser. SEB locks the student device into kiosk mode
                                and signs every request with the per-exam key below.
                            </p>
                        </div>
                        <label className="report-toggle" style={{ alignItems: 'center', gap: 8 }}>
                            <input
                                type="checkbox"
                                checked={detail.sebRequired}
                                onChange={(event) => onToggleSeb(event.target.checked)}
                            />
                            <strong>Require SEB</strong>
                        </label>
                    </div>
                    {detail.examMode !== 'strict' && detail.sebRequired ? (
                        <p style={{ color: 'var(--amber, #b45309)', marginTop: 6 }}>
                            Heads up: this exam is in Try Out mode, so the SEB requirement won&apos;t be enforced. Switch the
                            exam to Strict mode to apply the gate.
                        </p>
                    ) : null}
                    {detail.sebRequired && detail.sebSecret ? (
                        <div className="exam-detail-grid" style={{ marginTop: 12 }}>
                            <div>
                                <span>Browser Exam Key</span>
                                <strong>
                                    <code>{detail.sebSecret}</code>
                                </strong>
                            </div>
                            <div>
                                <span>Distribution</span>
                                <strong>
                                    <code>{detail.name}</code>.seb
                                </strong>
                            </div>
                            <div style={{ gridColumn: '1 / -1' }}>
                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    <button className="primary-button" type="button" onClick={onDownloadSeb}>
                                        Download .seb config
                                    </button>
                                    <button className="ghost-button" type="button" onClick={onRotateSebKey}>
                                        Rotate key
                                    </button>
                                </div>
                                <p style={{ color: 'var(--muted)', marginTop: 6, fontSize: '0.85rem' }}>
                                    Give the <code>.seb</code> file plus the exam token to students. They open the file with Safe
                                    Exam Browser — SEB launches in kiosk mode and pins to this URL.
                                </p>
                            </div>
                        </div>
                    ) : null}
                </section>
            ) : null}

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Submissions &amp; grading</h2>
                        <p>
                            Student submissions and essay grading live on the <strong>Scores</strong> page.
                        </p>
                    </div>
                    <Link className="ghost-button" href={SCORES_BASE}>
                        Open Scores
                    </Link>
                </div>
                <div className="exam-detail-grid">
                    <div>
                        <span>Submissions</span>
                        <strong>{detail.totalSubmissions}</strong>
                    </div>
                    <div>
                        <span>Average score</span>
                        <strong>{detail.averagePercent === null ? '—' : `${detail.averagePercent}%`}</strong>
                    </div>
                    <div>
                        <span>Passed</span>
                        <strong>{detail.passedCount}</strong>
                    </div>
                </div>
            </section>
        </TeacherShell>
    );
}

// ============================================================================
// QuestionForm (inline helper) — add / inline-edit a question.
// Edits exactly the fields the server persists: type, topic, prompt, points,
// options, correctAnswer, explanationText. Difficulty + media are shown
// read-only in the tree preview (the original form + PATCH route don't write
// them).
// ============================================================================

function makeDefaultOptions(type: QuestionType): QuestionOption[] {
    const count =
        type === 'single_choice' ? OPTION_LIMITS.single_choice : type === 'multi_select' ? OPTION_LIMITS.multi_select : 0;
    return Array.from({ length: count }, (_, i) => ({ id: String.fromCharCode('A'.charCodeAt(0) + i), text: '' }));
}

function QuestionForm({
    examIdentifier,
    initialQuestion,
    onSaved,
    onCancel,
}: {
    examIdentifier: string;
    initialQuestion?: TeacherQuestionSummary;
    onSaved: () => void;
    onCancel?: () => void;
}) {
    const isEdit = !!initialQuestion;
    const [open, setOpen] = useState(isEdit);

    const [type, setType] = useState<QuestionType>(initialQuestion?.type ?? 'single_choice');
    const [topic, setTopic] = useState(initialQuestion?.topic ?? '');
    const [prompt, setPrompt] = useState(initialQuestion?.prompt ?? '');
    const [points, setPoints] = useState(initialQuestion?.points ?? 1);
    const [options, setOptions] = useState<QuestionOption[]>(() => {
        if (initialQuestion?.options) return initialQuestion.options.map((option) => ({ ...option }));
        return makeDefaultOptions(initialQuestion?.type ?? 'single_choice');
    });
    const [singleCorrect, setSingleCorrect] = useState(() => {
        if (initialQuestion?.type === 'single_choice' && typeof initialQuestion.correctAnswer === 'string') {
            return initialQuestion.correctAnswer;
        }
        return 'A';
    });
    const [multiCorrect, setMultiCorrect] = useState<string[]>(() => {
        if (initialQuestion?.type === 'multi_select' && Array.isArray(initialQuestion.correctAnswer)) {
            return initialQuestion.correctAnswer as string[];
        }
        return [];
    });
    const [textCorrect, setTextCorrect] = useState(() => {
        if (initialQuestion?.type === 'short_text' && typeof initialQuestion.correctAnswer === 'string') {
            return initialQuestion.correctAnswer;
        }
        return '';
    });
    const [numericCorrect, setNumericCorrect] = useState(() => {
        if (initialQuestion?.type === 'numeric' && typeof initialQuestion.correctAnswer === 'number') {
            return initialQuestion.correctAnswer;
        }
        return 0;
    });
    const [explanationText, setExplanationText] = useState(initialQuestion?.explanationText ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    function resetForCreate() {
        setType('single_choice');
        setTopic('');
        setPrompt('');
        setPoints(1);
        setOptions(makeDefaultOptions('single_choice'));
        setSingleCorrect('A');
        setMultiCorrect([]);
        setTextCorrect('');
        setNumericCorrect(0);
        setExplanationText('');
        setError('');
    }

    function onTypeChange(next: QuestionType) {
        setType(next);
        if (next === 'single_choice' || next === 'multi_select') {
            const cap = OPTION_LIMITS[next];
            setOptions((current) => {
                const usable = current.filter((opt) => opt.text.trim().length > 0);
                if (usable.length >= 2 && usable.length <= cap) {
                    return usable.map((opt, i) => ({ id: String.fromCharCode('A'.charCodeAt(0) + i), text: opt.text }));
                }
                return makeDefaultOptions(next);
            });
            if (next === 'single_choice') setMultiCorrect([]);
            else setSingleCorrect('A');
        }
    }

    function setOptionText(index: number, text: string) {
        setOptions((current) => current.map((option, i) => (i === index ? { ...option, text } : option)));
    }

    function addOption() {
        setOptions((current) => {
            const cap = type === 'multi_select' ? OPTION_LIMITS.multi_select : OPTION_LIMITS.single_choice;
            if (current.length >= cap) return current;
            const nextId = String.fromCharCode('A'.charCodeAt(0) + current.length);
            return [...current, { id: nextId, text: '' }];
        });
    }

    function removeOption(index: number) {
        setOptions((current) => {
            if (current.length <= 2) return current;
            return current
                .filter((_, i) => i !== index)
                .map((option, i) => ({ id: String.fromCharCode('A'.charCodeAt(0) + i), text: option.text }));
        });
    }

    function toggleMultiCorrect(id: string) {
        setMultiCorrect((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    }

    async function onSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError('');

        const isChoiceType = type === 'single_choice' || type === 'multi_select';
        const cleanedOptions = isChoiceType ? options.filter((opt) => opt.text.trim().length > 0) : null;

        let correctAnswer: AnswerValue;
        if (type === 'single_choice') correctAnswer = singleCorrect;
        else if (type === 'multi_select') correctAnswer = multiCorrect;
        else if (type === 'short_text') correctAnswer = textCorrect;
        else correctAnswer = numericCorrect;

        try {
            const payload = { type, topic, prompt, points, options: cleanedOptions, correctAnswer, explanationText };
            if (isEdit && initialQuestion) {
                await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examIdentifier)}/questions/${initialQuestion.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
                onSaved();
            } else {
                await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examIdentifier)}/questions`, {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                resetForCreate();
                setOpen(false);
                onSaved();
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : `Could not ${isEdit ? 'update' : 'add'} question.`);
        } finally {
            setBusy(false);
        }
    }

    function handleCancel() {
        if (isEdit) {
            onCancel?.();
        } else {
            resetForCreate();
            setOpen(false);
        }
    }

    if (!open && !isEdit) {
        return (
            <button className="primary-button" type="button" onClick={() => setOpen(true)}>
                <Plus size={17} aria-hidden /> Add question
            </button>
        );
    }

    const isChoice = type === 'single_choice' || type === 'multi_select';

    return (
        <form className="question-form" onSubmit={onSubmit}>
            <div className="question-form-row">
                <label>
                    Type
                    <select value={type} onChange={(event) => onTypeChange(event.target.value as QuestionType)}>
                        {Object.entries(TYPE_LABELS).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    Topic
                    <input value={topic} onChange={(event) => setTopic(event.target.value)} placeholder="e.g. Algebra" required />
                </label>
                <label>
                    Points
                    <input
                        type="number"
                        min={1}
                        max={100}
                        value={points}
                        onChange={(event) => setPoints(Number(event.target.value))}
                        required
                    />
                </label>
            </div>

            <label>
                Prompt
                <textarea
                    value={prompt}
                    onChange={(event) => setPrompt(event.target.value)}
                    rows={3}
                    placeholder="Write the question prompt the student will read."
                    required
                />
            </label>

            {isChoice ? (
                <div className="options-block">
                    <div className="options-block-header">
                        <strong>Options &amp; correct answer</strong>
                        <button
                            className="ghost-button"
                            type="button"
                            onClick={addOption}
                            disabled={options.length >= (type === 'multi_select' ? OPTION_LIMITS.multi_select : OPTION_LIMITS.single_choice)}
                        >
                            <Plus size={14} aria-hidden /> Add option
                        </button>
                    </div>
                    {options.map((option, index) => (
                        <div className="option-row-edit" key={`${option.id}-${index}`}>
                            <span className="option-id-chip">{option.id}</span>
                            <input
                                value={option.text}
                                onChange={(event) => setOptionText(index, event.target.value)}
                                placeholder={`Option ${option.id}`}
                            />
                            {type === 'single_choice' ? (
                                <label className="option-correct-toggle">
                                    <input
                                        type="radio"
                                        name={`single-correct-${initialQuestion?.id ?? 'new'}`}
                                        value={option.id}
                                        checked={singleCorrect === option.id}
                                        onChange={() => setSingleCorrect(option.id)}
                                    />
                                    Correct
                                </label>
                            ) : (
                                <label className="option-correct-toggle">
                                    <input
                                        type="checkbox"
                                        checked={multiCorrect.includes(option.id)}
                                        onChange={() => toggleMultiCorrect(option.id)}
                                    />
                                    Correct
                                </label>
                            )}
                            <button
                                className="ghost-button"
                                type="button"
                                onClick={() => removeOption(index)}
                                disabled={options.length <= 2}
                                style={{ minWidth: 0, padding: '6px 10px' }}
                            >
                                Remove
                            </button>
                        </div>
                    ))}
                </div>
            ) : null}

            {type === 'short_text' ? (
                <label>
                    Correct answer (text)
                    <input
                        value={textCorrect}
                        onChange={(event) => setTextCorrect(event.target.value)}
                        placeholder="The expected answer"
                        required
                    />
                    <small>Comparison is case-insensitive after trimming whitespace.</small>
                </label>
            ) : null}

            {type === 'numeric' ? (
                <label>
                    Correct answer (number)
                    <input
                        type="number"
                        value={numericCorrect}
                        onChange={(event) => setNumericCorrect(Number(event.target.value))}
                        required
                    />
                </label>
            ) : null}

            <label>
                Explanation (shown after submission)
                <textarea
                    value={explanationText}
                    onChange={(event) => setExplanationText(event.target.value)}
                    rows={2}
                    placeholder="Why this is the right answer."
                    required
                />
            </label>

            {error ? <p className="form-error">{error}</p> : null}

            <div className="question-form-actions">
                <button className="primary-button" type="submit" disabled={busy}>
                    <Save size={17} aria-hidden />
                    {busy ? 'Saving…' : isEdit ? 'Save changes' : 'Save question'}
                </button>
                <button className="ghost-button" type="button" onClick={handleCancel}>
                    Cancel
                </button>
            </div>
        </form>
    );
}

// ============================================================================
// BankPicker (inline helper) — 6 filter dropdowns + media thumbnails + the
// "In exam" disabled badge. Multi-add by default; single-pick in replaceMode.
// ============================================================================

function BankPicker({
    examIdentifier,
    onAdded,
    replaceMode,
    onPickedForReplace,
    onCancelReplace,
}: {
    examIdentifier: string;
    onAdded: () => void;
    replaceMode?: boolean;
    onPickedForReplace?: (bankId: string) => void | Promise<void>;
    onCancelReplace?: () => void;
}) {
    const [open, setOpen] = useState<boolean>(false);
    const actuallyOpen = replaceMode ? true : open;

    const [questions, setQuestions] = useState<TeacherBankQuestionSummary[] | null>(null);
    const [filterOptions, setFilterOptions] = useState<BankFilterOptions>({
        languages: [],
        subjects: [],
        topics: [],
        subtopics: [],
        difficulties: [],
        types: [],
    });
    const [filters, setFilters] = useUIState<BankFilters>(
        `teacher.exam-detail.${examIdentifier}.bankPickerFilters`,
        {},
    );
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [inExam, setInExam] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const refresh = useCallback(async () => {
        try {
            const qs = new URLSearchParams();
            if (filters.language) qs.set('language', filters.language);
            if (filters.subject) qs.set('subject', filters.subject);
            if (filters.topic) qs.set('topic', filters.topic);
            if (filters.subtopic) qs.set('subtopic', filters.subtopic);
            if (filters.difficulty) qs.set('difficulty', filters.difficulty);
            if (filters.type) qs.set('type', filters.type);
            const qstr = qs.toString();
            const [list, opts, inExamIds] = await Promise.all([
                apiJson<{ questions: TeacherBankQuestionSummary[] }>(`/teacher/bank-picker${qstr ? `?${qstr}` : ''}`),
                apiJson<BankFilterOptions>('/teacher/bank-picker/options'),
                apiJson<{ ids: string[] }>(`${EXAMS_BASE}/${encodeURIComponent(examIdentifier)}/bank-in-exam`),
            ]);
            setQuestions(list.questions);
            setFilterOptions(opts);
            setInExam(new Set(inExamIds.ids));
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not load bank.');
        }
    }, [filters, examIdentifier]);

    useEffect(() => {
        if (!actuallyOpen) return;
        refresh();
    }, [actuallyOpen, refresh]);

    function setFilter<K extends keyof BankFilters>(key: K, value: BankFilters[K]) {
        setFilters((current) => {
            const next = { ...current };
            if (value === undefined || value === ('' as unknown as BankFilters[K])) delete next[key];
            else next[key] = value;
            return next;
        });
    }

    function toggle(id: string) {
        setSelected((current) => {
            if (replaceMode) return new Set([id]);
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    async function onAddSelected() {
        if (selected.size === 0) return;
        setBusy(true);
        setError('');
        try {
            if (replaceMode) {
                const [only] = Array.from(selected);
                if (only && onPickedForReplace) await onPickedForReplace(only);
                setSelected(new Set());
            } else {
                await apiJson(`${EXAMS_BASE}/${encodeURIComponent(examIdentifier)}/questions/from-bank`, {
                    method: 'POST',
                    body: JSON.stringify({ bankIds: Array.from(selected) }),
                });
                setOpen(false);
                setSelected(new Set());
                setFilters({});
                onAdded();
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not add questions.');
        } finally {
            setBusy(false);
        }
    }

    if (!actuallyOpen) {
        return (
            <button className="ghost-button" type="button" onClick={() => setOpen(true)}>
                <Library size={15} aria-hidden /> Pick from bank
            </button>
        );
    }

    return (
        <div className="question-form" style={{ marginTop: 18 }}>
            <div className="section-title-row">
                <div>
                    <h3 style={{ margin: 0 }}>Pick from question bank</h3>
                    <p style={{ margin: '4px 0 0', color: 'var(--muted)', fontSize: '0.85rem' }}>
                        Selected questions are copied into this exam (the bank stays unchanged).
                    </p>
                </div>
            </div>

            <div className="bank-filter-row">
                <select value={filters.language ?? ''} onChange={(event) => setFilter('language', event.target.value)}>
                    <option value="">All languages</option>
                    {filterOptions.languages.map((l) => (
                        <option key={l} value={l}>
                            {l}
                        </option>
                    ))}
                </select>
                <select value={filters.subject ?? ''} onChange={(event) => setFilter('subject', event.target.value)}>
                    <option value="">All subjects</option>
                    {filterOptions.subjects.map((s) => (
                        <option key={s} value={s}>
                            {s}
                        </option>
                    ))}
                </select>
                <select value={filters.topic ?? ''} onChange={(event) => setFilter('topic', event.target.value)}>
                    <option value="">All topics</option>
                    {filterOptions.topics.map((s) => (
                        <option key={s} value={s}>
                            {s}
                        </option>
                    ))}
                </select>
                <select value={filters.subtopic ?? ''} onChange={(event) => setFilter('subtopic', event.target.value)}>
                    <option value="">All subtopics</option>
                    {filterOptions.subtopics.map((s) => (
                        <option key={s} value={s}>
                            {s}
                        </option>
                    ))}
                </select>
                <select
                    value={filters.difficulty ?? ''}
                    onChange={(event) => setFilter('difficulty', (event.target.value || undefined) as Difficulty | undefined)}
                >
                    <option value="">All difficulties</option>
                    {filterOptions.difficulties.map((d) => (
                        <option key={d} value={d}>
                            {DIFFICULTY_LABELS[d]}
                        </option>
                    ))}
                </select>
                <select
                    value={filters.type ?? ''}
                    onChange={(event) => setFilter('type', (event.target.value || undefined) as QuestionType | undefined)}
                >
                    <option value="">All types</option>
                    {filterOptions.types.map((t) => (
                        <option key={t} value={t}>
                            {TYPE_SHORT[t]}
                        </option>
                    ))}
                </select>
            </div>

            {error ? <p className="form-error">{error}</p> : null}

            {questions === null ? (
                <p style={{ color: 'var(--muted)' }}>Loading…</p>
            ) : questions.length === 0 ? (
                <p style={{ color: 'var(--muted)' }}>No questions match these filters.</p>
            ) : (
                <ul className="bank-picker-list">
                    {questions.map((q) => {
                        const already = inExam.has(q.id);
                        return (
                            <li key={q.id} className={already ? 'in-exam' : ''}>
                                <label>
                                    <input
                                        type={replaceMode ? 'radio' : 'checkbox'}
                                        name={replaceMode ? 'bank-pick' : undefined}
                                        checked={selected.has(q.id)}
                                        disabled={already}
                                        onChange={() => toggle(q.id)}
                                    />
                                    <div>
                                        <div className="bank-picker-meta">
                                            <span className="question-type-pill">{TYPE_SHORT[q.type]}</span>
                                            <span className="language-pill">{q.language}</span>
                                            <span style={{ color: 'var(--muted)' }}>
                                                {q.subject} · {q.topic}
                                                {q.subtopic ? ` · ${q.subtopic}` : ''}
                                            </span>
                                            <span className={`difficulty-pill ${q.difficulty}`}>{DIFFICULTY_LABELS[q.difficulty]}</span>
                                            <span style={{ color: 'var(--muted)' }}>
                                                {q.points} pt{q.points === 1 ? '' : 's'}
                                            </span>
                                            {already ? (
                                                <span className="in-exam-badge">
                                                    <Check size={11} aria-hidden /> In exam
                                                </span>
                                            ) : null}
                                        </div>
                                        <MarkdownContent text={q.prompt} className="bank-picker-prompt" />
                                        {q.mediaUrl && q.mediaType === 'image' ? (
                                            <img src={q.mediaUrl} alt="" className="bank-media-thumb" />
                                        ) : q.mediaUrl && q.mediaType === 'audio' ? (
                                            <audio src={q.mediaUrl} controls className="bank-media-audio" />
                                        ) : q.mediaUrl && q.mediaType === 'video' ? (
                                            <video src={q.mediaUrl} controls className="bank-media-video" />
                                        ) : null}
                                    </div>
                                </label>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="question-form-actions">
                <button className="primary-button" type="button" onClick={onAddSelected} disabled={busy || selected.size === 0}>
                    <Plus size={17} aria-hidden />
                    {busy
                        ? replaceMode
                            ? 'Replacing…'
                            : 'Adding…'
                        : replaceMode
                          ? 'Replace with this'
                          : `Add ${selected.size} selected`}
                </button>
                <button
                    className="ghost-button"
                    type="button"
                    onClick={() => {
                        if (replaceMode) {
                            setSelected(new Set());
                            onCancelReplace?.();
                        } else {
                            setOpen(false);
                            setSelected(new Set());
                        }
                    }}
                >
                    Cancel
                </button>
            </div>
        </div>
    );
}
