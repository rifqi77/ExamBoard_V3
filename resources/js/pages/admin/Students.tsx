import { Head, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Copy,
    Download,
    KeyRound,
    Power,
    Trash2,
    UserCheck,
    UserRound,
    UserX,
    X,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import AdminShell from '@/components/AdminShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------- types

type StudentSummary = {
    userId: string;
    username: string;
    fullName: string;
    active: boolean;
    totalSubmissions: number;
    lastSubmissionAt: string | null;
    passwordPlain: string | null;
};

type ClassGroup = {
    classId: string | null;
    className: string;
    academicYear: string | null;
    studentCount: number;
    sourceFileName: string | null;
    students: StudentSummary[];
};

type CredRow = { group: string; fullName: string; username: string; password: string; note: string };
type CredsPanel = { heading: string; subtitle: string; rows: CredRow[] };

// ---------------------------------------------------------------- fetch helpers

async function postJson(url: string, body: unknown): Promise<any> {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.error || 'Request failed.');
    return data;
}
async function patchJson(url: string, body: unknown): Promise<any> {
    const res = await fetch(url, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.error || 'Request failed.');
    return data;
}

// Academic year (July→June), mirrors App\Support\AcademicYear.
function currentAcademicYear(now = new Date()): string {
    const y = now.getFullYear();
    const m = now.getMonth() + 1;
    return m >= 7 ? `${y}/${y + 1}` : `${y - 1}/${y}`;
}

function csvEscape(value: string): string {
    return /[",\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}
function buildCsv(rows: CredRow[]): string {
    const header = 'Class,Full name,Username,Password,Source';
    const lines = rows.map((r) => [r.group, r.fullName, r.username, r.password, r.note].map(csvEscape).join(','));
    return [header, ...lines].join('\n');
}

// ---------------------------------------------------------------- page

export default function Students() {
    const { groups: initialGroups } = usePage().props as unknown as { groups: ClassGroup[] };

    const [groups, setGroups] = useState<ClassGroup[]>(initialGroups ?? []);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    // Per-row inline password reset.
    const [resetUserId, setResetUserId] = useState<string | null>(null);
    const [resetPasswordValue, setResetPasswordValue] = useState('');
    const [resetBusy, setResetBusy] = useState(false);

    // Bulk selection.
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkBusy, setBulkBusy] = useState<string | null>(null);

    // Post-reset credentials panel.
    const [creds, setCreds] = useState<CredsPanel | null>(null);
    const [copied, setCopied] = useState(false);

    // Per-class collapse + year filter (persisted).
    const [openClasses, setOpenClasses] = useUIState<string[]>('admin.students.openClasses', []);
    const [yearFilter, setYearFilter] = useUIState<string>('admin.students.yearFilter', currentAcademicYear());

    // ------------------------------------------------------------ refresh

    async function refresh() {
        try {
            const res = await fetch('/admin/students/groups', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            setGroups(data.groups ?? []);
        } catch {
            setError('Could not refresh students.');
        }
    }

    // ------------------------------------------------------------ per-row

    async function onResetPassword(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!resetUserId) return;
        setResetBusy(true);
        setError('');
        setMessage('');
        try {
            await patchJson(`/admin/students/${resetUserId}`, { password: resetPasswordValue });
            setMessage('Password updated.');
            setResetUserId(null);
            setResetPasswordValue('');
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not reset password.');
        } finally {
            setResetBusy(false);
        }
    }

    async function onToggleActive(userId: string, nextActive: boolean) {
        setError('');
        setMessage('');
        try {
            await patchJson(`/admin/students/${userId}`, { active: nextActive });
            setMessage(nextActive ? 'Student reactivated.' : 'Student deactivated.');
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not update student.');
        }
    }

    // ------------------------------------------------------------ bulk

    async function onBulk(action: 'deactivate' | 'activate' | 'reset' | 'delete') {
        const ids = [...selected];
        if (ids.length === 0) return;
        if (
            action === 'delete' &&
            !window.confirm(
                `Permanently delete ${ids.length} student account(s) and ALL their submissions? This cannot be undone.`,
            )
        ) {
            return;
        }
        setBulkBusy(action);
        setError('');
        setMessage('');
        try {
            const result = await postJson('/admin/students/bulk', { action, userIds: ids });
            if (action === 'reset' && Array.isArray(result.credentials)) {
                setCreds({
                    heading: 'Reset passwords',
                    subtitle: `${result.credentials.length} password(s) reset. Save or print now — they are not shown again.`,
                    rows: result.credentials.map((c: any) => ({
                        group: '—',
                        fullName: c.fullName,
                        username: c.username,
                        password: c.password,
                        note: 'reset',
                    })),
                });
            }
            const count = result.updated ?? result.deleted ?? result.reset ?? ids.length;
            const verb =
                action === 'delete'
                    ? 'deleted'
                    : action === 'reset'
                      ? 'reset'
                      : action === 'activate'
                        ? 'reactivated'
                        : 'deactivated';
            setMessage(`${count} student(s) ${verb}${result.skipped > 0 ? ` · ${result.skipped} skipped` : ''}.`);
            setSelected(new Set());
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Bulk action failed.');
        } finally {
            setBulkBusy(null);
        }
    }

    // ------------------------------------------------------------ creds panel actions

    function downloadCredentialsCsv() {
        if (!creds) return;
        const blob = new Blob([buildCsv(creds.rows)], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'student_credentials.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    async function copyCredentials() {
        if (!creds) return;
        try {
            await navigator.clipboard.writeText(buildCsv(creds.rows));
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            setError('Could not copy. Use Download CSV instead.');
        }
    }

    // ------------------------------------------------------------ collapse + selection

    function isClassOpen(key: string): boolean {
        return openClasses.includes(key);
    }
    function toggleClass(key: string): void {
        setOpenClasses(openClasses.includes(key) ? openClasses.filter((k) => k !== key) : [...openClasses, key]);
    }
    function toggleOne(userId: string): void {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(userId)) next.delete(userId);
            else next.add(userId);
            return next;
        });
    }
    function setClassSelected(group: ClassGroup, on: boolean): void {
        setSelected((prev) => {
            const next = new Set(prev);
            for (const s of group.students) {
                if (on) next.add(s.userId);
                else next.delete(s.userId);
            }
            return next;
        });
    }
    function classAllSelected(group: ClassGroup): boolean {
        return group.students.length > 0 && group.students.every((s) => selected.has(s.userId));
    }

    // ------------------------------------------------------------ derived

    const availableYears = useMemo(
        () =>
            Array.from(new Set(groups.map((g) => g.academicYear).filter((y): y is string => Boolean(y)))).sort((a, b) =>
                b.localeCompare(a),
            ),
        [groups],
    );

    const filteredGroups = useMemo(
        () => groups.filter((g) => !yearFilter || g.academicYear === null || g.academicYear === yearFilter),
        [groups, yearFilter],
    );

    const totalStudents = filteredGroups.reduce((sum, g) => sum + g.studentCount, 0);
    const allClassKeys = filteredGroups.map((g) => g.classId ?? 'no-class');
    const allOpen = allClassKeys.length > 0 && allClassKeys.every((k) => openClasses.includes(k));
    const selectedCount = selected.size;

    return (
        <AdminShell>
            <Head title="Admin · Students" />

            <header className="teacher-page-header">
                <div>
                    <h1>Students</h1>
                    <p>
                        {totalStudents} student{totalStudents === 1 ? '' : 's'} across {filteredGroups.length} group
                        {filteredGroups.length === 1 ? '' : 's'}, school-wide. Select students to reset passwords or
                        deactivate them in bulk.
                        {allClassKeys.length > 0 ? (
                            <>
                                {' · '}
                                <button
                                    type="button"
                                    className="inline-link-button"
                                    onClick={() => setOpenClasses(allOpen ? [] : allClassKeys)}
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

            {/* Post-reset credentials panel. */}
            {creds ? (
                <section className="admin-panel credentials-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>{creds.heading}</h2>
                            <p>{creds.subtitle}</p>
                        </div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            <button className="ghost-button" type="button" onClick={copyCredentials}>
                                <Copy size={14} aria-hidden /> {copied ? 'Copied' : 'Copy CSV'}
                            </button>
                            <button className="primary-button" type="button" onClick={downloadCredentialsCsv}>
                                <Download size={15} aria-hidden /> Download CSV
                            </button>
                            <button
                                className="ghost-button"
                                type="button"
                                onClick={() => setCreds(null)}
                                aria-label="Dismiss"
                            >
                                <X size={15} aria-hidden /> Dismiss
                            </button>
                        </div>
                    </div>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="dashboard-table credentials-table">
                            <thead>
                                <tr>
                                    <th>Full name</th>
                                    <th>Username</th>
                                    <th>Password</th>
                                    <th>Source</th>
                                </tr>
                            </thead>
                            <tbody>
                                {creds.rows.map((r, i) => (
                                    <tr key={`${r.username}-${i}`}>
                                        <td>{r.fullName}</td>
                                        <td>
                                            <code>{r.username}</code>
                                        </td>
                                        <td>
                                            <code>{r.password}</code>
                                        </td>
                                        <td>
                                            <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>{r.note}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            ) : null}

            {/* Year filter. */}
            {availableYears.length > 0 ? (
                <div className="year-filter">
                    <label htmlFor="students-year-filter">Academic year:</label>
                    <select
                        id="students-year-filter"
                        value={yearFilter}
                        onChange={(e) => setYearFilter(e.target.value)}
                    >
                        <option value="">All years</option>
                        {availableYears.map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            {/* Sticky bulk-actions bar. */}
            {selectedCount > 0 ? (
                <section className="scores-bulk-bar">
                    <strong>{selectedCount} selected</strong>
                    <div className="scores-bulk-bar-actions">
                        <button
                            className="ghost-button"
                            type="button"
                            onClick={() => onBulk('reset')}
                            disabled={bulkBusy !== null}
                        >
                            <KeyRound size={15} aria-hidden /> {bulkBusy === 'reset' ? 'Resetting…' : 'Reset passwords'}
                        </button>
                        <button
                            className="ghost-button"
                            type="button"
                            onClick={() => onBulk('deactivate')}
                            disabled={bulkBusy !== null}
                        >
                            <UserX size={15} aria-hidden /> {bulkBusy === 'deactivate' ? 'Working…' : 'Deactivate'}
                        </button>
                        <button
                            className="ghost-button"
                            type="button"
                            onClick={() => onBulk('activate')}
                            disabled={bulkBusy !== null}
                        >
                            <UserCheck size={15} aria-hidden /> {bulkBusy === 'activate' ? 'Working…' : 'Reactivate'}
                        </button>
                        <button
                            className="ghost-button danger"
                            type="button"
                            onClick={() => onBulk('delete')}
                            disabled={bulkBusy !== null}
                        >
                            <Trash2 size={15} aria-hidden /> {bulkBusy === 'delete' ? 'Deleting…' : 'Delete'}
                        </button>
                        <button className="inline-link-button" type="button" onClick={() => setSelected(new Set())}>
                            Clear selection
                        </button>
                    </div>
                </section>
            ) : null}

            {/* Class roster (collapsible). */}
            {filteredGroups.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)' }}>
                        {groups.length > 0 ? 'No classes match this year filter.' : 'No students yet.'}
                    </p>
                </section>
            ) : (
                filteredGroups.map((group) => {
                    const classKey = group.classId ?? 'no-class';
                    const open = isClassOpen(classKey);
                    return (
                        <section key={classKey} className="admin-panel class-panel">
                            <div className="class-panel-header" aria-expanded={open}>
                                <button
                                    type="button"
                                    className="class-panel-chevron-button"
                                    aria-label={open ? 'Collapse' : 'Expand'}
                                    onClick={() => toggleClass(classKey)}
                                    style={{
                                        background: 'none',
                                        border: 'none',
                                        cursor: 'pointer',
                                        display: 'flex',
                                        alignItems: 'center',
                                        padding: 0,
                                    }}
                                >
                                    {open ? (
                                        <ChevronDown size={16} aria-hidden className="class-panel-chevron" />
                                    ) : (
                                        <ChevronRight size={16} aria-hidden className="class-panel-chevron" />
                                    )}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => toggleClass(classKey)}
                                    style={{
                                        background: 'none',
                                        border: 'none',
                                        cursor: 'pointer',
                                        textAlign: 'left',
                                        flex: 1,
                                        padding: 0,
                                    }}
                                >
                                    <h2>
                                        {group.className}
                                        {group.academicYear ? (
                                            <>
                                                {' '}
                                                <span className="muted">· {group.academicYear}</span>
                                            </>
                                        ) : null}
                                    </h2>
                                    <p>
                                        {group.studentCount} student{group.studentCount === 1 ? '' : 's'}
                                        {group.sourceFileName ? ` · from ${group.sourceFileName}` : ''}
                                    </p>
                                </button>
                                {group.students.length > 0 ? (
                                    <label style={{ display: 'flex', alignItems: 'center', gap: 6, flexShrink: 0 }}>
                                        <input
                                            type="checkbox"
                                            checked={classAllSelected(group)}
                                            onChange={(e) => setClassSelected(group, e.target.checked)}
                                        />
                                        <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>Select all</span>
                                    </label>
                                ) : null}
                            </div>
                            {open ? (
                                <ul className="student-list">
                                    {group.students.map((student) => {
                                        const editingReset = resetUserId === student.userId;
                                        const inactive = !student.active;
                                        return (
                                            <li
                                                key={student.userId}
                                                className={`student-row${inactive ? ' is-inactive' : ''}`}
                                            >
                                                <div className="student-row-main">
                                                    <input
                                                        type="checkbox"
                                                        checked={selected.has(student.userId)}
                                                        onChange={() => toggleOne(student.userId)}
                                                        aria-label={`Select ${student.fullName}`}
                                                    />
                                                    <UserRound size={16} aria-hidden />
                                                    <div className="student-row-text">
                                                        <strong className="student-row-name">{student.fullName}</strong>
                                                        <span className="student-row-username">
                                                            <code>{student.username}</code>
                                                        </span>
                                                        <span className="student-row-password">
                                                            {student.passwordPlain === null ? (
                                                                <span style={{ color: 'var(--muted)' }}>
                                                                    <KeyRound size={11} aria-hidden /> — (reset to view)
                                                                </span>
                                                            ) : (
                                                                <>
                                                                    <KeyRound size={11} aria-hidden />
                                                                    <code>{student.passwordPlain}</code>
                                                                    <button
                                                                        type="button"
                                                                        className="ghost-button icon-only"
                                                                        title="Copy password"
                                                                        onClick={() =>
                                                                            navigator.clipboard.writeText(
                                                                                student.passwordPlain!,
                                                                            )
                                                                        }
                                                                        style={{ padding: 2, minHeight: 0 }}
                                                                    >
                                                                        <Copy size={11} aria-hidden />
                                                                    </button>
                                                                </>
                                                            )}
                                                        </span>
                                                        <span className="student-row-submissions">
                                                            {student.totalSubmissions} submission
                                                            {student.totalSubmissions === 1 ? '' : 's'}
                                                        </span>
                                                    </div>
                                                </div>
                                                <div className="student-row-actions row-actions">
                                                    {editingReset ? (
                                                        <form className="inline-reset-form" onSubmit={onResetPassword}>
                                                            <input
                                                                type="text"
                                                                value={resetPasswordValue}
                                                                onChange={(e) => setResetPasswordValue(e.target.value)}
                                                                placeholder="New password"
                                                                required
                                                                autoFocus
                                                            />
                                                            <button
                                                                className="primary-button"
                                                                type="submit"
                                                                disabled={resetBusy}
                                                                style={{ padding: '6px 12px' }}
                                                            >
                                                                Save
                                                            </button>
                                                            <button
                                                                className="ghost-button"
                                                                type="button"
                                                                onClick={() => {
                                                                    setResetUserId(null);
                                                                    setResetPasswordValue('');
                                                                }}
                                                                style={{ padding: '6px 10px' }}
                                                            >
                                                                Cancel
                                                            </button>
                                                        </form>
                                                    ) : (
                                                        <>
                                                            <button
                                                                className="ghost-button icon-only"
                                                                type="button"
                                                                title="Reset password"
                                                                aria-label="Reset password"
                                                                onClick={() => {
                                                                    setResetUserId(student.userId);
                                                                    setResetPasswordValue('');
                                                                }}
                                                            >
                                                                <KeyRound size={14} aria-hidden />
                                                            </button>
                                                            <button
                                                                className="ghost-button icon-only"
                                                                type="button"
                                                                title={student.active ? 'Disable' : 'Enable'}
                                                                aria-label={student.active ? 'Disable' : 'Enable'}
                                                                onClick={() =>
                                                                    onToggleActive(student.userId, !student.active)
                                                                }
                                                            >
                                                                <Power size={14} aria-hidden />
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : null}
                        </section>
                    );
                })
            )}
        </AdminShell>
    );
}
