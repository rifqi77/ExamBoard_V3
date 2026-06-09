import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    BookOpenCheck,
    ClipboardList,
    Library,
    LineChart,
    PenLine,
    ShieldCheck,
    TrendingDown,
    TrendingUp,
    Users,
} from 'lucide-react';
import { ReactNode, useMemo } from 'react';
import AdminShell from '@/components/AdminShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------------------
// Admin Analyze — faithful port of AdminAnalyzeClient + /api/admin/analyze,
// rebuilt as a 2-tab page (Dashboard | Item analysis). All numbers are
// computed server-side in Admin\AnalyzeController and passed in as props; the
// page is a pure renderer. A teacher picker scopes every section to one
// teacher via ?teacherId= (server reloads with the narrowed data).
// ---------------------------------------------------------------------------

const TYPE_LABELS: Record<string, string> = {
    single_choice: 'Single choice',
    multi_select: 'Multi select',
    short_text: 'Short text',
    numeric: 'Numeric',
    essay: 'Essay',
};

// Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
const DIFFICULTY_LABELS: Record<string, string> = {
    remember: 'Remember',
    understand: 'Understand',
    apply: 'Apply',
    analyze: 'Analyze',
    evaluate: 'Evaluate',
    create: 'Create',
    olympiad: 'Olympiad',
};

// ---- Prop types (mirror AnalyzeController) ------------------------------

type LabelCount = { label: string; count: number };

type AnalyzeData = {
    system: {
        teacherCount: number;
        activeTeacherCount: number;
        disabledTeacherCount: number;
        studentCount: number;
        examCount: number;
        submissionCount: number;
        bankQuestionCount: number;
        submissionsByDay: Array<{ date: string; count: number }>;
    };
    performance: {
        perExam: Array<{
            examId: string;
            examName: string;
            submissionCount: number;
            passedCount: number;
            passRate: number;
            averagePercent: number | null;
        }>;
        topScorers: Array<{ studentName: string; username: string; averagePercent: number; examsTaken: number }>;
        bottomScorers: Array<{ studentName: string; username: string; averagePercent: number; examsTaken: number }>;
        scoreDistribution: Array<{ bucket: string; count: number }>;
    };
    topics: {
        strongest: Array<{ topic: string; percent: number; submissionCount: number }>;
        weakest: Array<{ topic: string; percent: number; submissionCount: number }>;
    };
    bank: {
        bySubject: LabelCount[];
        byType: LabelCount[];
        byDifficulty: LabelCount[];
        mostUsed: Array<{ bankQuestionId: string; prompt: string; usageCount: number }>;
        unusedCount: number;
    };
    workload: {
        perTeacher: Array<{ teacherId: string; teacherName: string; pendingCount: number }>;
        oldestPending: Array<{
            submissionId: string;
            studentName: string;
            examName: string;
            submittedAt: string | null;
            daysOld: number;
        }>;
    };
};

type ItemStat = {
    questionId: string;
    position: number;
    type: string;
    topic: string;
    prompt: string;
    points: number;
    responses: number;
    gradedResponses: number;
    correctCount: number;
    correctRate: number | null;
    difficultyIndex: number | null;
    averagePercent: number | null;
    isEssay: boolean;
    optionCounts: Array<{ label: string; count: number; isCorrect: boolean }>;
};

type ItemAnalysisExam = {
    examDatabaseId: string;
    examId: string;
    examName: string;
    passingGrade: number;
    submissionCount: number;
    questionCount: number;
    items: ItemStat[];
};

type TeacherOption = { userId: string; fullName: string; subject: string | null; active: boolean };

type PageProps = {
    analyze: AnalyzeData;
    itemAnalysis: ItemAnalysisExam[];
    teachers: TeacherOption[];
    teacherId: string | null;
};

type TabKey = 'dashboard' | 'items';

export default function Analyze() {
    const { analyze, itemAnalysis, teachers, teacherId } = usePage().props as unknown as PageProps;
    const [tab, setTab] = useUIState<TabKey>('admin.analyze.activeTab', 'dashboard');

    function onScope(next: string) {
        // Reload the whole page with the new scope so the server recomputes.
        router.get(
            '/admin/analyze',
            next ? { teacherId: next } : {},
            { preserveState: false, preserveScroll: true },
        );
    }

    return (
        <AdminShell>
            <Head title="Admin · Analyze" />
            <header className="teacher-page-header">
                <div>
                    <h1>Analyze</h1>
                    <p>
                        System activity, performance, topic insights, bank composition, and grading workload across every
                        teacher — plus per-exam item analysis.
                    </p>
                </div>
            </header>

            <TeacherScopePicker teachers={teachers} value={teacherId} onChange={onScope} />

            <div className="curriculum-tabs" role="tablist" aria-label="Analyze views">
                <button
                    type="button"
                    role="tab"
                    aria-selected={tab === 'dashboard'}
                    className={`curriculum-tab${tab === 'dashboard' ? ' active' : ''}`}
                    onClick={() => setTab('dashboard')}
                >
                    <span>
                        <LineChart size={15} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                        Dashboard
                    </span>
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected={tab === 'items'}
                    className={`curriculum-tab${tab === 'items' ? ' active' : ''}`}
                    onClick={() => setTab('items')}
                >
                    <span>
                        <BarChart3 size={15} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                        Item analysis
                    </span>
                    <span className="curriculum-tab-count">{itemAnalysis.length}</span>
                </button>
            </div>

            {tab === 'dashboard' ? <Dashboard data={analyze} /> : <ItemAnalysis exams={itemAnalysis} />}
        </AdminShell>
    );
}

// ===========================================================================
// Tab 1 — System dashboard (the 9 original sections)
// ===========================================================================

function Dashboard({ data }: { data: AnalyzeData }) {
    const submissionsByDayMax = useMemo(
        () => Math.max(1, ...data.system.submissionsByDay.map((d) => d.count)),
        [data],
    );
    const scoreDistMax = useMemo(
        () => Math.max(1, ...data.performance.scoreDistribution.map((b) => b.count)),
        [data],
    );

    return (
        <>
            {/* ============ 1 + 2 SYSTEM ACTIVITY ============ */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>System activity</h2>
                        <p>Totals + submissions over the last 30 days.</p>
                    </div>
                    <Activity size={20} aria-hidden />
                </div>
                <div className="admin-metrics" style={{ marginTop: 12 }}>
                    <MiniCard
                        icon={<ShieldCheck size={18} aria-hidden />}
                        label="Teachers"
                        value={data.system.teacherCount}
                        detail={`${data.system.activeTeacherCount} active · ${data.system.disabledTeacherCount} disabled`}
                    />
                    <MiniCard icon={<Users size={18} aria-hidden />} label="Students" value={data.system.studentCount} />
                    <MiniCard icon={<BookOpenCheck size={18} aria-hidden />} label="Exams" value={data.system.examCount} />
                    <MiniCard
                        icon={<ClipboardList size={18} aria-hidden />}
                        label="Submissions"
                        value={data.system.submissionCount}
                    />
                    <MiniCard
                        icon={<Library size={18} aria-hidden />}
                        label="Bank questions"
                        value={data.system.bankQuestionCount}
                    />
                </div>
                <div className="analyze-histogram" style={{ marginTop: 18 }}>
                    <span className="grading-label">Submissions per day (last 30)</span>
                    <div className="analyze-day-bars">
                        {data.system.submissionsByDay.map((d) => {
                            const heightPct = (d.count / submissionsByDayMax) * 100;
                            return (
                                <div key={d.date} className="analyze-day-col" title={`${d.date}: ${d.count}`}>
                                    <div className="analyze-day-bar" style={{ height: `${Math.max(2, heightPct)}%` }} />
                                    <span>{d.date.slice(5)}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* ============ 3 + 4 + 5 + 6 PERFORMANCE ============ */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Performance</h2>
                        <p>Pass rate per exam, top + bottom scorers, score distribution.</p>
                    </div>
                    <TrendingUp size={20} aria-hidden />
                </div>

                <div className="content-heading" style={{ marginTop: 8 }}>
                    <h3 style={{ margin: 0 }}>Pass rate per exam</h3>
                </div>
                {data.performance.perExam.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No submissions yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Exam</th>
                                <th>Submissions</th>
                                <th>Passed</th>
                                <th>Pass rate</th>
                                <th>Average</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.performance.perExam.map((row) => (
                                <tr key={row.examId}>
                                    <td>
                                        <strong>{row.examName}</strong>
                                        <div style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
                                            <code>{row.examId}</code>
                                        </div>
                                    </td>
                                    <td>{row.submissionCount}</td>
                                    <td>{row.passedCount}</td>
                                    <td>{row.passRate}%</td>
                                    <td>{row.averagePercent === null ? '—' : `${row.averagePercent}%`}</td>
                                    <td style={{ minWidth: 140 }}>
                                        <div className="analyze-bar-track">
                                            <div className="analyze-bar-fill" style={{ width: `${row.passRate}%` }} />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <div className="report-export-grid" style={{ marginTop: 18 }}>
                    <div>
                        <span className="grading-label">Top 10 scorers</span>
                        {data.performance.topScorers.length === 0 ? (
                            <p style={{ color: 'var(--muted)' }}>No graded submissions yet.</p>
                        ) : (
                            <ScorerTable rows={data.performance.topScorers} />
                        )}
                    </div>
                    <div>
                        <span className="grading-label">Bottom 10 scorers</span>
                        {data.performance.bottomScorers.length === 0 ? (
                            <p style={{ color: 'var(--muted)' }}>No graded submissions yet.</p>
                        ) : (
                            <ScorerTable rows={data.performance.bottomScorers} />
                        )}
                    </div>
                </div>

                <div className="analyze-histogram" style={{ marginTop: 18 }}>
                    <span className="grading-label">Score distribution</span>
                    <div className="analyze-day-bars analyze-score-buckets">
                        {data.performance.scoreDistribution.map((b) => {
                            const heightPct = (b.count / scoreDistMax) * 100;
                            return (
                                <div key={b.bucket} className="analyze-day-col" title={`${b.bucket}%: ${b.count}`}>
                                    <div className="analyze-day-bar score" style={{ height: `${Math.max(2, heightPct)}%` }} />
                                    <span>{b.bucket}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* ============ 7 TOPICS ============ */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Topic insights (cross-teacher)</h2>
                        <p>Aggregated topic achievement across every fully-graded submission.</p>
                    </div>
                    <TrendingDown size={20} aria-hidden />
                </div>
                <div className="report-export-grid" style={{ marginTop: 12 }}>
                    <div>
                        <span className="grading-label">Strongest topics</span>
                        {data.topics.strongest.length === 0 ? (
                            <p style={{ color: 'var(--muted)' }}>No topic data yet.</p>
                        ) : (
                            <TopicTable rows={data.topics.strongest} fillClass="strong" />
                        )}
                    </div>
                    <div>
                        <span className="grading-label">Weakest topics</span>
                        {data.topics.weakest.length === 0 ? (
                            <p style={{ color: 'var(--muted)' }}>No topic data yet.</p>
                        ) : (
                            <TopicTable rows={data.topics.weakest} fillClass="weak" />
                        )}
                    </div>
                </div>
            </section>

            {/* ============ 8 BANK INSIGHTS ============ */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Question bank insights</h2>
                        <p>Composition by subject / type / difficulty plus most-used questions.</p>
                    </div>
                    <Library size={20} aria-hidden />
                </div>
                <div className="analyze-bank-grid">
                    <BankBreakdown title="By subject" rows={data.bank.bySubject} />
                    <BankBreakdown
                        title="By type"
                        rows={data.bank.byType.map((r) => ({ ...r, label: TYPE_LABELS[r.label] ?? r.label }))}
                    />
                    <BankBreakdown
                        title="By difficulty"
                        rows={data.bank.byDifficulty.map((r) => ({ ...r, label: DIFFICULTY_LABELS[r.label] ?? r.label }))}
                    />
                </div>
                <div style={{ marginTop: 18 }}>
                    <span className="grading-label">
                        Most-used bank questions ·{' '}
                        <span style={{ color: 'var(--muted)' }}>{data.bank.unusedCount} unused</span>
                    </span>
                    {data.bank.mostUsed.length === 0 ? (
                        <p style={{ color: 'var(--muted)' }}>No bank questions have been copied into exams yet.</p>
                    ) : (
                        <table className="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Prompt</th>
                                    <th>Times used</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.bank.mostUsed.map((q) => (
                                    <tr key={q.bankQuestionId}>
                                        <td>{q.prompt || <span style={{ color: 'var(--muted)' }}>—</span>}</td>
                                        <td>{q.usageCount}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </section>

            {/* ============ 9 WORKLOAD ============ */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Grading workload</h2>
                        <p>Pending essays per teacher and the oldest submissions waiting on a grade.</p>
                    </div>
                    <PenLine size={20} aria-hidden />
                </div>
                <div className="content-heading" style={{ marginTop: 8 }}>
                    <h3 style={{ margin: 0 }}>Pending essays per teacher</h3>
                </div>
                {data.workload.perTeacher.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No teachers yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Pending essays</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.workload.perTeacher.map((t) => (
                                <tr key={t.teacherId}>
                                    <td>{t.teacherName}</td>
                                    <td>{t.pendingCount}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <div className="content-heading" style={{ marginTop: 18 }}>
                    <h3 style={{ margin: 0 }}>Oldest pending submissions</h3>
                </div>
                {data.workload.oldestPending.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No submissions are waiting on a grade.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Exam</th>
                                <th>Submitted</th>
                                <th>Age (days)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.workload.oldestPending.map((s) => (
                                <tr key={s.submissionId}>
                                    <td>{s.studentName}</td>
                                    <td>{s.examName}</td>
                                    <td>{s.submittedAt ? new Date(s.submittedAt).toLocaleDateString() : '—'}</td>
                                    <td>{s.daysOld}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </>
    );
}

// ===========================================================================
// Tab 2 — Item analysis (per-exam, per-question difficulty + answer mix)
// ===========================================================================

function ItemAnalysis({ exams }: { exams: ItemAnalysisExam[] }) {
    const [openExam, setOpenExam] = useUIState<string | null>(
        'admin.analyze.itemAnalysis.openExam',
        exams.length > 0 ? exams[0].examDatabaseId : null,
    );

    if (exams.length === 0) {
        return (
            <section className="admin-panel">
                <p style={{ color: 'var(--muted)', margin: 0 }}>
                    No exams with submissions yet. Once students submit, per-question item statistics appear here.
                </p>
            </section>
        );
    }

    return (
        <>
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Item analysis</h2>
                        <p>
                            Per-question difficulty index (mean awarded ÷ possible) and the answer / option distribution,
                            recomputed from every submission. Low difficulty = hard question; flat distractors rarely chosen.
                        </p>
                    </div>
                    <BarChart3 size={20} aria-hidden />
                </div>
            </section>

            {exams.map((exam) => {
                const open = openExam === exam.examDatabaseId;
                return (
                    <section key={exam.examDatabaseId} className="admin-panel">
                        <div className="section-title-row">
                            <button
                                type="button"
                                className="class-panel-header"
                                aria-expanded={open}
                                onClick={() => setOpenExam(open ? null : exam.examDatabaseId)}
                            >
                                <div>
                                    <h2>{exam.examName}</h2>
                                    <p>
                                        <code>{exam.examId}</code> · {exam.submissionCount} submission
                                        {exam.submissionCount === 1 ? '' : 's'} · {exam.questionCount} question
                                        {exam.questionCount === 1 ? '' : 's'}
                                    </p>
                                </div>
                            </button>
                        </div>
                        {!open ? null : exam.items.length === 0 ? (
                            <p style={{ color: 'var(--muted)' }}>This exam has no questions.</p>
                        ) : (
                            <table className="dashboard-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Question</th>
                                        <th>Type</th>
                                        <th>Responses</th>
                                        <th>Correct</th>
                                        <th>Difficulty</th>
                                        <th style={{ minWidth: 160 }}>Distribution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {exam.items.map((item) => (
                                        <ItemRow key={item.questionId} item={item} />
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                );
            })}
        </>
    );
}

function ItemRow({ item }: { item: ItemStat }) {
    // Difficulty index → a 0..100 bar. Tint by band: green (easy) → red (hard).
    const di = item.difficultyIndex;
    const pct = di === null ? 0 : Math.round(di * 100);
    const fillClass = di === null ? '' : di >= 0.7 ? 'strong' : di <= 0.4 ? 'weak' : '';
    const maxOpt = Math.max(1, ...item.optionCounts.map((o) => o.count));

    return (
        <tr>
            <td>{item.position}</td>
            <td>
                <div style={{ maxWidth: 320 }}>{item.prompt || <span style={{ color: 'var(--muted)' }}>—</span>}</div>
                <div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                    {item.topic} · {item.points} pt{item.points === 1 ? '' : 's'}
                </div>
            </td>
            <td>
                <span className="question-type-pill">{TYPE_LABELS[item.type] ?? item.type}</span>
            </td>
            <td>{item.responses}</td>
            <td>
                {item.isEssay && item.gradedResponses === 0 ? (
                    <span style={{ color: 'var(--muted)' }}>ungraded</span>
                ) : item.correctRate === null ? (
                    <span style={{ color: 'var(--muted)' }}>—</span>
                ) : (
                    <>
                        {item.correctCount}/{item.gradedResponses}{' '}
                        <span style={{ color: 'var(--muted)' }}>({item.correctRate}%)</span>
                    </>
                )}
            </td>
            <td style={{ minWidth: 120 }}>
                {di === null ? (
                    <span style={{ color: 'var(--muted)' }}>—</span>
                ) : (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <div className="analyze-bar-track" style={{ flex: 1 }}>
                            <div className={`analyze-bar-fill ${fillClass}`} style={{ width: `${pct}%` }} />
                        </div>
                        <span>{di.toFixed(2)}</span>
                    </div>
                )}
            </td>
            <td>
                {item.optionCounts.length === 0 ? (
                    <span style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
                        {item.isEssay ? 'free text' : 'n/a'}
                    </span>
                ) : (
                    <ul className="analyze-bank-list">
                        {item.optionCounts.map((o) => (
                            <li key={o.label}>
                                <span style={o.isCorrect ? { fontWeight: 700 } : undefined}>
                                    {o.label}
                                    {o.isCorrect ? ' ✓' : ''}
                                </span>
                                <div className="analyze-bar-track">
                                    <div
                                        className={`analyze-bar-fill ${o.isCorrect ? 'strong' : ''}`}
                                        style={{ width: `${(o.count / maxOpt) * 100}%` }}
                                    />
                                </div>
                                <strong>{o.count}</strong>
                            </li>
                        ))}
                    </ul>
                )}
            </td>
        </tr>
    );
}

// ===========================================================================
// Shared bits
// ===========================================================================

function TeacherScopePicker({
    teachers,
    value,
    onChange,
}: {
    teachers: TeacherOption[];
    value: string | null;
    onChange: (next: string) => void;
}) {
    return (
        <div className="year-filter">
            <Users size={15} aria-hidden />
            <label htmlFor="admin-teacher-scope">Teacher</label>
            <select
                id="admin-teacher-scope"
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">All teachers</option>
                {teachers.map((t) => (
                    <option key={t.userId} value={t.userId}>
                        {t.fullName}
                        {t.subject ? ` · ${t.subject}` : ''}
                        {t.active ? '' : ' (inactive)'}
                    </option>
                ))}
            </select>
        </div>
    );
}

function MiniCard({
    icon,
    label,
    value,
    detail,
}: {
    icon: ReactNode;
    label: string;
    value: number;
    detail?: string;
}) {
    return (
        <div className="admin-panel metric-card">
            {icon}
            <span>{label}</span>
            <strong>{value}</strong>
            {detail ? <span style={{ color: 'var(--muted)', fontSize: '0.75rem' }}>{detail}</span> : null}
        </div>
    );
}

function ScorerTable({
    rows,
}: {
    rows: Array<{ studentName: string; username: string; averagePercent: number; examsTaken: number }>;
}) {
    return (
        <table className="dashboard-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Avg</th>
                    <th>Exams</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((s) => (
                    <tr key={s.username}>
                        <td>
                            <strong>{s.studentName}</strong>
                            <div style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>{s.username}</div>
                        </td>
                        <td>{s.averagePercent}%</td>
                        <td>{s.examsTaken}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function TopicTable({
    rows,
    fillClass,
}: {
    rows: Array<{ topic: string; percent: number; submissionCount: number }>;
    fillClass: 'strong' | 'weak';
}) {
    return (
        <table className="dashboard-table">
            <thead>
                <tr>
                    <th>Topic</th>
                    <th>Achievement</th>
                    <th>Submissions</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr key={row.topic}>
                        <td>
                            <strong>{row.topic}</strong>
                        </td>
                        <td style={{ minWidth: 140 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <div className="analyze-bar-track" style={{ flex: 1 }}>
                                    <div className={`analyze-bar-fill ${fillClass}`} style={{ width: `${row.percent}%` }} />
                                </div>
                                <span>{row.percent}%</span>
                            </div>
                        </td>
                        <td>{row.submissionCount}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function BankBreakdown({ title, rows }: { title: string; rows: LabelCount[] }) {
    const max = Math.max(1, ...rows.map((r) => r.count));
    return (
        <div className="analyze-bank-card">
            <span className="grading-label">{title}</span>
            {rows.length === 0 ? (
                <p style={{ color: 'var(--muted)', margin: '6px 0 0' }}>—</p>
            ) : (
                <ul className="analyze-bank-list">
                    {rows.map((r) => (
                        <li key={r.label}>
                            <span>{r.label}</span>
                            <div className="analyze-bar-track">
                                <div className="analyze-bar-fill" style={{ width: `${(r.count / max) * 100}%` }} />
                            </div>
                            <strong>{r.count}</strong>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
