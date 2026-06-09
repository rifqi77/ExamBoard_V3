import { Link, usePage, router, Head } from '@inertiajs/react';
import { ArrowLeft, BarChart3, CheckCircle2, CircleX, Clock, Eye, LogOut } from 'lucide-react';

function formatDate(value: string | null): string {
    if (!value) return '—';
    const ms = new Date(value).getTime();
    if (Number.isNaN(ms) || ms < 86_400_000) return '—';
    return new Date(value).toLocaleString();
}

export default function Scores() {
    const { submissions } = usePage().props as any;

    return (
        <main className="student-scores-shell">
            <Head title="My scores" />
            <header className="student-scores-header">
                <div className="brand-lockup compact">
                    <div className="brand-mark">
                        <BarChart3 size={20} aria-hidden />
                    </div>
                    <div>
                        <h1>My scores</h1>
                        <p>Exams you&apos;ve already taken, newest first.</p>
                    </div>
                </div>
                <div className="student-scores-actions">
                    <Link className="ghost-button" href="/student">
                        <ArrowLeft size={16} aria-hidden /> Back
                    </Link>
                    <button type="button" className="ghost-button" onClick={() => router.post('/logout')}>
                        <LogOut size={16} aria-hidden /> Sign out
                    </button>
                </div>
            </header>

            <section className="admin-panel">
                {submissions.length === 0 ? (
                    <div style={{ textAlign: 'center', padding: '30px 20px' }}>
                        <p style={{ color: 'var(--muted)', marginBottom: 14 }}>You haven&apos;t submitted any exams yet.</p>
                        <Link className="primary-button" href="/token">
                            Take an exam
                        </Link>
                    </div>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Exam</th>
                                <th>Started</th>
                                <th>Submitted</th>
                                <th style={{ width: 130 }}>Score</th>
                                <th style={{ width: 140 }}>Status</th>
                                <th style={{ width: 120 }}>Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            {submissions.map((s: any) => {
                                const normalised = Number(((s.finalScore / Math.max(1, s.possibleScore)) * 100).toFixed(2));
                                return (
                                    <tr key={s.id}>
                                        <td>
                                            <strong>{s.examName}</strong>
                                            <br />
                                            <code style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>{s.examId}</code>
                                        </td>
                                        <td>{formatDate(s.startedAt)}</td>
                                        <td>{formatDate(s.submittedAt)}</td>
                                        <td>
                                            <strong>{normalised}</strong>
                                            <span style={{ color: 'var(--muted)' }}> / 100</span>
                                            <br />
                                            <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                                Raw {s.finalScore}/{s.possibleScore}
                                            </span>
                                        </td>
                                        <td>
                                            {s.gradingStatus === 'pending_grading' ? (
                                                <span className="status-item warning">
                                                    <Clock size={13} aria-hidden /> Pending ({s.pendingEssayCount} essay
                                                    {s.pendingEssayCount === 1 ? '' : 's'})
                                                </span>
                                            ) : s.passed ? (
                                                <span className="status-item neutral">
                                                    <CheckCircle2 size={13} aria-hidden /> Passed
                                                </span>
                                            ) : (
                                                <span className="status-item warning">
                                                    <CircleX size={13} aria-hidden /> Not passed
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            <Link className="ghost-button" href={`/student/scores/${encodeURIComponent(s.id)}`}>
                                                <Eye size={14} aria-hidden /> Review
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </section>
        </main>
    );
}
