import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AdminShell from '@/components/AdminShell';

// Admin Answer audit — faithful port of AnswerAuditClient (basePath=/admin).
// Diagnostic integrity table: for every session it lines up the raw
// answer_drafts (auto-saved input) against the submission answersSnapshot
// (what scoring read) per question. A "mismatch" means an answer was lost
// or is phantom — the only red flag. Click a row for per-question
// side-by-side; filter by all / with-drafts / with-mismatch.

type PerQuestion = {
    questionId: string;
    position: number;
    type: string;
    points: number;
    topic: string;
    hasDraft: boolean;
    hasSnap: boolean;
    draftValue: unknown;
    snapshotValue: unknown;
    match: boolean;
};

type StudentRow = {
    sessionId: string;
    attempt: number;
    status: string;
    startedAt: string;
    lastSavedAt: string | null;
    submittedAt: string | null;
    username: string;
    fullName: string;
    submissionId: string | null;
    finalScore: number | null;
    possibleScore: number | null;
    percentScore: number | null;
    pendingEssayCount: number | null;
    draftCount: number;
    snapCount: number;
    mismatchCount: number;
    perQuestion: PerQuestion[];
};

type AuditResponse = {
    exam: { id: string; examCode: string; name: string; shuffleQuestions: boolean; shuffleOptions: boolean };
    questions: Array<{ id: string; position: number; type: string; points: number; topic: string }>;
    students: StudentRow[];
    totals: { sessions: number; students: number; totalDrafts: number; totalSnap: number; totalMismatch: number };
};

function formatValue(v: unknown): string {
    if (v === null || v === undefined) return '—';
    if (typeof v === 'string') {
        const trimmed = v.trim();
        if (trimmed.length === 0) return '""';
        if (trimmed.length > 80) return `"${trimmed.substring(0, 77)}…" (${trimmed.length} chars)`;
        return `"${trimmed}"`;
    }
    if (Array.isArray(v)) {
        if (v.length === 0) return '[ ] (empty)';
        return JSON.stringify(v);
    }
    return JSON.stringify(v);
}

function Stat({ label, value, danger }: { label: string; value: number; danger?: boolean }) {
    return (
        <div>
            <div style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>{label}</div>
            <strong style={{ fontSize: '1.3rem', color: danger ? 'var(--danger)' : 'var(--foreground)' }}>{value}</strong>
        </div>
    );
}

function DetailGrid({ student }: { student: StudentRow }) {
    return (
        <div style={{ padding: 12 }}>
            <p style={{ marginTop: 0, fontSize: '0.85rem', color: 'var(--muted)' }}>
                Session {student.sessionId.substring(0, 8)} · attempt {student.attempt} · {student.draftCount} raw drafts,{' '}
                {student.snapCount} snapshot keys
            </p>
            <table className="data-table" style={{ fontSize: '0.85rem' }}>
                <thead>
                    <tr>
                        <th style={{ width: 50 }}>Q#</th>
                        <th style={{ width: 110 }}>Type</th>
                        <th>Topic</th>
                        <th>Raw draft (DB)</th>
                        <th>Grading snapshot</th>
                        <th style={{ width: 70 }}>Match</th>
                    </tr>
                </thead>
                <tbody>
                    {student.perQuestion.map((q) => (
                        <tr
                            key={q.questionId}
                            style={{
                                background: !q.match
                                    ? 'rgba(220, 38, 38, 0.1)'
                                    : q.hasDraft || q.hasSnap
                                      ? undefined
                                      : 'rgba(120, 120, 120, 0.05)',
                            }}
                        >
                            <td>Q{String(q.position).padStart(2, '0')}</td>
                            <td>{q.type}</td>
                            <td style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>{q.topic.substring(0, 30)}</td>
                            <td>
                                <code style={{ fontSize: '0.78rem' }}>{formatValue(q.draftValue)}</code>
                            </td>
                            <td>
                                <code style={{ fontSize: '0.78rem' }}>{formatValue(q.snapshotValue)}</code>
                            </td>
                            <td>
                                {q.match ? (
                                    <span style={{ color: 'var(--success, #16a34a)' }}>✓</span>
                                ) : (
                                    <strong style={{ color: 'var(--danger)' }}>✗ LOST</strong>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function FragmentRow({ s, expanded, onToggle }: { s: StudentRow; expanded: boolean; onToggle: () => void }) {
    return (
        <>
            <tr
                onClick={onToggle}
                style={{ cursor: 'pointer', background: s.mismatchCount > 0 ? 'rgba(220, 38, 38, 0.08)' : undefined }}
            >
                <td>
                    <strong>{s.fullName}</strong>{' '}
                    <code style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>{s.username}</code>
                </td>
                <td>{s.attempt}</td>
                <td>
                    <span style={{ fontSize: '0.85rem' }}>
                        {s.status}
                        {s.submittedAt ? '' : ' (no submission)'}
                    </span>
                </td>
                <td>{s.draftCount}</td>
                <td>{s.snapCount}</td>
                <td>
                    {s.mismatchCount > 0 ? (
                        <strong style={{ color: 'var(--danger)' }}>{s.mismatchCount} ⚠</strong>
                    ) : (
                        <span style={{ color: 'var(--success, #16a34a)' }}>0 ✓</span>
                    )}
                </td>
                <td>
                    {s.finalScore !== null && s.possibleScore !== null
                        ? `${s.finalScore}/${s.possibleScore}`
                        : '—'}
                </td>
                <td style={{ fontSize: '0.8rem', color: 'var(--muted)' }}>
                    {s.lastSavedAt ? new Date(s.lastSavedAt).toLocaleString() : '—'}
                </td>
            </tr>
            {expanded ? (
                <tr>
                    <td colSpan={8} style={{ background: 'rgba(0,0,0,0.02)' }}>
                        <DetailGrid student={s} />
                    </td>
                </tr>
            ) : null}
        </>
    );
}

export default function AdminExamAudit() {
    const { examId } = usePage().props as unknown as { examId: string; examName: string; notFound: boolean };

    const [data, setData] = useState<AuditResponse | null>(null);
    const [error, setError] = useState('');
    const [expanded, setExpanded] = useState<string | null>(null);
    const [filter, setFilter] = useState<'all' | 'mismatch' | 'with-drafts'>('all');

    useEffect(() => {
        fetch(`/admin/exams/${encodeURIComponent(examId)}/answer-audit`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : r.json().then((j) => Promise.reject(j))))
            .then((j: AuditResponse) => setData(j))
            .catch((e) => setError(e?.error ?? 'Failed to load audit data.'));
    }, [examId]);

    const filtered = useMemo(() => {
        if (!data) return [];
        if (filter === 'mismatch') return data.students.filter((s) => s.mismatchCount > 0);
        if (filter === 'with-drafts') return data.students.filter((s) => s.draftCount > 0);
        return data.students;
    }, [data, filter]);

    return (
        <AdminShell>
            <Head title="Admin · Answer audit" />
            {error ? (
                <div className="admin-panel" style={{ padding: 24 }}>
                    <p style={{ color: 'var(--danger)' }}>Error: {error}</p>
                    <Link href={`/admin/exams/${examId}`} className="ghost-button">
                        ← Back to exam
                    </Link>
                </div>
            ) : !data ? (
                <div className="admin-panel" style={{ padding: 24 }}>
                    Loading audit…
                </div>
            ) : (
                <div style={{ padding: 16 }}>
                    <div style={{ marginBottom: 16, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                        <Link href={`/admin/exams/${examId}`} className="ghost-button">
                            ← Back to exam
                        </Link>
                        <h1 style={{ margin: 0, fontSize: '1.4rem' }}>
                            Answer audit: {data.exam.examCode} — {data.exam.name}
                        </h1>
                    </div>

                    <div className="admin-panel" style={{ padding: 16, marginBottom: 16 }}>
                        <strong>Integrity check</strong>
                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
                                gap: 12,
                                marginTop: 8,
                            }}
                        >
                            <Stat label="Sessions" value={data.totals.sessions} />
                            <Stat label="Students" value={data.totals.students} />
                            <Stat label="Total drafts (DB)" value={data.totals.totalDrafts} />
                            <Stat label="Total in snapshots" value={data.totals.totalSnap} />
                            <Stat label="Mismatches" value={data.totals.totalMismatch} danger={data.totals.totalMismatch > 0} />
                        </div>
                        <p style={{ marginTop: 12, fontSize: '0.85rem', color: 'var(--muted)' }}>
                            A &quot;mismatch&quot; means: a question&apos;s value in the auto-saved <code>answer_drafts</code>{' '}
                            table does NOT equal what got stored in the submission&apos;s <code>answersSnapshot</code>. If this
                            is <strong>0</strong>, no answer was lost in restoration. Click any row to see per-question
                            side-by-side comparison.
                        </p>
                    </div>

                    <div style={{ marginBottom: 12, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button
                            type="button"
                            className={filter === 'all' ? 'primary-button' : 'ghost-button'}
                            onClick={() => setFilter('all')}
                        >
                            All ({data.students.length})
                        </button>
                        <button
                            type="button"
                            className={filter === 'with-drafts' ? 'primary-button' : 'ghost-button'}
                            onClick={() => setFilter('with-drafts')}
                        >
                            With drafts ({data.students.filter((s) => s.draftCount > 0).length})
                        </button>
                        <button
                            type="button"
                            className={filter === 'mismatch' ? 'primary-button' : 'ghost-button'}
                            onClick={() => setFilter('mismatch')}
                        >
                            With mismatch ({data.students.filter((s) => s.mismatchCount > 0).length})
                        </button>
                    </div>

                    <div className="admin-panel" style={{ padding: 0, overflowX: 'auto' }}>
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Att</th>
                                    <th>Session status</th>
                                    <th>Drafts (DB)</th>
                                    <th>Snap (sub)</th>
                                    <th>Mismatch</th>
                                    <th>Score</th>
                                    <th>Last saved</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((s) => (
                                    <FragmentRow
                                        key={s.sessionId}
                                        s={s}
                                        expanded={expanded === s.sessionId}
                                        onToggle={() => setExpanded(expanded === s.sessionId ? null : s.sessionId)}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AdminShell>
    );
}
