import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, CheckCircle2, CircleX, ListChecks, ShieldCheck, Users } from 'lucide-react';
import AdminShell from '@/components/AdminShell';

// Admin All Exams — school-wide live-monitor launchpad. Faithful port of
// AdminAllExamsClient: every teacher's exams with a per-teacher scope
// picker and Live / Audit / Manage actions for any exam, plus the
// exams / active / submissions metric cards. The scope picker round-trips
// to the server with ?teacherId= (exams/classes are owned via created_by).

type TeacherChoice = { userId: string; fullName: string; subject: string | null; active: boolean };

type ExamSummary = {
    examDatabaseId: string;
    examId: string;
    name: string;
    durationMinutes: number;
    passingGrade: number;
    active: boolean;
    ownerTeacherName: string | null;
    totalSubmissions: number;
    averagePercent: number | null;
};

type PageProps = {
    exams: ExamSummary[];
    teachers: TeacherChoice[];
    teacherId: string | null;
    metrics: { exams: number; active: number; submissions: number };
};

export default function AdminAllExams() {
    const { exams, teachers, teacherId, metrics } = usePage().props as unknown as PageProps;

    function setTeacher(next: string) {
        router.get('/admin/all-exams', next ? { teacherId: next } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <AdminShell>
            <Head title="Admin · All exams" />
            <header className="teacher-page-header">
                <div>
                    <h1>All exams</h1>
                    <p>Every teacher&apos;s exams across the school. Open the live monitor or answer audit for any of them.</p>
                </div>
            </header>

            {/* Teacher scope picker (server-rendered list) */}
            <div className="year-filter">
                <Users size={15} aria-hidden />
                <label htmlFor="admin-teacher-scope">Teacher</label>
                <select
                    id="admin-teacher-scope"
                    value={teacherId ?? ''}
                    onChange={(event) => setTeacher(event.target.value)}
                >
                    <option value="">All teachers</option>
                    {teachers.map((teacher) => (
                        <option key={teacher.userId} value={teacher.userId}>
                            {teacher.fullName}
                            {teacher.subject ? ` · ${teacher.subject}` : ''}
                            {teacher.active ? '' : ' (inactive)'}
                        </option>
                    ))}
                </select>
            </div>

            <section className="admin-metrics">
                <div className="admin-panel metric-card">
                    <span>Exams</span>
                    <strong>{metrics.exams}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <Activity size={20} aria-hidden />
                    <span>Active</span>
                    <strong>{metrics.active}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Submissions</span>
                    <strong>{metrics.submissions}</strong>
                </div>
            </section>

            {exams.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No exams found.</p>
                </section>
            ) : (
                <section className="admin-panel">
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Exam</th>
                                <th>Teacher</th>
                                <th>Duration</th>
                                <th>Passing</th>
                                <th>Submissions</th>
                                <th>Avg</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {exams.map((exam) => (
                                <tr key={exam.examDatabaseId}>
                                    <td>
                                        <strong>{exam.name}</strong>
                                        <div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>
                                            <code>{exam.examId}</code>
                                        </div>
                                    </td>
                                    <td>{exam.ownerTeacherName ?? '—'}</td>
                                    <td>{exam.durationMinutes} min</td>
                                    <td>{exam.passingGrade}%</td>
                                    <td>{exam.totalSubmissions}</td>
                                    <td>{exam.averagePercent === null ? '—' : `${exam.averagePercent}%`}</td>
                                    <td>
                                        {exam.active ? (
                                            <span className="status-item neutral">
                                                <CheckCircle2 size={14} aria-hidden /> Active
                                            </span>
                                        ) : (
                                            <span className="status-item warning">
                                                <CircleX size={14} aria-hidden /> Inactive
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                            <Link className="ghost-button" href={`/admin/exams/${exam.examId}/live`}>
                                                <Activity size={14} aria-hidden /> Live
                                            </Link>
                                            <Link className="ghost-button" href={`/admin/exams/${exam.examId}/audit`}>
                                                <ShieldCheck size={14} aria-hidden /> Audit
                                            </Link>
                                            <Link className="ghost-button" href={`/admin/exams/${exam.examId}`}>
                                                <ListChecks size={14} aria-hidden /> Manage
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}
        </AdminShell>
    );
}
