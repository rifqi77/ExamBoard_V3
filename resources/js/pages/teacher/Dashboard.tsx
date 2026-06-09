import { Link, usePage, Head } from '@inertiajs/react';
import { Activity, BookOpenCheck, CheckCircle2, CircleX, Clock, PenLine, Users } from 'lucide-react';
import TeacherShell from '@/components/TeacherShell';

export default function Dashboard() {
    const { auth, metrics, recentSubmissions } = usePage().props as any;

    return (
        <TeacherShell>
            <Head title="Teacher · Overview" />
            <header className="teacher-page-header">
                <div>
                    <h1>Overview</h1>
                    <p>Welcome back, {auth.user?.fullName}.</p>
                </div>
            </header>

            <section className="admin-metrics admin-metrics--5col">
                <div className="admin-panel metric-card">
                    <BookOpenCheck size={20} aria-hidden />
                    <span>Exams</span>
                    <strong>{metrics.exams}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <Activity size={20} aria-hidden />
                    <span>Total submissions</span>
                    <strong>{metrics.totalSubmissions}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <CheckCircle2 size={20} aria-hidden />
                    <span>Passed</span>
                    <strong>{metrics.totalPassed}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <PenLine size={20} aria-hidden />
                    <span>Awaiting grading</span>
                    <strong>{metrics.pendingGrading}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <Users size={20} aria-hidden />
                    <span>Students</span>
                    <strong>{metrics.students}</strong>
                </div>
            </section>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Recent submissions</h2>
                        <p>Latest 10 across all exams. Click View to open the full review.</p>
                    </div>
                </div>

                {recentSubmissions.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No submissions yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Exam</th>
                                <th>Score</th>
                                <th>Result</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {recentSubmissions.map((s: any) => (
                                <tr key={s.id}>
                                    <td>
                                        <strong>{s.studentName}</strong>
                                        <div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{s.username}</div>
                                    </td>
                                    <td>{s.examName}</td>
                                    <td>
                                        {s.finalScore} / {s.possibleScore}
                                        <span style={{ color: 'var(--muted)' }}> ({s.percentScore}%)</span>
                                    </td>
                                    <td>
                                        {s.gradingStatus === 'pending_grading' ? (
                                            <span className="status-item warning">
                                                <Clock size={14} aria-hidden /> Pending ({s.pendingEssayCount})
                                            </span>
                                        ) : s.passed ? (
                                            <span className="status-item neutral">
                                                <CheckCircle2 size={14} aria-hidden /> Passed
                                            </span>
                                        ) : (
                                            <span className="status-item warning">
                                                <CircleX size={14} aria-hidden /> Not passed
                                            </span>
                                        )}
                                    </td>
                                    <td>{new Date(s.submittedAt).toLocaleString()}</td>
                                    <td>
                                        <Link className="ghost-button" href={`/teacher/scores/${s.id}`}>
                                            {s.gradingStatus === 'pending_grading' ? 'Grade' : 'View'}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </TeacherShell>
    );
}
