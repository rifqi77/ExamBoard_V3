import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity, KeyRound, ListChecks, Plus, RefreshCw, ShieldCheck, Trash2, Upload, X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import AdminShell from '@/components/AdminShell';

// Admin Exams — school-wide list. Faithful to the original
// AdminExamsListClient (every exam, owner column, Create + Manage) plus the
// richer teacher-style list conventions the new console uses: decrypted
// token pills with regenerate/delete, avg %, passed, submissions, delete,
// and an import-package action. Admin is never scoped to created_by, so the
// Owner column is always shown. Live / Audit / Manage open the per-exam
// admin pages reachable for any teacher's exam.

type TokenSummary = {
    id: string;
    tokenPreview: string;
    usedCount: number;
    maxUses: number;
    expiresAt: string | null;
};

type ExamSummary = {
    examDatabaseId: string;
    examId: string;
    name: string;
    durationMinutes: number;
    passingGrade: number;
    active: boolean;
    ownerTeacherName: string | null;
    activeTokens: TokenSummary[];
    activeTokenCount: number;
    totalSubmissions: number;
    averagePercent: number | null;
    passedCount: number;
};

export default function AdminExams() {
    const props = usePage().props as any;
    const exams: ExamSummary[] = props.exams ?? [];
    const flash = props.flash ?? {};

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [busyTokenId, setBusyTokenId] = useState<string | null>(null);
    const [deleteBusyId, setDeleteBusyId] = useState<string | null>(null);

    const importForm = useForm<{ package: File | null }>({ package: null });

    function onFileSelected(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (!file) return;
        importForm.setData('package', file);
        importForm.post('/admin/exams/import', {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (fileInputRef.current) fileInputRef.current.value = '';
                importForm.reset();
            },
        });
    }

    function onRegenerateToken(token: TokenSummary) {
        if (
            !window.confirm(
                `Regenerate token "${token.tokenPreview}"?\n\nThe current code will stop working immediately. A fresh 6-character code will replace it with the same usage limit and expiry.`,
            )
        ) {
            return;
        }
        setBusyTokenId(token.id);
        router.post(`/admin/exams/tokens/${token.id}/regenerate`, {}, {
            preserveScroll: true,
            onFinish: () => setBusyTokenId(null),
        });
    }

    function onDeleteToken(token: TokenSummary) {
        if (
            !window.confirm(
                `Delete token "${token.tokenPreview}"?\n\nStudents using this code will lose access. Cannot be undone.`,
            )
        ) {
            return;
        }
        setBusyTokenId(token.id);
        router.delete(`/admin/exams/tokens/${token.id}`, {
            preserveScroll: true,
            onFinish: () => setBusyTokenId(null),
        });
    }

    function onDeleteExam(exam: ExamSummary) {
        if (
            !window.confirm(
                `Delete the exam "${exam.name}" (${exam.examId})?\n\nThis permanently removes the exam, ALL its questions, ALL access tokens, AND ALL ${exam.totalSubmissions} submission${
                    exam.totalSubmissions === 1 ? '' : 's'
                }. Cannot be undone.`,
            )
        ) {
            return;
        }
        setDeleteBusyId(exam.examDatabaseId);
        router.delete(`/admin/exams/${exam.examId}`, {
            preserveScroll: true,
            onFinish: () => setDeleteBusyId(null),
        });
    }

    return (
        <AdminShell>
            <Head title="Admin · Exams" />
            <header className="teacher-page-header">
                <div>
                    <h1>Exams</h1>
                    <p>
                        Every exam across the school. Open the live monitor, answer audit, or manage questions and tokens for
                        any of them. Use the bank picker inside an exam to draw from every teacher&apos;s bank.
                    </p>
                </div>
                <div>
                    <input ref={fileInputRef} type="file" accept=".zip,.json" hidden onChange={onFileSelected} />
                    <button
                        className="ghost-button"
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={importForm.processing}
                    >
                        <Upload size={17} aria-hidden /> {importForm.processing ? 'Importing…' : 'Import package'}
                    </button>
                    <Link className="primary-button" href="/admin/exams/new">
                        <Plus size={17} aria-hidden /> Create exam
                    </Link>
                </div>
            </header>

            {flash.error ? <p className="form-error">{flash.error}</p> : null}
            {flash.success ? <p className="form-success">{flash.success}</p> : null}

            <details className="admin-panel package-format-help">
                <summary style={{ cursor: 'pointer', fontSize: '0.9rem', color: 'var(--muted)' }}>
                    Package format reference (click to expand)
                </summary>
                <p style={{ margin: '10px 0 0', color: 'var(--muted)', fontSize: '0.88rem' }}>
                    <strong>Package format:</strong> a <code>.zip</code> with <code>exam.xlsx</code> at the root and an
                    optional <code>media/</code> folder, or a <code>.json</code> export. The xlsx needs a{' '}
                    <strong>Metadata</strong> sheet (key-value: Exam Code, Name, Duration, Passing Grade, Instructions,
                    optional <em>Exam Mode</em> / <em>Shuffle Questions</em> / <em>Shuffle Options</em>) and a{' '}
                    <strong>Questions</strong> sheet (columns: Position, Type, Topic, Points, Prompt, Option A–D, Correct
                    Answer, Explanation, Media File). Type ∈{' '}
                    <code>single_choice | multi_select | short_text | numeric</code>. Exam Mode ∈{' '}
                    <code>strict | try_out</code>.
                </p>
            </details>

            <section className="admin-panel">
                {exams.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>
                        No exams yet. Click Create exam or Import package to add one.
                    </p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Exam</th>
                                <th>Owner</th>
                                <th>Code</th>
                                <th>Active tokens</th>
                                <th>Duration</th>
                                <th>Passing</th>
                                <th>Submissions</th>
                                <th>Avg %</th>
                                <th>Passed</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {exams.map((exam) => (
                                <tr key={exam.examDatabaseId}>
                                    <td>
                                        <Link
                                            href={`/admin/exams/${exam.examId}`}
                                            style={{ color: 'var(--blue)', fontWeight: 700 }}
                                        >
                                            {exam.name}
                                        </Link>
                                        {!exam.active ? (
                                            <div>
                                                <span className="status-item warning">Inactive</span>
                                            </div>
                                        ) : null}
                                    </td>
                                    <td style={{ color: 'var(--muted)' }}>{exam.ownerTeacherName ?? '—'}</td>
                                    <td>
                                        <code>{exam.examId}</code>
                                    </td>
                                    <td>
                                        {exam.activeTokens.length === 0 ? (
                                            <span className="token-pill is-empty">
                                                <KeyRound size={13} aria-hidden /> 0
                                            </span>
                                        ) : (
                                            <div className="token-stack">
                                                {exam.activeTokens.map((t) => (
                                                    <span
                                                        key={t.id}
                                                        className="token-pill"
                                                        title={`${t.usedCount}/${t.maxUses} uses${
                                                            t.expiresAt
                                                                ? ` · expires ${new Date(t.expiresAt).toLocaleDateString()}`
                                                                : ' · no expiry'
                                                        }`}
                                                    >
                                                        <KeyRound size={13} aria-hidden />
                                                        <code>{t.tokenPreview}</code>
                                                        <span style={{ color: 'var(--muted)', fontSize: '0.75rem' }}>
                                                            {t.usedCount}/{t.maxUses}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            className="token-pill-action"
                                                            onClick={() => onRegenerateToken(t)}
                                                            disabled={busyTokenId === t.id}
                                                            title="Regenerate this token"
                                                            aria-label={`Regenerate token ${t.tokenPreview}`}
                                                        >
                                                            <RefreshCw size={11} aria-hidden />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="token-pill-action danger"
                                                            onClick={() => onDeleteToken(t)}
                                                            disabled={busyTokenId === t.id}
                                                            title="Delete this token"
                                                            aria-label={`Delete token ${t.tokenPreview}`}
                                                        >
                                                            <X size={11} aria-hidden />
                                                        </button>
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td>{exam.durationMinutes} min</td>
                                    <td>{exam.passingGrade}%</td>
                                    <td>{exam.totalSubmissions}</td>
                                    <td>{exam.averagePercent === null ? '—' : `${exam.averagePercent}%`}</td>
                                    <td>{exam.passedCount}</td>
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
                                            <button
                                                type="button"
                                                className="ghost-button danger"
                                                onClick={() => onDeleteExam(exam)}
                                                disabled={deleteBusyId === exam.examDatabaseId}
                                                title="Delete this exam (and all questions, tokens, submissions)"
                                                style={{ width: 'auto' }}
                                            >
                                                <Trash2 size={14} aria-hidden />{' '}
                                                {deleteBusyId === exam.examDatabaseId ? 'Deleting…' : 'Delete'}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AdminShell>
    );
}
