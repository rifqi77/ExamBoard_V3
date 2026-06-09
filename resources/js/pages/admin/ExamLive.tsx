import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AdminShell from '@/components/AdminShell';

// Admin Live monitor — faithful port of LiveMonitorClient (basePath=/admin).
// Polls /admin/exams/{id}/live-scores every 7s and scores each student's
// CURRENT answers on the fly (nothing is stored, nothing shown to students):
// the non-essay score updates as they answer; essays show pending until
// graded. Auto-refresh toggle + manual refresh + "updated Ns ago".

type Student = {
    userId: string;
    username: string;
    fullName: string;
    status: string; // draft | submitted | expired
    answeredCount: number;
    totalQuestions: number;
    autoEarned: number;
    autoPossible: number;
    autoPct: number;
    essayPossible: number;
    essayPending: number;
    timeRemainingSeconds: number;
    lastSavedAt: string | null;
    antiCheatEventCount: number;
};

type LiveResponse = {
    exam: { id: string; examCode: string; name: string; passingGrade: number; totalQuestions: number };
    students: Student[];
    totals: { students: number; inProgress: number; submitted: number; avgAutoPct: number };
};

const POLL_MS = 7000;

function mmss(total: number): string {
    if (total <= 0) return '0:00';
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function StatusBadge({ status }: { status: string }) {
    if (status === 'submitted') return <span className="status-item neutral">Submitted</span>;
    if (status === 'expired') return <span className="status-item warning">Expired</span>;
    return (
        <span className="status-item warning" style={{ color: '#2563eb' }}>
            ● In progress
        </span>
    );
}

function Stat({ label, value, accent }: { label: string; value: number | string; accent?: string }) {
    return (
        <div
            style={{
                background: 'var(--surface, #fff)',
                border: '1px solid var(--border, #e6e8ec)',
                borderRadius: 10,
                padding: '12px 14px',
            }}
        >
            <div
                style={{
                    fontSize: '0.74rem',
                    color: 'var(--muted)',
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.03em',
                }}
            >
                {label}
            </div>
            <div style={{ fontSize: '1.6rem', fontWeight: 800, color: accent ?? 'inherit' }}>{value}</div>
        </div>
    );
}

export default function AdminExamLive() {
    const { examId } = usePage().props as unknown as { examId: string; examName: string; notFound: boolean };

    const [data, setData] = useState<LiveResponse | null>(null);
    const [error, setError] = useState('');
    const [auto, setAuto] = useState(true);
    const [updatedAt, setUpdatedAt] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`/admin/exams/${encodeURIComponent(examId)}/live-scores`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.error || `HTTP ${res.status}`);
            }
            setData((await res.json()) as LiveResponse);
            setError('');
            setUpdatedAt(Date.now());
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to load live scores.');
        } finally {
            setLoading(false);
        }
    }, [examId]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        if (!auto) {
            if (timer.current) clearInterval(timer.current);
            return;
        }
        timer.current = setInterval(() => void load(), POLL_MS);
        return () => {
            if (timer.current) clearInterval(timer.current);
        };
    }, [auto, load]);

    return (
        <AdminShell>
            <Head title="Admin · Live monitor" />
            {error && !data ? (
                <div className="admin-panel" style={{ padding: 24 }}>
                    <p style={{ color: 'var(--danger)' }}>Error: {error}</p>
                    <Link href={`/admin/exams/${examId}`} className="ghost-button">
                        ← Back to exam
                    </Link>
                </div>
            ) : !data ? (
                <div className="admin-panel" style={{ padding: 24 }}>
                    Loading live monitor…
                </div>
            ) : (
                <div style={{ padding: 16 }}>
                    <div
                        style={{
                            marginBottom: 16,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 12,
                            flexWrap: 'wrap',
                        }}
                    >
                        <Link href={`/admin/exams/${examId}`} className="ghost-button">
                            ← Back to exam
                        </Link>
                        <h1 style={{ margin: 0, fontSize: '1.4rem' }}>
                            Live monitor: {data.exam.examCode} — {data.exam.name}
                        </h1>
                        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 10 }}>
                            <span style={{ fontSize: '0.8rem', color: 'var(--muted)' }}>
                                {loading
                                    ? 'refreshing…'
                                    : updatedAt
                                      ? `updated ${Math.round((Date.now() - updatedAt) / 1000)}s ago`
                                      : ''}
                            </span>
                            <label style={{ fontSize: '0.85rem', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                                <input type="checkbox" checked={auto} onChange={(e) => setAuto(e.target.checked)} />
                                Auto-refresh
                            </label>
                            <button type="button" className="ghost-button" onClick={() => void load()}>
                                Refresh
                            </button>
                        </div>
                    </div>

                    <p style={{ fontSize: '0.82rem', color: 'var(--muted)', margin: '0 0 14px' }}>
                        Scores are computed live from each student&apos;s current answers — nothing is stored or shown to
                        students. The non-essay score updates as they answer; essays show as pending until you grade them.
                    </p>

                    <div className="admin-panel" style={{ padding: 16, marginBottom: 16 }}>
                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))',
                                gap: 12,
                            }}
                        >
                            <Stat label="Students" value={data.totals.students} />
                            <Stat label="In progress" value={data.totals.inProgress} accent="#2563eb" />
                            <Stat label="Submitted" value={data.totals.submitted} />
                            <Stat label="Avg non-essay %" value={`${data.totals.avgAutoPct}%`} />
                        </div>
                    </div>

                    <div className="admin-panel" style={{ padding: 0, overflowX: 'auto' }}>
                        <table className="dashboard-table" style={{ width: '100%' }}>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Status</th>
                                    <th>Answered</th>
                                    <th>Non-essay score</th>
                                    <th>Essays</th>
                                    <th>Time left</th>
                                    <th>Flags</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.students.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} style={{ padding: 20, color: 'var(--muted)' }}>
                                            No students have opened this exam yet.
                                        </td>
                                    </tr>
                                ) : (
                                    data.students.map((s) => (
                                        <tr key={s.userId}>
                                            <td>
                                                <strong>{s.fullName}</strong>
                                                <br />
                                                <code style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                                    {s.username}
                                                </code>
                                            </td>
                                            <td>
                                                <StatusBadge status={s.status} />
                                            </td>
                                            <td>
                                                {s.answeredCount} / {s.totalQuestions}
                                            </td>
                                            <td>
                                                <strong>{s.autoPossible === 0 ? '—' : `${s.autoPct}%`}</strong>
                                                <br />
                                                <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                                    {s.autoEarned}/{s.autoPossible} pts
                                                </span>
                                            </td>
                                            <td>
                                                {s.essayPossible === 0 ? (
                                                    <span style={{ color: 'var(--muted)' }}>—</span>
                                                ) : s.essayPending > 0 ? (
                                                    <span style={{ color: '#d97706' }}>{s.essayPending} pending</span>
                                                ) : (
                                                    <span className="status-item neutral">graded</span>
                                                )}
                                            </td>
                                            <td>{s.status === 'submitted' ? '—' : mmss(s.timeRemainingSeconds)}</td>
                                            <td>
                                                {s.antiCheatEventCount > 0 ? (
                                                    <span style={{ color: '#d97706' }} title="anti-cheat events">
                                                        ⚠ {s.antiCheatEventCount}
                                                    </span>
                                                ) : (
                                                    <span style={{ color: 'var(--muted)' }}>—</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AdminShell>
    );
}
