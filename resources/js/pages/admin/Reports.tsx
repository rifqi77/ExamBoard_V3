import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Download, FileSpreadsheet, Users } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import AdminShell from '@/components/AdminShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------------------
// Admin Reports — school-wide port of the teacher report matrix. The matrix
// is computed server-side (Admin\ReportsController@index) and passed in as
// `report`; the page renders the per-class collapsible score tables, drives
// the .xlsx export (Admin\ReportsController@export) via a native form POST,
// and adds a teacher picker that scopes both the matrix and the export to one
// teacher via ?teacherId=.
// ---------------------------------------------------------------------------

type ReportColumnKey = 'username' | 'perExam' | 'average' | 'passed' | 'pending' | 'strongest' | 'weakest';
type ReportColumnSelection = Record<ReportColumnKey, boolean>;

const DEFAULT_COLUMN_SELECTION: ReportColumnSelection = {
    username: true,
    perExam: true,
    average: true,
    passed: true,
    pending: true,
    strongest: true,
    weakest: true,
};

const COLUMN_LABELS: Array<{ key: ReportColumnKey; label: string }> = [
    { key: 'username', label: 'Username' },
    { key: 'perExam', label: 'Per-exam scores' },
    { key: 'average', label: 'Average %' },
    { key: 'passed', label: 'Passed / Taken' },
    { key: 'pending', label: 'Pending count' },
    { key: 'strongest', label: 'Strongest topic' },
    { key: 'weakest', label: 'Weakest topic' },
];

type ReportExamColumn = { examDatabaseId: string; examId: string; examName: string; passingGrade: number };
type ReportPerExamCell = { percent: number; passed: boolean; status: 'pending_grading' | 'graded' };
type ReportStudentRow = {
    studentId: string;
    studentName: string;
    username: string;
    perExam: Record<string, ReportPerExamCell>;
    examsTaken: number;
    examsPassed: number;
    pendingCount: number;
    averagePercent: number | null;
    strongestTopic: string | null;
    weakestTopic: string | null;
};
type ReportClassGroup = {
    classId: string | null;
    className: string;
    academicYear: string | null;
    studentCount: number;
    students: ReportStudentRow[];
};
type AdminReport = { exams: ReportExamColumn[]; classes: ReportClassGroup[] };
type TeacherOption = { userId: string; fullName: string; subject: string | null; active: boolean };

type PageProps = {
    report: AdminReport;
    currentAcademicYear: string;
    teachers: TeacherOption[];
    teacherId: string | null;
};

const NO_CLASS_KEY = '__no_class__';

function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);
}
function pad(n: number): string {
    return String(n).padStart(2, '0');
}
function stamp(): string {
    const d = new Date();
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}`;
}
function classKey(classId: string | null): string {
    return classId ?? NO_CLASS_KEY;
}

export default function Reports() {
    const { report, currentAcademicYear, teachers, teacherId } = usePage().props as unknown as PageProps;

    const [columns, setColumns] = useUIState<ReportColumnSelection>('admin.reports.columns', DEFAULT_COLUMN_SELECTION);
    const [openClasses, setOpenClasses] = useUIState<string[]>('admin.reports.openClasses', []);
    const [yearFilter, setYearFilter] = useUIState<string>('admin.reports.yearFilter', currentAcademicYear);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const downloadFrame = useRef<HTMLIFrameElement>(null);

    function toggleColumn(key: ReportColumnKey) {
        setColumns((cur) => ({ ...cur, [key]: !cur[key] }));
    }
    function isClassOpen(k: string) {
        return openClasses.includes(k);
    }
    function togglePanel(k: string) {
        setOpenClasses((cur) => (cur.includes(k) ? cur.filter((x) => x !== k) : [...cur, k]));
    }

    function onScope(next: string) {
        router.get('/admin/reports', next ? { teacherId: next } : {}, { preserveState: false, preserveScroll: true });
    }

    // Native form POST so the browser downloads the .xlsx. Same-origin POST
    // carries cookies + a matching Origin/Referer (session auth + CSRF pass).
    // The current teacher scope is forwarded so the export matches the matrix.
    function doExport(targetClassIds: Array<string | null>, label: string, filename: string) {
        setError('');
        setMessage('');
        try {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/admin/reports/export';
            form.target = 'report-download-frame';
            form.style.display = 'none';

            const append = (name: string, value: string) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            };

            targetClassIds.forEach((id) => append('classIds[]', classKey(id)));
            (Object.keys(columns) as ReportColumnKey[]).forEach((k) => append(`columns[${k}]`, columns[k] ? '1' : '0'));
            append('label', label);
            append('filename', filename);
            if (teacherId) append('teacherId', teacherId);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            setMessage(`Exported ${label}.`);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Export failed.');
        }
    }

    function exportOne(classId: string | null, className: string) {
        doExport([classId], className, `report_${slugify(className)}.xlsx`);
    }
    function exportAll() {
        const all = filteredClasses.map((c) => c.classId);
        if (all.length === 0) {
            setError('No classes to export.');
            return;
        }
        doExport(all, 'all classes', `report_all_${stamp()}.xlsx`);
    }

    const availableYears = useMemo(
        () =>
            Array.from(
                new Set((report?.classes ?? []).map((c) => c.academicYear).filter((y): y is string => Boolean(y))),
            ).sort((a, b) => b.localeCompare(a)),
        [report],
    );

    const filteredClasses = useMemo(
        () => report?.classes.filter((c) => !yearFilter || c.academicYear === null || c.academicYear === yearFilter) ?? [],
        [report, yearFilter],
    );

    const totalStudents = filteredClasses.reduce((sum, c) => sum + c.studentCount, 0);
    const totalSubmissions = filteredClasses.reduce(
        (sum, c) => sum + c.students.reduce((s, st) => s + st.examsTaken + st.pendingCount, 0),
        0,
    );

    const allFilteredKeys = filteredClasses.map((c) => classKey(c.classId));
    const allOpen = allFilteredKeys.length > 0 && allFilteredKeys.every((k) => openClasses.includes(k));

    return (
        <AdminShell>
            <Head title="Admin · Reports" />
            {/* Hidden sink for the streamed .xlsx download. */}
            <iframe ref={downloadFrame} name="report-download-frame" title="download" style={{ display: 'none' }} />

            <header className="teacher-page-header">
                <div>
                    <h1>Reports</h1>
                    <p>
                        Student achievement and progress across every teacher's exams, grouped by class. Export to Excel for
                        end-of-semester reports.
                        {filteredClasses.length > 0 ? (
                            <>
                                {' · '}
                                <button
                                    type="button"
                                    className="inline-link-button"
                                    onClick={() => setOpenClasses(allOpen ? [] : allFilteredKeys)}
                                >
                                    {allOpen ? 'Collapse all' : 'Expand all'}
                                </button>
                            </>
                        ) : null}
                    </p>
                </div>
            </header>

            {error ? <p className="form-error">{error}</p> : null}
            {message ? <p className="form-success">{message}</p> : null}

            <TeacherScopePicker teachers={teachers} value={teacherId} onChange={onScope} />

            <section className="admin-metrics">
                <div className="admin-panel metric-card">
                    <span>Classes</span>
                    <strong>{filteredClasses.length}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Students</span>
                    <strong>{totalStudents}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Exams</span>
                    <strong>{report?.exams.length ?? 0}</strong>
                </div>
                <div className="admin-panel metric-card">
                    <span>Submissions</span>
                    <strong>{totalSubmissions}</strong>
                </div>
            </section>

            {availableYears.length > 0 ? (
                <div className="year-filter">
                    <label htmlFor="reports-year-filter">Academic year:</label>
                    <select id="reports-year-filter" value={yearFilter} onChange={(e) => setYearFilter(e.target.value)}>
                        <option value="">All years</option>
                        {availableYears.map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Export options</h2>
                        <p>Pick which columns to include and which classes to export.</p>
                    </div>
                </div>
                <div className="report-export-grid">
                    <div>
                        <span className="grading-label">Columns</span>
                        <div className="report-toggle-list">
                            {COLUMN_LABELS.map((col) => (
                                <label key={col.key} className="report-toggle">
                                    <input type="checkbox" checked={columns[col.key]} onChange={() => toggleColumn(col.key)} />
                                    {col.label}
                                </label>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="question-form-actions" style={{ marginTop: 16 }}>
                    <button className="primary-button" type="button" onClick={exportAll} disabled={!report}>
                        <FileSpreadsheet size={17} aria-hidden /> Export all classes
                    </button>
                </div>
            </section>

            {!report ? (
                <p style={{ color: 'var(--muted)' }}>Loading report…</p>
            ) : filteredClasses.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)', margin: 0 }}>
                        {report.classes.length > 0
                            ? 'No classes match this year filter.'
                            : 'No classes yet. Upload an Excel roster from the Students page first.'}
                    </p>
                </section>
            ) : (
                filteredClasses.map((cls) => {
                    const ckey = classKey(cls.classId);
                    const open = isClassOpen(ckey);
                    const empty = cls.students.length === 0;
                    return (
                        <section key={ckey} className="admin-panel class-panel">
                            <div className="section-title-row">
                                <button
                                    type="button"
                                    className="class-panel-header"
                                    aria-expanded={open}
                                    onClick={() => togglePanel(ckey)}
                                    disabled={empty}
                                    style={empty ? { cursor: 'default' } : undefined}
                                >
                                    {empty ? (
                                        <span style={{ width: 16, display: 'inline-block' }} aria-hidden />
                                    ) : open ? (
                                        <ChevronDown size={16} aria-hidden className="class-panel-chevron" />
                                    ) : (
                                        <ChevronRight size={16} aria-hidden className="class-panel-chevron" />
                                    )}
                                    <div>
                                        <h2>
                                            {cls.className}
                                            {cls.academicYear ? <span className="muted"> · {cls.academicYear}</span> : null}
                                        </h2>
                                        <p>
                                            {cls.studentCount} student{cls.studentCount === 1 ? '' : 's'}
                                        </p>
                                    </div>
                                </button>
                                <button className="ghost-button" type="button" onClick={() => exportOne(cls.classId, cls.className)}>
                                    <Download size={14} aria-hidden /> Export this class
                                </button>
                            </div>
                            {!open ? null : empty ? (
                                <p style={{ color: 'var(--muted)' }}>No students assigned to this class yet.</p>
                            ) : (
                                <div style={{ overflowX: 'auto' }}>
                                    <table className="dashboard-table">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                {columns.username ? <th>Username</th> : null}
                                                {columns.perExam
                                                    ? report.exams.map((exam) => <th key={exam.examDatabaseId}>{exam.examName}</th>)
                                                    : null}
                                                {columns.average ? <th>Avg %</th> : null}
                                                {columns.passed ? <th>Passed / Taken</th> : null}
                                                {columns.pending ? <th>Pending</th> : null}
                                                {columns.strongest ? <th>Strongest</th> : null}
                                                {columns.weakest ? <th>Weakest</th> : null}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {cls.students.map((student) => (
                                                <tr key={student.studentId}>
                                                    <td>
                                                        <strong>{student.studentName}</strong>
                                                    </td>
                                                    {columns.username ? (
                                                        <td>
                                                            <span style={{ color: 'var(--muted)' }}>{student.username}</span>
                                                        </td>
                                                    ) : null}
                                                    {columns.perExam
                                                        ? report.exams.map((exam) => {
                                                              const cell = student.perExam[exam.examDatabaseId];
                                                              if (!cell)
                                                                  return (
                                                                      <td key={exam.examDatabaseId}>
                                                                          <span style={{ color: 'var(--muted)' }}>—</span>
                                                                      </td>
                                                                  );
                                                              return (
                                                                  <td key={exam.examDatabaseId}>
                                                                      {cell.percent}%
                                                                      {cell.status === 'pending_grading'
                                                                          ? ' ⏳'
                                                                          : cell.passed
                                                                            ? ' ✓'
                                                                            : ''}
                                                                  </td>
                                                              );
                                                          })
                                                        : null}
                                                    {columns.average ? (
                                                        <td>{student.averagePercent === null ? '—' : `${student.averagePercent}%`}</td>
                                                    ) : null}
                                                    {columns.passed ? (
                                                        <td>
                                                            {student.examsPassed} / {student.examsTaken}
                                                        </td>
                                                    ) : null}
                                                    {columns.pending ? <td>{student.pendingCount}</td> : null}
                                                    {columns.strongest ? <td>{student.strongestTopic ?? '—'}</td> : null}
                                                    {columns.weakest ? <td>{student.weakestTopic ?? '—'}</td> : null}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    );
                })
            )}
        </AdminShell>
    );
}

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
            <select id="admin-teacher-scope" value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
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
