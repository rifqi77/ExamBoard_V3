import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, CheckCircle2, CircleX, Clock, PenLine, Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import AdminShell from '@/components/AdminShell';
import MarkdownContent from '@/components/MarkdownContent';
import MediaRenderer, { QMedia } from '@/components/MediaRenderer';

// ---------------------------------------------------------------------------
// Admin Score detail / grading — school-wide port of the teacher Grade page.
// Same Scoring engine, same per-essay manual scoring + feedback, but wrapped
// in AdminShell, posts to /admin/scores/{id}/grade, and shows which teacher
// owns the exam. Built from Admin\ScoresController::show.
// ---------------------------------------------------------------------------

type QuestionType = 'single_choice' | 'multi_select' | 'short_text' | 'numeric' | 'essay';

const TYPE_LABELS: Record<QuestionType, string> = {
    single_choice: 'Single choice',
    multi_select: 'Multi select',
    short_text: 'Short text',
    numeric: 'Numeric',
    essay: 'Essay',
};

type Item = {
    question: {
        id: string;
        position: number;
        type: QuestionType;
        topic: string;
        tags: string[];
        prompt: string;
        options: { id: string; text: string }[] | null;
        points: number;
    };
    media: QMedia[];
    studentAnswer: unknown;
    correctAnswer: unknown;
    explanationText: string;
    feedback: string;
    isAutoGraded: boolean;
    isCorrect: boolean;
    awarded: number;
    possible: number;
    requiresGrading: boolean;
};

type AntiCheatEvent = { kind: string; label: string; at: string | null };

type TopicRow = { topic: string; earned: number; possible: number; percent: number; correct: number; total: number };

type Detail = {
    id: string;
    examDatabaseId: string;
    examId: string;
    examName: string;
    examSubject: string;
    teacherName: string;
    passingGrade: number;
    studentName: string;
    username: string;
    submittedAt: string | null;
    finalScore: number;
    possibleScore: number;
    percentScore: number;
    passed: boolean;
    pendingEssayCount: number;
    gradingStatus: 'pending_grading' | 'graded';
    topicBreakdown: TopicRow[];
    items: Item[];
    antiCheatEvents: AntiCheatEvent[];
};

function formatAnswerForDisplay(answer: unknown, type: QuestionType): string {
    if (answer === null || answer === undefined) return '— (no answer)';
    if (type === 'essay') {
        const text = String(answer).trim();
        return text.length === 0 ? '— (no answer)' : text;
    }
    if (Array.isArray(answer)) return answer.join(', ');
    return String(answer);
}

export default function ScoreDetail() {
    const { submission: detail, flash } = usePage().props as unknown as {
        submission: Detail;
        flash?: { success?: string | null; error?: string | null };
    };

    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [feedbackDrafts, setFeedbackDrafts] = useState<Record<string, string>>({});
    const [savingId, setSavingId] = useState<string | null>(null);
    const [savedFor, setSavedFor] = useState<Record<string, boolean>>({});
    const [localError, setLocalError] = useState('');

    useEffect(() => {
        const initial: Record<string, string> = {};
        const fb: Record<string, string> = {};
        for (const item of detail.items) {
            if (!item.isAutoGraded) {
                initial[item.question.id] = item.requiresGrading ? '' : String(item.awarded);
                fb[item.question.id] = item.feedback ?? '';
            }
        }
        setDrafts(initial);
        setFeedbackDrafts(fb);
    }, [detail]);

    function onSaveEssay(item: Item) {
        const raw = drafts[item.question.id] ?? '';
        setLocalError('');
        const trimmed = raw.trim();
        const parsed = trimmed === '' ? null : Number(trimmed);
        if (parsed !== null && !Number.isFinite(parsed)) {
            setLocalError('Enter a number, or clear the field to unscore.');
            return;
        }
        if (parsed !== null && parsed < 0) {
            setLocalError('Score must be 0 or greater.');
            return;
        }
        if (parsed !== null && parsed > item.possible) {
            setLocalError(`Score cannot exceed the question's max of ${item.possible} point(s).`);
            return;
        }
        setSavingId(item.question.id);
        router.post(
            `/admin/scores/${detail.id}/grade`,
            { questionId: item.question.id, score: parsed, feedback: feedbackDrafts[item.question.id] ?? '' },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSavedFor((cur) => ({ ...cur, [item.question.id]: true }));
                    setTimeout(
                        () =>
                            setSavedFor((cur) => {
                                const next = { ...cur };
                                delete next[item.question.id];
                                return next;
                            }),
                        1500,
                    );
                },
                onFinish: () => setSavingId(null),
            },
        );
    }

    return (
        <AdminShell>
            <Head title={`Grade · ${detail.studentName}`} />
            <header className="teacher-page-header">
                <div>
                    <h1>Grade submission</h1>
                    <p>
                        <strong>{detail.studentName}</strong>{' '}
                        <span style={{ color: 'var(--muted)' }}>({detail.username})</span> · {detail.examName}
                        {detail.teacherName ? <span style={{ color: 'var(--muted)' }}> · {detail.teacherName}</span> : null}
                    </p>
                </div>
                <Link className="ghost-button" href="/admin/scores">
                    <ArrowLeft size={17} aria-hidden /> Back to scores
                </Link>
            </header>

            {flash?.error ? <p className="form-error">{flash.error}</p> : null}
            {localError ? <p className="form-error">{localError}</p> : null}
            {flash?.success ? <p className="form-success">{flash.success}</p> : null}

            <section className="admin-metrics">
                <div className="admin-panel metric-card">
                    <span>Score so far</span>
                    <strong>
                        {detail.finalScore}
                        <span style={{ color: 'var(--muted)', fontWeight: 500 }}> / {detail.possibleScore}</span>
                    </strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Percent</span>
                    <strong>{detail.percentScore}%</strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Status</span>
                    <strong>
                        {detail.gradingStatus === 'pending_grading' ? (
                            <span className="status-item warning">
                                <Clock size={14} aria-hidden /> Pending grading
                            </span>
                        ) : detail.passed ? (
                            <span className="status-item neutral">
                                <CheckCircle2 size={14} aria-hidden /> Passed
                            </span>
                        ) : (
                            <span className="status-item warning">
                                <CircleX size={14} aria-hidden /> Not passed
                            </span>
                        )}
                    </strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Pending essays</span>
                    <strong>{detail.pendingEssayCount}</strong>
                </div>
            </section>

            {detail.antiCheatEvents.length > 0 ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Proctoring events</h2>
                            <p>
                                Captured during the exam. Use to flag suspicious behaviour for follow-up — these do not change
                                the auto-grade.
                            </p>
                        </div>
                    </div>
                    <ProctoringEventsPanel events={detail.antiCheatEvents} />
                </section>
            ) : null}

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Topic breakdown</h2>
                        <p>Earned vs possible per topic. Updates as you grade essays.</p>
                    </div>
                </div>
                {detail.topicBreakdown.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No topics scored yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Topic</th>
                                <th>Correct</th>
                                <th>Earned</th>
                                <th>Percent</th>
                            </tr>
                        </thead>
                        <tbody>
                            {detail.topicBreakdown.map((row) => (
                                <tr key={row.topic}>
                                    <td>
                                        <strong>{row.topic}</strong>
                                    </td>
                                    <td>
                                        {row.correct} / {row.total}
                                    </td>
                                    <td>
                                        {row.earned} / {row.possible}
                                    </td>
                                    <td>{row.percent}%</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Answers</h2>
                        <p>Auto-graded answers are compared against the key. Essay answers need a manual score.</p>
                    </div>
                </div>

                <ol className="grading-list">
                    {detail.items.map((item) => (
                        <li key={item.question.id} className="grading-item">
                            <div className="grading-item-head">
                                <span className="question-type-pill">{TYPE_LABELS[item.question.type]}</span>
                                <span style={{ color: 'var(--muted)' }}>{item.question.topic}</span>
                                <span style={{ color: 'var(--muted)' }}>
                                    {item.possible} pt{item.possible === 1 ? '' : 's'}
                                </span>
                                {item.isAutoGraded ? (
                                    item.isCorrect ? (
                                        <span className="status-item neutral">
                                            <CheckCircle2 size={13} aria-hidden /> Correct
                                        </span>
                                    ) : (
                                        <span className="status-item warning">
                                            <CircleX size={13} aria-hidden /> Incorrect
                                        </span>
                                    )
                                ) : item.requiresGrading ? (
                                    <span className="status-item warning">
                                        <PenLine size={13} aria-hidden /> Needs grading
                                    </span>
                                ) : (
                                    <span className="status-item neutral">
                                        <Check size={13} aria-hidden /> Graded
                                    </span>
                                )}
                            </div>

                            <div className="grading-item-prompt">
                                <strong>{item.question.position}.</strong>{' '}
                                <MarkdownContent text={item.question.prompt} className="grading-item-prompt-md" />
                            </div>

                            {item.media.length > 0 ? (
                                <div className="grading-item-media">
                                    {item.media.map((m) => (
                                        <MediaRenderer key={m.id} media={m} />
                                    ))}
                                </div>
                            ) : null}

                            <div className="grading-item-answer-block">
                                <div>
                                    <span className="grading-label">Student answer</span>
                                    <div className="grading-answer student">
                                        {item.question.type === 'essay' ? (
                                            typeof item.studentAnswer === 'string' && item.studentAnswer.trim().length > 0 ? (
                                                <MarkdownContent text={item.studentAnswer} />
                                            ) : (
                                                '— (no answer)'
                                            )
                                        ) : (
                                            formatAnswerForDisplay(item.studentAnswer, item.question.type)
                                        )}
                                    </div>
                                </div>
                                {item.isAutoGraded ? (
                                    <div>
                                        <span className="grading-label">Correct answer</span>
                                        <div className="grading-answer correct">
                                            {formatAnswerForDisplay(item.correctAnswer, item.question.type)}
                                        </div>
                                    </div>
                                ) : null}
                            </div>

                            {item.question.type === 'essay' && item.explanationText.trim().length > 0 ? (
                                <div className="grading-mark-scheme">
                                    <span className="grading-label">Mark scheme</span>
                                    <MarkdownContent text={item.explanationText} className="grading-mark-scheme-md" />
                                </div>
                            ) : null}
                            {item.isAutoGraded && item.explanationText.trim().length > 0 ? (
                                <div className="grading-mark-scheme">
                                    <span className="grading-label">Explanation</span>
                                    <MarkdownContent text={item.explanationText} className="grading-mark-scheme-md" />
                                </div>
                            ) : null}

                            {!item.isAutoGraded ? (
                                <div className="grading-essay-row">
                                    <label>
                                        Score (0 – {item.possible})
                                        <input
                                            type="number"
                                            min={0}
                                            max={item.possible}
                                            step={0.5}
                                            value={drafts[item.question.id] ?? ''}
                                            onChange={(e) =>
                                                setDrafts((cur) => ({ ...cur, [item.question.id]: e.target.value }))
                                            }
                                            placeholder="—"
                                        />
                                    </label>
                                    <label style={{ flex: 1, minWidth: 200 }}>
                                        Feedback (optional)
                                        <input
                                            type="text"
                                            value={feedbackDrafts[item.question.id] ?? ''}
                                            onChange={(e) =>
                                                setFeedbackDrafts((cur) => ({ ...cur, [item.question.id]: e.target.value }))
                                            }
                                            placeholder="One-line note for this answer"
                                        />
                                    </label>
                                    <button
                                        className="primary-button"
                                        type="button"
                                        disabled={savingId === item.question.id}
                                        onClick={() => onSaveEssay(item)}
                                    >
                                        <Save size={15} aria-hidden />
                                        {savingId === item.question.id ? 'Saving…' : 'Save score'}
                                    </button>
                                    {savedFor[item.question.id] ? (
                                        <span className="form-success" style={{ margin: 0 }}>
                                            Saved
                                        </span>
                                    ) : null}
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ol>
            </section>
        </AdminShell>
    );
}

function ProctoringEventsPanel({ events }: { events: AntiCheatEvent[] }) {
    const counts = new Map<string, { label: string; n: number }>();
    for (const ev of events) {
        const cur = counts.get(ev.kind);
        if (cur) cur.n += 1;
        else counts.set(ev.kind, { label: ev.label, n: 1 });
    }
    const sortedSummary = Array.from(counts.values()).sort((a, b) => b.n - a.n);
    return (
        <>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
                {sortedSummary.map((s) => (
                    <span key={s.label} className="status-item warning">
                        {s.label} × {s.n}
                    </span>
                ))}
            </div>
            <table className="dashboard-table" style={{ marginTop: 12 }}>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    {events.slice(0, 50).map((ev, idx) => (
                        <tr key={`${ev.at}-${idx}`}>
                            <td>{ev.label}</td>
                            <td>{ev.at ? new Date(ev.at).toLocaleTimeString() : '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {events.length > 50 ? (
                <p style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>Showing first 50 of {events.length} events.</p>
            ) : null}
        </>
    );
}
