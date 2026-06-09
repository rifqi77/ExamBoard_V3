import { Head, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Copy,
    Download,
    KeyRound,
    Plus,
    Power,
    Trash2,
    Upload,
    UserCheck,
    UserRound,
    UserX,
    Users,
    X,
} from 'lucide-react';
import { ChangeEvent, FormEvent, ReactNode, useMemo, useRef, useState } from 'react';
import TeacherShell from '@/components/TeacherShell';
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

type ParsedClass = {
    name: string;
    students: { fullName: string; username: string; password: string }[];
};

type CreatedCredential = {
    className: string;
    fullName: string;
    username: string;
    password: string;
    passwordWasGenerated: boolean;
};

type CredsPanel = { heading: string; subtitle: string; rows: CreatedCredential[] };

// ---------------------------------------------------------------- helpers

// Same-origin JSON fetch. CSRF is Origin/Referer-based (no token needed) and
// the session cookie rides along automatically.
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

async function deleteJson(url: string): Promise<any> {
    const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json' } });
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
function parseAcademicYear(input: string): string | null {
    const m = input.trim().match(/^(\d{4})\s*\/\s*(\d{4})$/);
    if (!m) return null;
    const a = Number(m[1]);
    const b = Number(m[2]);
    if (b !== a + 1) return null;
    return `${a}/${b}`;
}

function csvEscape(value: string): string {
    return /[",\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}
function buildCsv(rows: CreatedCredential[]): string {
    const header = 'Class,Full name,Username,Password,Password source';
    const lines = rows.map((r) =>
        [r.className, r.fullName, r.username, r.password, r.passwordWasGenerated ? 'generated' : 'from file']
            .map(csvEscape)
            .join(','),
    );
    return [header, ...lines].join('\n');
}

// ---------------------------------------------------------------- page

export default function Students() {
    const { groups: initialGroups } = usePage().props as any;

    const [groups, setGroups] = useState<ClassGroup[]>(initialGroups ?? []);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    // Add-student form.
    const [showAdd, setShowAdd] = useState(false);
    const [newUsername, setNewUsername] = useState('');
    const [newFullName, setNewFullName] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [addBusy, setAddBusy] = useState(false);

    // Per-row inline password reset.
    const [resetUserId, setResetUserId] = useState<string | null>(null);
    const [resetPasswordValue, setResetPasswordValue] = useState('');
    const [resetBusy, setResetBusy] = useState(false);

    // Bulk selection.
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkBusy, setBulkBusy] = useState<string | null>(null);

    // Bulk create from pasted roster.
    const [showBulkCreate, setShowBulkCreate] = useState(false);
    const [rosterText, setRosterText] = useState('');
    const [rosterClassId, setRosterClassId] = useState<string>('');
    const [bulkCreateBusy, setBulkCreateBusy] = useState(false);

    // Per-class collapse (default closed → compact page).
    const [openClasses, setOpenClasses] = useUIState<string[]>('teacher.students.openClasses', []);

    // Excel/CSV upload + preview + credentials panel.
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [parsedPreview, setParsedPreview] = useState<{ fileName: string; classes: ParsedClass[] } | null>(null);
    const [parseBusy, setParseBusy] = useState(false);
    const [importBusy, setImportBusy] = useState(false);
    const [creds, setCreds] = useState<CredsPanel | null>(null);
    const [copied, setCopied] = useState(false);

    const [importYear, setImportYear] = useUIState<string>('teacher.importYear', currentAcademicYear());
    const [yearFilter, setYearFilter] = useUIState<string>('teacher.students.yearFilter', currentAcademicYear());

    // ------------------------------------------------------------ refresh

    async function refresh() {
        try {
            const res = await fetch('/teacher/students/groups', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            setGroups(data.groups ?? []);
        } catch {
            setError('Could not refresh students.');
        }
    }

    // ------------------------------------------------------------ add one

    async function onAddStudent(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setAddBusy(true);
        setError('');
        setMessage('');
        try {
            const data = await postJson('/teacher/students', {
                username: newUsername,
                password: newPassword,
                fullName: newFullName,
            });
            setMessage(`Added ${data.student.fullName} (${data.student.username}).`);
            setNewUsername('');
            setNewFullName('');
            setNewPassword('');
            setShowAdd(false);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not add student.');
        } finally {
            setAddBusy(false);
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
            await patchJson(`/teacher/students/${resetUserId}`, { password: resetPasswordValue });
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
            await patchJson(`/teacher/students/${userId}`, { active: nextActive });
            setMessage(nextActive ? 'Student reactivated.' : 'Student deactivated.');
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not update student.');
        }
    }

    async function onDeleteStudent(userId: string, fullName: string) {
        if (!window.confirm(`Delete ${fullName}? Their submissions and drafts will also be removed.`)) return;
        setError('');
        setMessage('');
        try {
            await deleteJson(`/teacher/students/${userId}`);
            setMessage(`Deleted ${fullName}.`);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not delete student.');
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
            const result = await postJson('/teacher/students/bulk', { action, userIds: ids });
            if (action === 'reset' && Array.isArray(result.credentials)) {
                setCreds({
                    heading: 'Reset passwords',
                    subtitle: `${result.credentials.length} password(s) reset. Save or print now — they are not shown again.`,
                    rows: result.credentials.map((c: any) => ({
                        className: '—',
                        fullName: c.fullName,
                        username: c.username,
                        password: c.password,
                        passwordWasGenerated: true,
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

    async function onBulkCreate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBulkCreateBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await postJson('/teacher/students/bulk-create', {
                roster: rosterText,
                classId: rosterClassId || null,
            });
            setMessage(
                `Created ${result.studentsCreated} student(s)${
                    result.studentsSkipped.length > 0 ? ` · ${result.studentsSkipped.length} skipped` : ''
                }.`,
            );
            if (Array.isArray(result.createdStudents) && result.createdStudents.length > 0) {
                setCreds({
                    heading: 'New student credentials',
                    subtitle: `${result.createdStudents.length} account(s) created from the pasted roster. Save or print now — passwords are not shown again.`,
                    rows: result.createdStudents,
                });
            }
            setRosterText('');
            setShowBulkCreate(false);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not create students.');
        } finally {
            setBulkCreateBusy(false);
        }
    }

    // ------------------------------------------------------------ import

    async function onFileSelected(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (fileInputRef.current) fileInputRef.current.value = '';
        if (!file) return;
        setParseBusy(true);
        setError('');
        setMessage('');
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res = await fetch('/teacher/classes/parse', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: fd,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data?.error || 'Could not read file.');
            setParsedPreview({ fileName: data.fileName, classes: data.classes });
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not read file.');
        } finally {
            setParseBusy(false);
        }
    }

    async function onConfirmImport() {
        if (!parsedPreview) return;
        const parsedYear = parseAcademicYear(importYear);
        if (!parsedYear) {
            setError('Academic year must be "YYYY/YYYY" with consecutive years, e.g. "2025/2026".');
            return;
        }
        setImportBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await postJson('/teacher/classes/import', {
                fileName: parsedPreview.fileName,
                academicYear: parsedYear,
                classes: parsedPreview.classes,
            });
            const skipped =
                result.studentsSkipped.length > 0
                    ? ` ${result.studentsSkipped.length} skipped (${result.studentsSkipped[0].reason}${
                          result.studentsSkipped.length > 1 ? ' and others' : ''
                      }).`
                    : '';
            setMessage(
                `Imported ${result.studentsCreated} student(s) across ${result.classesCreated} new and ${result.classesUpdated} existing class(es).${skipped}`,
            );
            if (Array.isArray(result.createdStudents) && result.createdStudents.length > 0) {
                setCreds({
                    heading: 'New student credentials',
                    subtitle: `${result.createdStudents.length} student account(s) created from ${parsedPreview.fileName}. Save or print this list now — passwords are not shown again after you close this panel.`,
                    rows: result.createdStudents,
                });
            }
            setParsedPreview(null);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not import.');
        } finally {
            setImportBusy(false);
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

    // Real classes the teacher owns (excludes the synthetic "No class" bucket)
    // — used as the bulk-create target dropdown.
    const realClasses = useMemo(() => groups.filter((g) => g.classId !== null), [groups]);

    const totalStudents = filteredGroups.reduce((sum, g) => sum + g.studentCount, 0);
    const allClassKeys = filteredGroups.map((g) => g.classId ?? 'no-class');
    const allOpen = allClassKeys.length > 0 && allClassKeys.every((k) => openClasses.includes(k));
    const selectedCount = selected.size;

    return (
        <TeacherShell>
            <Head title="Teacher · Students" />

            <header className="teacher-page-header">
                <div>
                    <h1>Students</h1>
                    <p>
                        {totalStudents} student{totalStudents === 1 ? '' : 's'} across {filteredGroups.length} group
                        {filteredGroups.length === 1 ? '' : 's'}. Upload an Excel/CSV file (one sheet per class) to add a
                        whole class at once.
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
                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                    <input ref={fileInputRef} type="file" accept=".xlsx,.csv" hidden onChange={onFileSelected} />
                    <button
                        className="ghost-button"
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={parseBusy}
                    >
                        <Upload size={17} aria-hidden /> {parseBusy ? 'Reading…' : 'Upload Excel'}
                    </button>
                    <button
                        className="ghost-button"
                        type="button"
                        onClick={() => setShowBulkCreate((v) => !v)}
                    >
                        <Users size={17} aria-hidden /> {showBulkCreate ? 'Close' : 'Bulk add'}
                    </button>
                    <button className="primary-button" type="button" onClick={() => setShowAdd((v) => !v)}>
                        <Plus size={17} aria-hidden />
                        {showAdd ? 'Close' : 'Add student'}
                    </button>
                </div>
            </header>

            {error ? <p className="form-error">{error}</p> : null}
            {message ? <p className="form-success">{message}</p> : null}

            {/* Excel/CSV preview + confirm. */}
            {parsedPreview ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Preview: {parsedPreview.fileName}</h2>
                            <p>
                                {parsedPreview.classes.length} class(es),{' '}
                                {parsedPreview.classes.reduce((sum, cls) => sum + cls.students.length, 0)} student(s).
                                Auto-generated usernames/passwords will appear in the imported roster.
                            </p>
                        </div>
                    </div>
                    <ul className="import-preview-list">
                        {parsedPreview.classes.map((cls) => (
                            <li key={cls.name}>
                                <strong>{cls.name}</strong>
                                <span>{cls.students.length} student(s)</span>
                            </li>
                        ))}
                    </ul>
                    <label style={{ marginTop: 12, display: 'block' }}>
                        Academic year
                        <input
                            type="text"
                            value={importYear}
                            onChange={(e) => setImportYear(e.target.value)}
                            placeholder="2025/2026"
                            style={{ maxWidth: 160 }}
                        />
                        <small>
                            Format <code>YYYY/YYYY</code> (consecutive years). Same class name in a different year creates
                            a new roster.
                        </small>
                    </label>
                    <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                        <button className="primary-button" type="button" onClick={onConfirmImport} disabled={importBusy}>
                            {importBusy ? 'Importing…' : 'Confirm import'}
                        </button>
                        <button className="ghost-button" type="button" onClick={() => setParsedPreview(null)}>
                            Cancel
                        </button>
                    </div>
                </section>
            ) : null}

            {/* Post-import / post-reset credentials panel. */}
            {creds ? (
                <section className="admin-panel credentials-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>{creds.heading}</h2>
                            <p>{creds.subtitle}</p>
                        </div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            <button className="ghost-button" type="button" onClick={copyCredentials}>
                                <Copy size={14} aria-hidden />
                                {copied ? 'Copied' : 'Copy CSV'}
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
                            <tbody>{renderCredentialRows(creds.rows)}</tbody>
                        </table>
                    </div>
                </section>
            ) : null}

            {/* Bulk create from a pasted roster. */}
            {showBulkCreate ? (
                <section className="admin-panel">
                    <form className="exam-form" onSubmit={onBulkCreate}>
                        <div className="section-title-row">
                            <div>
                                <h2>Bulk add students</h2>
                                <p>
                                    Paste one student per line. Just the full name auto-generates a username and
                                    password; or use <code>Full Name, username, password</code> (comma or tab separated)
                                    to set your own.
                                </p>
                            </div>
                        </div>
                        <label>
                            Roster
                            <textarea
                                value={rosterText}
                                onChange={(e) => setRosterText(e.target.value)}
                                rows={8}
                                placeholder={'ANDI PRATAMA\nSITI AISYAH, siti.aisyah, rahasia123\nBUDI SANTOSO'}
                                required
                            />
                        </label>
                        <label>
                            Add to class (optional)
                            <select value={rosterClassId} onChange={(e) => setRosterClassId(e.target.value)}>
                                <option value="">No class</option>
                                {realClasses.map((g) => (
                                    <option key={g.classId as string} value={g.classId as string}>
                                        {g.className}
                                        {g.academicYear ? ` · ${g.academicYear}` : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button className="primary-button" type="submit" disabled={bulkCreateBusy}>
                            <Users size={17} aria-hidden />
                            {bulkCreateBusy ? 'Creating…' : 'Create students'}
                        </button>
                    </form>
                </section>
            ) : null}

            {/* Add single student. */}
            {showAdd ? (
                <section className="admin-panel">
                    <form className="exam-form" onSubmit={onAddStudent}>
                        <div className="exam-form-row">
                            <label>
                                Username
                                <input
                                    value={newUsername}
                                    onChange={(e) => setNewUsername(e.target.value.toUpperCase())}
                                    placeholder="SISWA-BARU"
                                    style={{ textTransform: 'uppercase' }}
                                    required
                                    autoFocus
                                />
                                <small>3–32 chars: letters, digits, dots, dashes, underscores.</small>
                            </label>
                            <label>
                                Full name
                                <input
                                    value={newFullName}
                                    onChange={(e) => setNewFullName(e.target.value.toUpperCase())}
                                    placeholder="ANDI PRATAMA"
                                    style={{ textTransform: 'uppercase' }}
                                    required
                                />
                            </label>
                            <label>
                                Password
                                <input
                                    type="text"
                                    value={newPassword}
                                    onChange={(e) => setNewPassword(e.target.value)}
                                    placeholder="At least 6 characters"
                                    required
                                />
                            </label>
                        </div>
                        <button className="primary-button" type="submit" disabled={addBusy}>
                            <Plus size={17} aria-hidden />
                            {addBusy ? 'Adding…' : 'Add student'}
                        </button>
                    </form>
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
                        <button
                            className="inline-link-button"
                            type="button"
                            onClick={() => setSelected(new Set())}
                        >
                            Clear selection
                        </button>
                    </div>
                </section>
            ) : null}

            {/* Class roster (collapsible). */}
            {filteredGroups.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)' }}>
                        {groups.length > 0
                            ? 'No classes match this year filter.'
                            : 'No students yet. Upload an Excel/CSV file or click Add student.'}
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
                                                            <button
                                                                className="ghost-button icon-only danger"
                                                                type="button"
                                                                title="Delete student"
                                                                aria-label="Delete student"
                                                                onClick={() =>
                                                                    onDeleteStudent(student.userId, student.fullName)
                                                                }
                                                            >
                                                                <Trash2 size={14} aria-hidden />
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
        </TeacherShell>
    );
}

// Group credential rows by class so the class name appears once as a section
// header instead of repeating on every row.
function renderCredentialRows(rows: CreatedCredential[]): ReactNode[] {
    const byClass = new Map<string, CreatedCredential[]>();
    for (const r of rows) {
        const arr = byClass.get(r.className) ?? [];
        arr.push(r);
        byClass.set(r.className, arr);
    }
    const rendered: ReactNode[] = [];
    for (const [className, students] of byClass) {
        rendered.push(
            <tr key={`__hdr__${className}`} className="credentials-class-header">
                <th colSpan={4} scope="colgroup">
                    {className}{' '}
                    <span className="credentials-class-count">
                        {students.length} student{students.length === 1 ? '' : 's'}
                    </span>
                </th>
            </tr>,
        );
        for (const s of students) {
            rendered.push(
                <tr key={`${s.className}-${s.username}`}>
                    <td>{s.fullName}</td>
                    <td>
                        <code>{s.username}</code>
                    </td>
                    <td>
                        <code>{s.password}</code>
                    </td>
                    <td>
                        <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>
                            {s.passwordWasGenerated ? 'auto-generated' : 'from file'}
                        </span>
                    </td>
                </tr>,
            );
        }
    }
    return rendered;
}
