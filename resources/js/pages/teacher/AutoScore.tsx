import { Link, usePage, Head } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ExternalLink } from 'lucide-react';
import TeacherShell from '@/components/TeacherShell';
import { useUIState } from '@/lib/useUIState';

// Auto Score focuses the shared Exam → Class → Students tree on the
// auto-graded portion (MCQ + multi-select + short-text + numeric). Per-
// student answer-vs-key-vs-explanation detail lives one click away on the
// grading page (View), which renders every item with the student answer,
// correct answer, and explanation.

type SubmissionSummary = {
    id: string;
    examId: string;
    studentName: string;
    username: string;
    autoEarned: number;
    autoPossible: number;
    pendingEssayCount: number;
    pendingEssayPoints: number;
};

type ClassGroupT = {
    classId: string | null;
    className: string;
    academicYear: string | null;
    submissions: SubmissionSummary[];
};

type ExamGroupT = {
    examDatabaseId: string;
    examId: string;
    examName: string;
    classes: ClassGroupT[];
};

export default function AutoScore() {
    const { groups } = usePage().props as unknown as { groups: ExamGroupT[] };
    const [openExams, setOpenExams] = useUIState<string[]>('teacher.auto-score.openExams', []);
    const [openClasses, setOpenClasses] = useUIState<string[]>('teacher.auto-score.openClasses', []);

    return (
        <TeacherShell>
            <Head title="Teacher · Auto Score" />
            <header className="teacher-page-header">
                <div>
                    <h1>Auto Score</h1>
                    <p>
                        Auto-graded portion only (MCQ, multi-select, short-text, numeric). Click View to see each student's
                        answer next to the key and feedback explanation.
                    </p>
                </div>
            </header>

            {groups.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No submissions yet.</p>
                </section>
            ) : (
                groups.map((exam) => {
                    const focusedCount = exam.classes.reduce((sum, c) => sum + c.submissions.length, 0);
                    const isOpen = openExams.includes(exam.examDatabaseId);
                    return (
                        <section className="admin-panel class-panel" key={exam.examDatabaseId}>
                            <div className="section-title-row">
                                <button
                                    type="button"
                                    className="class-panel-header"
                                    aria-expanded={isOpen}
                                    onClick={() =>
                                        setOpenExams((prev) =>
                                            prev.includes(exam.examDatabaseId)
                                                ? prev.filter((x) => x !== exam.examDatabaseId)
                                                : [...prev, exam.examDatabaseId],
                                        )
                                    }
                                >
                                    {isOpen ? (
                                        <ChevronDown size={16} aria-hidden className="class-panel-chevron" />
                                    ) : (
                                        <ChevronRight size={16} aria-hidden className="class-panel-chevron" />
                                    )}
                                    <div>
                                        <h2>{exam.examName}</h2>
                                        <p>
                                            <code>{exam.examId}</code> · {focusedCount} submission
                                            {focusedCount === 1 ? '' : 's'}
                                        </p>
                                    </div>
                                </button>
                            </div>
                            {isOpen
                                ? exam.classes.map((cls) => {
                                      const classKey = `${exam.examDatabaseId}:${cls.classId ?? 'no-class'}`;
                                      const classOpen = openClasses.includes(classKey);
                                      return (
                                          <div className="scores-class-group" key={cls.classId ?? 'no-class'}>
                                              <div className="scores-class-header">
                                                  <button
                                                      type="button"
                                                      className="lo-chev"
                                                      aria-expanded={classOpen}
                                                      aria-label={`${classOpen ? 'Collapse' : 'Expand'} ${cls.className}`}
                                                      onClick={() =>
                                                          setOpenClasses((prev) =>
                                                              prev.includes(classKey)
                                                                  ? prev.filter((x) => x !== classKey)
                                                                  : [...prev, classKey],
                                                          )
                                                      }
                                                  >
                                                      {classOpen ? (
                                                          <ChevronDown size={13} aria-hidden />
                                                      ) : (
                                                          <ChevronRight size={13} aria-hidden />
                                                      )}
                                                  </button>
                                                  <span className="scores-class-toggle-label">
                                                      <h3>
                                                          {cls.classId === null ? (
                                                              <em style={{ color: 'var(--muted)' }}>{cls.className}</em>
                                                          ) : (
                                                              cls.className
                                                          )}
                                                          {cls.academicYear ? (
                                                              <span className="muted"> · {cls.academicYear}</span>
                                                          ) : null}
                                                      </h3>
                                                      <span className="scores-class-meta">
                                                          {cls.submissions.length} row{cls.submissions.length === 1 ? '' : 's'}
                                                      </span>
                                                  </span>
                                              </div>
                                              {classOpen ? (
                                                  <table className="dashboard-table scores-class-table">
                                                      <thead>
                                                          <tr>
                                                              <th>Student</th>
                                                              <th>Auto score</th>
                                                              <th>Auto %</th>
                                                              <th>Action</th>
                                                          </tr>
                                                      </thead>
                                                      <tbody>
                                                          {cls.submissions.map((s) => {
                                                              const autoPct =
                                                                  s.autoPossible > 0
                                                                      ? Number(((s.autoEarned / s.autoPossible) * 100).toFixed(1))
                                                                      : 0;
                                                              const auto100 =
                                                                  s.autoPossible > 0
                                                                      ? Number(((s.autoEarned / s.autoPossible) * 100).toFixed(2))
                                                                      : 0;
                                                              return (
                                                                  <tr key={s.id}>
                                                                      <td>
                                                                          <strong>{s.studentName}</strong>{' '}
                                                                          <code style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                                                              {s.username}
                                                                          </code>
                                                                      </td>
                                                                      <td>
                                                                          <strong>{auto100}</strong>
                                                                          <span style={{ color: 'var(--muted)' }}> /100</span>
                                                                          <br />
                                                                          <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                                                              raw {s.autoEarned}/{s.autoPossible}
                                                                          </span>
                                                                      </td>
                                                                      <td>{autoPct}%</td>
                                                                      <td>
                                                                          <Link
                                                                              href={`/teacher/scores/${s.id}`}
                                                                              className="ghost-button"
                                                                              style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
                                                                          >
                                                                              <ExternalLink size={13} aria-hidden /> View
                                                                          </Link>
                                                                      </td>
                                                                  </tr>
                                                              );
                                                          })}
                                                      </tbody>
                                                  </table>
                                              ) : null}
                                          </div>
                                      );
                                  })
                                : null}
                        </section>
                    );
                })
            )}
        </TeacherShell>
    );
}
