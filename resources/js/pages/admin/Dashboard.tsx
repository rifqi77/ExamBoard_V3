import { Head, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpenCheck,
    CheckCircle2,
    CircleX,
    Clock,
    FileSpreadsheet,
    KeyRound,
    Library,
    ShieldCheck,
    Ticket,
    Users,
    UsersRound,
} from 'lucide-react';
import { ComponentType } from 'react';
import AdminShell from '@/components/AdminShell';

// ---------------------------------------------------------------- types

type Metrics = {
    teacherCount: number;
    activeTeacherCount: number;
    studentCount: number;
    examCount: number;
    submissionCount: number;
    bankQuestionCount: number;
};

type RecentToken = {
    id: string;
    token: string | null;
    examId: string | null;
    examName: string | null;
    className: string | null;
    maxUses: number;
    usedCount: number;
    active: boolean;
    expiresAt: string | null;
    createdAt: string | null;
};

type RecentClass = {
    id: string;
    name: string;
    academicYear: string | null;
    studentCount: number;
    sourceFileName: string | null;
    createdAt: string | null;
};

type RecentSubmission = {
    id: string;
    studentName: string;
    username: string;
    examName: string;
    finalScore: number;
    possibleScore: number;
    percentScore: number;
    passed: boolean;
    pendingEssayCount: number;
    gradingStatus: 'pending_grading' | 'graded';
    submittedAt: string | null;
};

type MetricCard = {
    icon: ComponentType<{ size?: number; 'aria-hidden'?: boolean }>;
    title: string;
    value: number;
    subtitle: string;
};

type MetricGroup = { label: string; cards: MetricCard[] };

// ---------------------------------------------------------------- helpers

function buildGroups(s: Metrics): MetricGroup[] {
    const disabledTeachers = Math.max(0, s.teacherCount - s.activeTeacherCount);
    return [
        {
            label: 'People',
            cards: [
                {
                    icon: ShieldCheck,
                    title: 'Teachers',
                    value: s.teacherCount,
                    subtitle: 'registered teacher accounts',
                },
                {
                    icon: Activity,
                    title: 'Active teachers',
                    value: s.activeTeacherCount,
                    subtitle: disabledTeachers === 0 ? 'all teachers active' : `${disabledTeachers} disabled`,
                },
                {
                    icon: Users,
                    title: 'Students',
                    value: s.studentCount,
                    subtitle: 'registered learners',
                },
            ],
        },
        {
            label: 'Content',
            cards: [
                {
                    icon: BookOpenCheck,
                    title: 'Exams',
                    value: s.examCount,
                    subtitle: 'across every teacher',
                },
                {
                    icon: FileSpreadsheet,
                    title: 'Submissions',
                    value: s.submissionCount,
                    subtitle: 'completed attempts',
                },
                {
                    icon: Library,
                    title: 'Bank questions',
                    value: s.bankQuestionCount,
                    subtitle: 'reusable items',
                },
            ],
        },
    ];
}

function fmt(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

// ---------------------------------------------------------------- page

export default function Dashboard() {
    const { metrics, recent } = usePage().props as unknown as {
        metrics: Metrics;
        recent: { tokens: RecentToken[]; classes: RecentClass[]; submissions: RecentSubmission[] };
    };

    const groups = buildGroups(metrics);

    return (
        <AdminShell>
            <Head title="Admin · Overview" />

            <header className="teacher-page-header">
                <div>
                    <h1>Overview</h1>
                    <p>System totals across every teacher account, plus the latest activity school-wide.</p>
                </div>
            </header>

            {groups.map((group) => (
                <section className="metric-group" key={group.label}>
                    <h2 className="metric-group-label">{group.label}</h2>
                    <div className="admin-metrics admin-metrics--3col">
                        {group.cards.map((card) => {
                            const Icon = card.icon;
                            return (
                                <div className="admin-panel metric-card" key={card.title}>
                                    <Icon size={18} aria-hidden />
                                    <span>{card.title}</span>
                                    <strong>{card.value}</strong>
                                    <small className="metric-card-sub">{card.subtitle}</small>
                                </div>
                            );
                        })}
                    </div>
                </section>
            ))}

            {/* Recent submissions across every exam. */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>
                            <Activity size={18} aria-hidden /> Recent submissions
                        </h2>
                        <p>Latest 10 completed attempts, every teacher.</p>
                    </div>
                </div>
                {recent.submissions.length === 0 ? (
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
                            </tr>
                        </thead>
                        <tbody>
                            {recent.submissions.map((s) => (
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
                                    <td>{fmt(s.submittedAt)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <div className="admin-metrics admin-metrics--2col">
                {/* Recent access tokens. */}
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>
                                <Ticket size={18} aria-hidden /> Recent access tokens
                            </h2>
                            <p>Latest 10 issued, with usage and binding.</p>
                        </div>
                    </div>
                    {recent.tokens.length === 0 ? (
                        <p style={{ color: 'var(--muted)' }}>No tokens issued yet.</p>
                    ) : (
                        <table className="dashboard-table dashboard-table--compact">
                            <thead>
                                <tr>
                                    <th>Token</th>
                                    <th>Exam</th>
                                    <th>Class</th>
                                    <th>Uses</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent.tokens.map((t) => (
                                    <tr key={t.id}>
                                        <td>
                                            <code>{t.token ?? '—'}</code>
                                        </td>
                                        <td>
                                            {t.examName}
                                            {t.examId ? (
                                                <div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                                                    {t.examId}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td>
                                            {t.className ?? <span style={{ color: 'var(--muted)' }}>any</span>}
                                        </td>
                                        <td>
                                            {t.usedCount} / {t.maxUses}
                                        </td>
                                        <td>
                                            {t.active ? (
                                                <span className="status-item neutral">
                                                    <KeyRound size={14} aria-hidden /> Active
                                                </span>
                                            ) : (
                                                <span className="status-item warning">
                                                    <CircleX size={14} aria-hidden /> Off
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>

                {/* Recent classes. */}
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>
                                <UsersRound size={18} aria-hidden /> Recent classes
                            </h2>
                            <p>Latest 10 rosters created.</p>
                        </div>
                    </div>
                    {recent.classes.length === 0 ? (
                        <p style={{ color: 'var(--muted)' }}>No classes yet.</p>
                    ) : (
                        <table className="dashboard-table dashboard-table--compact">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Year</th>
                                    <th>Students</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent.classes.map((c) => (
                                    <tr key={c.id}>
                                        <td>
                                            <strong>{c.name}</strong>
                                            {c.sourceFileName ? (
                                                <div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                                                    {c.sourceFileName}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td>{c.academicYear ?? '—'}</td>
                                        <td>{c.studentCount}</td>
                                        <td>{fmt(c.createdAt)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>
        </AdminShell>
    );
}
