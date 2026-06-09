import { Link, router, usePage, Head } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ExternalLink, FileDown, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import TeacherShell from '@/components/TeacherShell';
import { useUIState } from '@/lib/useUIState';

// Pending Score: the shared Exam → Class → Students tree, restricted to
// submissions that still have a pending essay. Each exam (and each
// student) can be exported as a self-contained Markdown bundle the
// teacher pastes into Claude/ChatGPT; the JSON it returns is pasted back
// via "Import AI scores" which POSTs to /teacher/grade-bulk.

type SubmissionSummary = {
    id: string;
    examId: string;
    studentName: string;
    username: string;
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

type AiExports = { byExam: Record<string, string>; bySubmission: Record<string, string> };

function copyOrDownload(filename: string, text: string, onNotice: (msg: string) => void) {
    const done = () =>
        onNotice(
            `Copied ${filename} (${text.length.toLocaleString()} chars) to clipboard. Paste into Claude / ChatGPT, then paste the JSON it returns into "Import AI scores" below.`,
        );
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(() => triggerDownload(filename, text, onNotice));
    } else {
        triggerDownload(filename, text, onNotice);
    }
}

function triggerDownload(filename: string, text: string, onNotice: (msg: string) => void) {
    const blob = new Blob([text], { type: 'text/markdown;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    onNotice(`Downloaded ${filename}. Open it, paste the contents into Claude / ChatGPT, then import the JSON below.`);
}

export default function PendingScore() {
    const { groups, aiExports, flash } = usePage().props as unknown as {
        groups: ExamGroupT[];
        aiExports: AiExports;
        flash?: { success?: string | null; error?: string | null };
    };
    // Persisted as arrays (Sets don't JSON-serialize) — wrap for the existing
    // `has`/setter-callback consumers below.
    const [openExamIds, setOpenExamIds] = useUIState<string[]>('teacher.pending-score.openExams', []);
    const [openClassKeys, setOpenClassKeys] = useUIState<string[]>('teacher.pending-score.openClasses', []);
    const openExams = useMemo(() => new Set(openExamIds), [openExamIds]);
    const openClasses = useMemo(() => new Set(openClassKeys), [openClassKeys]);
    const setOpenExams = (updater: (prev: Set<string>) => Set<string>) =>
        setOpenExamIds(Array.from(updater(new Set(openExamIds))));
    const setOpenClasses = (updater: (prev: Set<string>) => Set<string>) =>
        setOpenClassKeys(Array.from(updater(new Set(openClassKeys))));
    const [notice, setNotice] = useState<string | null>(null);

    // Only submissions with pending essays.
    const filteredGroups = useMemo<ExamGroupT[]>(
        () =>
            groups
                .map((g) => ({
                    ...g,
                    classes: g.classes
                        .map((c) => ({ ...c, submissions: c.submissions.filter((s) => s.pendingEssayCount > 0) }))
                        .filter((c) => c.submissions.length > 0),
                }))
                .filter((g) => g.classes.length > 0),
        [groups],
    );

    return (
        <TeacherShell>
            <Head title="Teacher · Pending Score" />
            <header className="teacher-page-header">
                <div>
                    <h1>Pending Score</h1>
                    <p>
                        Essays still waiting for manual grading. Click View to grade each essay alongside the mark scheme, or
                        Export to bundle answers for an AI assistant.
                    </p>
                </div>
            </header>

            {flash?.error ? <p className="form-error">{flash.error}</p> : null}
            {flash?.success ? <p className="form-success">{flash.success}</p> : null}

            <ImportAIScoresPanel />

            {notice ? <p className="form-success">{notice}</p> : null}

            {filteredGroups.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)', margin: 0 }}>
                        Nothing pending — every submitted essay has been graded.
                    </p>
                </section>
            ) : (
                filteredGroups.map((exam) => {
                    const focusedCount = exam.classes.reduce((sum, c) => sum + c.submissions.length, 0);
                    const isOpen = openExams.has(exam.examDatabaseId);
                    const examBundle = aiExports.byExam[exam.examDatabaseId];
                    return (
                        <section className="admin-panel class-panel" key={exam.examDatabaseId}>
                            <div className="section-title-row">
                                <button
                                    type="button"
                                    className="class-panel-header"
                                    aria-expanded={isOpen}
                                    onClick={() =>
                                        setOpenExams((prev) => {
                                            const next = new Set(prev);
                                            if (next.has(exam.examDatabaseId)) next.delete(exam.examDatabaseId);
                                            else next.add(exam.examDatabaseId);
                                            return next;
                                        })
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
                                            <code>{exam.examId}</code> · {focusedCount} pending
                                        </p>
                                    </div>
                                </button>
                                {focusedCount > 0 && examBundle ? (
                                    <button
                                        type="button"
                                        className="ghost-button"
                                        style={{ width: 'auto' }}
                                        onClick={() =>
                                            copyOrDownload(`${exam.examId}-ai-grading.md`, examBundle, setNotice)
                                        }
                                        title="Copy this exam's full essay bundle (prompts + mark schemes + student answers) to clipboard. Paste into Claude/ChatGPT to get AI-suggested grades."
                                    >
                                        <FileDown size={14} aria-hidden /> Export for AI
                                    </button>
                                ) : null}
                            </div>
                            {isOpen
                                ? exam.classes.map((cls) => {
                                      const classKey = `${exam.examDatabaseId}:${cls.classId ?? 'no-class'}`;
                                      const classOpen = openClasses.has(classKey);
                                      return (
                                          <div className="scores-class-group" key={cls.classId ?? 'no-class'}>
                                              <div className="scores-class-header">
                                                  <button
                                                      type="button"
                                                      className="lo-chev"
                                                      aria-expanded={classOpen}
                                                      aria-label={`${classOpen ? 'Collapse' : 'Expand'} ${cls.className}`}
                                                      onClick={() =>
                                                          setOpenClasses((prev) => {
                                                              const next = new Set(prev);
                                                              if (next.has(classKey)) next.delete(classKey);
                                                              else next.add(classKey);
                                                              return next;
                                                          })
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
                                                          {cls.submissions.length} pending
                                                      </span>
                                                  </span>
                                              </div>
                                              {classOpen ? (
                                                  <table className="dashboard-table scores-class-table">
                                                      <thead>
                                                          <tr>
                                                              <th>Student</th>
                                                              <th>Pending essays</th>
                                                              <th>Essay points pending</th>
                                                              <th>Action</th>
                                                          </tr>
                                                      </thead>
                                                      <tbody>
                                                          {cls.submissions.map((s) => {
                                                              const oneBundle = aiExports.bySubmission[s.id];
                                                              return (
                                                                  <tr key={s.id}>
                                                                      <td>
                                                                          <strong>{s.studentName}</strong>{' '}
                                                                          <code style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                                                                              {s.username}
                                                                          </code>
                                                                      </td>
                                                                      <td>{s.pendingEssayCount}</td>
                                                                      <td>{s.pendingEssayPoints} pts</td>
                                                                      <td>
                                                                          <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                                                              <Link
                                                                                  href={`/teacher/scores/${s.id}`}
                                                                                  className="ghost-button"
                                                                                  style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
                                                                              >
                                                                                  <ExternalLink size={13} aria-hidden /> View
                                                                              </Link>
                                                                              {oneBundle ? (
                                                                                  <button
                                                                                      type="button"
                                                                                      className="ghost-button"
                                                                                      onClick={() =>
                                                                                          copyOrDownload(
                                                                                              `${s.examId}-${s.username}-ai-grading.md`,
                                                                                              oneBundle,
                                                                                              setNotice,
                                                                                          )
                                                                                      }
                                                                                      title="Copy this student's essay bundle to clipboard for AI grading"
                                                                                      style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
                                                                                  >
                                                                                      <FileDown size={13} aria-hidden /> Export
                                                                                  </button>
                                                                              ) : null}
                                                                          </div>
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

// Teacher pastes the JSON the AI returned; we POST it to
// /teacher/grade-bulk which validates + recomputes server-side. The
// {applied, skipped, errors} summary comes back via the flash bag
// (success holds the counts, error holds the first few row reasons).
function ImportAIScoresPanel() {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [localError, setLocalError] = useState('');
    const [result, setResult] = useState<{
        applied: number;
        skipped: number;
        errors: Array<{ submissionId?: string; questionId?: string; reason: string }>;
    } | null>(null);

    async function submit() {
        setLocalError('');
        setResult(null);
        let parsed: unknown;
        try {
            parsed = JSON.parse(text.trim());
        } catch {
            setLocalError("That isn't valid JSON. Paste only the JSON array the AI returned, including the outer [ ].");
            return;
        }
        if (!Array.isArray(parsed)) {
            setLocalError('Expected a JSON ARRAY of { submissionId, questionId, score } objects.');
            return;
        }
        setBusy(true);
        try {
            // Plain fetch (not Inertia) so we can read the structured
            // {applied, skipped, errors} JSON directly; then reload the
            // listing so freshly-graded rows drop out of "pending".
            const res = await fetch('/teacher/grade-bulk', {
                method: 'POST',
                // Same-origin fetch — the browser sends Origin/Referer,
                // which is what VerifyOrigin checks for (no token CSRF).
                headers: {
                    'content-type': 'application/json',
                    accept: 'application/json',
                    'x-requested-with': 'XMLHttpRequest',
                },
                body: JSON.stringify({ scores: parsed }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
            setResult(data);
            if (data.applied > 0) {
                setText('');
                router.reload({ only: ['groups', 'aiExports'] });
            }
        } catch (e) {
            setResult({ applied: 0, skipped: 0, errors: [{ reason: e instanceof Error ? e.message : String(e) }] });
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="admin-panel" style={{ padding: 16 }}>
            <button
                type="button"
                className="ghost-button"
                onClick={() => setOpen((v) => !v)}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, width: 'auto' }}
            >
                <Upload size={14} aria-hidden />
                {open ? 'Hide Import AI scores' : 'Import AI scores from clipboard'}
            </button>
            {open ? (
                <div style={{ marginTop: 10 }}>
                    <p style={{ fontSize: '0.85rem', color: 'var(--muted)' }}>
                        Paste the JSON array Claude/ChatGPT returned (must look like{' '}
                        <code>[{`{ submissionId, questionId, score }`}]</code>). The server will validate, re-run scoring per
                        submission, and update the gradebook. You can re-grade later by exporting and pasting again.
                    </p>
                    <textarea
                        rows={8}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        placeholder='[ { "submissionId": "abc-…", "questionId": "def-…", "score": 8, "feedback": "good" } ]'
                        style={{
                            width: '100%',
                            fontFamily: 'monospace',
                            fontSize: '0.8rem',
                            minHeight: 120,
                            padding: 8,
                            border: '1px solid var(--border)',
                            borderRadius: 4,
                        }}
                    />
                    {localError ? <p className="form-error">{localError}</p> : null}
                    <div style={{ marginTop: 8, display: 'flex', gap: 8, alignItems: 'center' }}>
                        <button
                            type="button"
                            className="primary-button"
                            onClick={submit}
                            disabled={busy || text.trim().length === 0}
                        >
                            {busy ? 'Applying…' : 'Apply scores'}
                        </button>
                        {result ? (
                            <span style={{ fontSize: '0.85rem' }}>
                                Applied: <strong>{result.applied}</strong> · Skipped: <strong>{result.skipped}</strong>
                            </span>
                        ) : null}
                    </div>
                    {result && result.errors.length > 0 ? (
                        <details style={{ marginTop: 8 }}>
                            <summary style={{ cursor: 'pointer', color: 'var(--danger)', fontSize: '0.85rem' }}>
                                {result.errors.length} error(s) — click to expand
                            </summary>
                            <ul style={{ fontSize: '0.78rem' }}>
                                {result.errors.map((er, i) => (
                                    <li key={i}>
                                        <code>
                                            {er.submissionId?.substring(0, 8) ?? '?'}/{er.questionId?.substring(0, 8) ?? '?'}
                                        </code>
                                        : {er.reason}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
