import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CircleX,
    Copy,
    Eye,
    KeyRound,
    Plus,
    Power,
    Save,
    Settings,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import AdminShell from '@/components/AdminShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------- types

type CapabilityMap = Record<string, boolean>;

type TeacherSummary = {
    userId: string;
    username: string;
    fullName: string;
    subject: string | null;
    active: boolean;
    examCount: number;
    studentCount: number;
    bankQuestionCount: number;
    submissionCount: number;
    capabilities: CapabilityMap;
};

type CapabilityEntry = {
    key: string;
    group: string;
    subgroup: string | null;
    label: string;
    description: string | null;
    entryKind: string;
};

type CapabilityGroup = { group: string; label: string; entries: CapabilityEntry[] };

type PageProps = {
    teachers: TeacherSummary[];
    subjects: string[];
    capabilityGroups: CapabilityGroup[];
    capabilityKeys: string[];
    capabilitySubgroupLabels: Record<string, string>;
};

const OTHER_SUBJECT_VALUE = '__other__';
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

// ---------------------------------------------------------------- fetch helpers

// Same-origin JSON fetch. CSRF is Origin/Referer-based; the session cookie
// rides along automatically.
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

// ---------------------------------------------------------------- SubjectPicker

// Inline port of the original SubjectPicker. Curated defaults arrive merged
// with in-use subjects via the `choices` prop. Picking "Other…" reveals an
// ALL-CAPS free-text input.
function SubjectPicker({
    value,
    onChange,
    choices,
    required = false,
}: {
    value: string;
    onChange: (next: string) => void;
    choices: string[];
    required?: boolean;
}) {
    const [isOther, setIsOther] = useState(false);
    const showOtherInput = isOther || (value.length > 0 && !choices.includes(value));

    return (
        <>
            <select
                value={showOtherInput ? OTHER_SUBJECT_VALUE : value || ''}
                required={required}
                onChange={(event) => {
                    const v = event.target.value;
                    if (v === OTHER_SUBJECT_VALUE) {
                        setIsOther(true);
                        onChange('');
                    } else {
                        setIsOther(false);
                        onChange(v);
                    }
                }}
            >
                <option value="">— choose a subject —</option>
                {choices.map((s) => (
                    <option key={s} value={s}>
                        {s}
                    </option>
                ))}
                <option value={OTHER_SUBJECT_VALUE}>Other…</option>
            </select>
            {showOtherInput ? (
                <input
                    value={value}
                    onChange={(event) => onChange(event.target.value.toUpperCase())}
                    placeholder="E.G. ASTRONOMY / ASTRONOMI"
                    style={{ textTransform: 'uppercase', marginTop: 4 }}
                    autoFocus
                />
            ) : null}
        </>
    );
}

// ---------------------------------------------------------------- page

export default function Teachers() {
    const props = usePage().props as unknown as PageProps;

    const [teachers, setTeachers] = useState<TeacherSummary[]>(props.teachers ?? []);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    // Add-teacher form.
    const [showCreate, setShowCreate] = useState(false);
    const [newFullName, setNewFullName] = useState('');
    const [newUsername, setNewUsername] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [newSubject, setNewSubject] = useState('');
    const [creating, setCreating] = useState(false);

    // Per-row password reset.
    const [resettingUserId, setResettingUserId] = useState<string | null>(null);
    const [resetValue, setResetValue] = useState('');
    const [resetBusy, setResetBusy] = useState(false);
    // Revealed plaintext per teacher (populated from the reset response).
    const [revealed, setRevealed] = useState<Record<string, string>>({});

    // Capabilities editor takeover.
    const [editingCapsFor, setEditingCapsFor] = useState<string | null>(null);

    // Filters.
    const [letterFilter, setLetterFilter] = useUIState('admin.teachers.letterFilter', '');
    const [subjectFilter, setSubjectFilter] = useUIState('admin.teachers.subjectFilter', '');

    // ------------------------------------------------------------ refresh

    async function refresh() {
        try {
            const res = await fetch('/admin/teachers?json=1', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            setTeachers(data.teachers ?? []);
        } catch {
            setError('Could not refresh teachers.');
        }
    }

    // ------------------------------------------------------------ derived

    // Active first, then alphabetical by full name within each group.
    const sortedTeachers = useMemo(
        () =>
            teachers.slice().sort((a, b) => {
                if (a.active !== b.active) return a.active ? -1 : 1;
                return a.fullName.localeCompare(b.fullName);
            }),
        [teachers],
    );

    // Letters that actually have a teacher — empty ones get greyed out.
    const lettersWithTeachers = useMemo(() => {
        const set = new Set<string>();
        for (const t of teachers) {
            const first = t.fullName.trim().charAt(0).toUpperCase();
            if (first >= 'A' && first <= 'Z') set.add(first);
        }
        return set;
    }, [teachers]);

    // Distinct subjects in use + whether any teacher has none.
    const subjectOptions = useMemo(() => {
        const set = new Set<string>();
        let hasUnset = false;
        for (const t of teachers) {
            const s = t.subject?.trim() ?? '';
            if (s) set.add(s);
            else hasUnset = true;
        }
        return { subjects: Array.from(set).sort((a, b) => a.localeCompare(b)), hasUnset };
    }, [teachers]);

    // Curated defaults merged with in-use subjects, for the create form picker.
    const subjectChoices = useMemo(() => {
        const set = new Set<string>(props.subjects ?? []);
        for (const t of teachers) {
            const s = t.subject?.trim() ?? '';
            if (s) set.add(s);
        }
        return Array.from(set).sort((a, b) => a.localeCompare(b));
    }, [props.subjects, teachers]);

    const visibleTeachers = useMemo(
        () =>
            sortedTeachers.filter((t) => {
                if (letterFilter) {
                    const first = t.fullName.trim().charAt(0).toUpperCase();
                    if (first !== letterFilter) return false;
                }
                if (subjectFilter) {
                    if (subjectFilter === '__none__') {
                        if ((t.subject ?? '').trim() !== '') return false;
                    } else if ((t.subject ?? '').trim() !== subjectFilter) {
                        return false;
                    }
                }
                return true;
            }),
        [sortedTeachers, letterFilter, subjectFilter],
    );

    // ------------------------------------------------------------ actions

    async function onCreate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setCreating(true);
        setError('');
        setMessage('');
        try {
            await postJson('/admin/teachers', {
                username: newUsername,
                password: newPassword,
                fullName: newFullName,
                subject: newSubject.trim() || null,
            });
            setMessage(`Teacher "${newFullName}" created.`);
            setNewFullName('');
            setNewUsername('');
            setNewPassword('');
            setNewSubject('');
            setShowCreate(false);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not create teacher.');
        } finally {
            setCreating(false);
        }
    }

    async function onResetSave(userId: string) {
        setResetBusy(true);
        setError('');
        setMessage('');
        try {
            const data = await patchJson(`/admin/teachers/${userId}`, { password: resetValue });
            setMessage('Password updated.');
            if (typeof data.passwordPlain === 'string' && data.passwordPlain) {
                setRevealed((prev) => ({ ...prev, [userId]: data.passwordPlain }));
            }
            setResettingUserId(null);
            setResetValue('');
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
            await patchJson(`/admin/teachers/${userId}`, { active: nextActive });
            setMessage(nextActive ? 'Teacher reactivated.' : 'Teacher deactivated.');
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not change status.');
        }
    }

    async function onDeleteTeacher(userId: string, fullName: string) {
        if (
            !window.confirm(
                `Delete teacher "${fullName}"? Their account and password will be removed. Students, exams, and bank questions they created will stay (orphaned).`,
            )
        ) {
            return;
        }
        setError('');
        setMessage('');
        try {
            await deleteJson(`/admin/teachers/${userId}`);
            setMessage(`Deleted teacher "${fullName}".`);
            await refresh();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not delete teacher.');
        }
    }

    function onViewAs(userId: string) {
        setError('');
        // Server mints an impersonation JWT and redirects to /teacher.
        router.post(`/admin/impersonate/${userId}`);
    }

    // Capabilities editor takes over the page when a teacher is selected.
    if (editingCapsFor) {
        const target = teachers.find((t) => t.userId === editingCapsFor) ?? null;
        if (target) {
            return (
                <AdminShell>
                    <Head title={`Admin · Capabilities — ${target.fullName}`} />
                    <CapabilitiesEditor
                        teacher={target}
                        groups={props.capabilityGroups}
                        keys={props.capabilityKeys}
                        subgroupLabels={props.capabilitySubgroupLabels}
                        onClose={() => setEditingCapsFor(null)}
                        onSaved={async (caps) => {
                            setTeachers((prev) =>
                                prev.map((t) => (t.userId === target.userId ? { ...t, capabilities: caps } : t)),
                            );
                            setMessage(`Capabilities updated for ${target.fullName}.`);
                        }}
                    />
                </AdminShell>
            );
        }
    }

    return (
        <AdminShell>
            <Head title="Admin · Teachers" />

            <header className="teacher-page-header">
                <div>
                    <h1>Teachers</h1>
                    <p>
                        {teachers.length} teacher account{teachers.length === 1 ? '' : 's'}. Create new accounts and
                        manage access.
                    </p>
                </div>
                <button className="primary-button" type="button" onClick={() => setShowCreate((v) => !v)}>
                    <Plus size={17} aria-hidden />
                    {showCreate ? 'Cancel' : 'Add teacher'}
                </button>
            </header>

            {error ? <p className="form-error">{error}</p> : null}
            {message ? <p className="form-success">{message}</p> : null}

            {showCreate ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>New teacher</h2>
                            <p>Pick a username and starting password. The teacher can change it later.</p>
                        </div>
                    </div>
                    <form className="question-form" onSubmit={onCreate}>
                        <div className="question-form-row">
                            <label>
                                Full name
                                <input
                                    value={newFullName}
                                    onChange={(event) => setNewFullName(event.target.value.toUpperCase())}
                                    placeholder="E.G. SARI WIJAYA"
                                    style={{ textTransform: 'uppercase' }}
                                    required
                                />
                            </label>
                            <label>
                                Username
                                <input
                                    value={newUsername}
                                    onChange={(event) => setNewUsername(event.target.value.toUpperCase())}
                                    placeholder="E.G. TEACHER3"
                                    style={{ textTransform: 'uppercase' }}
                                    required
                                />
                            </label>
                            <label>
                                Subject (optional)
                                <SubjectPicker value={newSubject} onChange={setNewSubject} choices={subjectChoices} />
                            </label>
                        </div>
                        <label>
                            Starting password
                            <input
                                type="text"
                                value={newPassword}
                                onChange={(event) => setNewPassword(event.target.value)}
                                placeholder="6–64 characters"
                                required
                            />
                        </label>
                        <div className="question-form-actions">
                            <button className="primary-button" type="submit" disabled={creating}>
                                <Plus size={17} aria-hidden />
                                {creating ? 'Creating…' : 'Create teacher'}
                            </button>
                        </div>
                    </form>
                </section>
            ) : null}

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>All teachers</h2>
                        <p>
                            Sorted alphabetically; disabled accounts sink to the bottom. Reset a password to give a
                            teacher a fresh credential (the new password is shown once, inline). Deactivate to block
                            sign-in without removing data. Delete only when the account is truly unused — students and
                            exams they created stay behind (orphaned).
                        </p>
                    </div>
                </div>

                {teachers.length === 0 ? (
                    <p style={{ color: 'var(--muted)' }}>No teachers yet. Add the first one above.</p>
                ) : (
                    <>
                        <div
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: 16,
                                alignItems: 'flex-start',
                                marginBottom: 14,
                                paddingBottom: 12,
                                borderBottom: '1px solid var(--border, #e2e6ee)',
                            }}
                        >
                            <div style={{ flex: '1 1 320px', minWidth: 260 }}>
                                <div
                                    style={{
                                        fontSize: '0.78rem',
                                        color: 'var(--muted)',
                                        marginBottom: 6,
                                        fontWeight: 600,
                                        letterSpacing: '0.02em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    Filter by initial
                                </div>
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                                    <button
                                        type="button"
                                        onClick={() => setLetterFilter('')}
                                        className={letterFilter === '' ? 'primary-button' : 'ghost-button'}
                                        style={{ padding: '4px 10px', fontSize: '0.78rem', minWidth: 36 }}
                                    >
                                        All
                                    </button>
                                    {ALPHABET.map((letter) => {
                                        const has = lettersWithTeachers.has(letter);
                                        const active = letterFilter === letter;
                                        return (
                                            <button
                                                key={letter}
                                                type="button"
                                                onClick={() => setLetterFilter(active ? '' : letter)}
                                                disabled={!has && !active}
                                                className={active ? 'primary-button' : 'ghost-button'}
                                                style={{
                                                    padding: '4px 8px',
                                                    fontSize: '0.78rem',
                                                    minWidth: 28,
                                                    opacity: has || active ? 1 : 0.4,
                                                    cursor: has || active ? 'pointer' : 'not-allowed',
                                                }}
                                            >
                                                {letter}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div style={{ flex: '0 0 auto', minWidth: 200 }}>
                                <div
                                    style={{
                                        fontSize: '0.78rem',
                                        color: 'var(--muted)',
                                        marginBottom: 6,
                                        fontWeight: 600,
                                        letterSpacing: '0.02em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    Filter by subject
                                </div>
                                <select
                                    value={subjectFilter}
                                    onChange={(event) => setSubjectFilter(event.target.value)}
                                    style={{ padding: '6px 10px', fontSize: '0.85rem', width: '100%', minWidth: 200 }}
                                >
                                    <option value="">All subjects</option>
                                    {subjectOptions.subjects.map((s) => (
                                        <option key={s} value={s}>
                                            {s}
                                        </option>
                                    ))}
                                    {subjectOptions.hasUnset ? <option value="__none__">— (no subject)</option> : null}
                                </select>
                            </div>

                            {letterFilter || subjectFilter ? (
                                <div style={{ alignSelf: 'flex-end' }}>
                                    <button
                                        type="button"
                                        className="ghost-button"
                                        onClick={() => {
                                            setLetterFilter('');
                                            setSubjectFilter('');
                                        }}
                                        style={{ padding: '6px 12px', fontSize: '0.8rem' }}
                                    >
                                        Clear filters
                                    </button>
                                </div>
                            ) : null}
                        </div>

                        {visibleTeachers.length === 0 ? (
                            <p style={{ color: 'var(--muted)' }}>No teachers match the current filters.</p>
                        ) : (
                            <table className="dashboard-table dashboard-table--compact">
                                <thead>
                                    <tr>
                                        <th>Teacher</th>
                                        <th>Subject</th>
                                        <th>Activity</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visibleTeachers.map((teacher) => {
                                        const reveal = revealed[teacher.userId];
                                        return (
                                            <tr key={teacher.userId}>
                                                <td>
                                                    <strong>{teacher.fullName}</strong>
                                                    <span
                                                        style={{
                                                            color: 'var(--muted)',
                                                            fontSize: '0.85rem',
                                                            marginLeft: 6,
                                                        }}
                                                    >
                                                        ({teacher.username})
                                                    </span>
                                                    {reveal ? (
                                                        <div className="student-row-password" style={{ marginTop: 4 }}>
                                                            <KeyRound size={11} aria-hidden />
                                                            <code>{reveal}</code>
                                                            <button
                                                                type="button"
                                                                className="ghost-button icon-only"
                                                                title="Copy password"
                                                                onClick={() => navigator.clipboard.writeText(reveal)}
                                                                style={{ padding: 2, minHeight: 0 }}
                                                            >
                                                                <Copy size={11} aria-hidden />
                                                            </button>
                                                        </div>
                                                    ) : null}
                                                </td>
                                                <td>
                                                    {teacher.subject ? (
                                                        teacher.subject
                                                    ) : (
                                                        <span style={{ color: 'var(--muted)' }}>—</span>
                                                    )}
                                                </td>
                                                <td style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>
                                                    <span title="Exams">{teacher.examCount} exams</span>
                                                    {' · '}
                                                    <span title="Students">{teacher.studentCount} students</span>
                                                    {' · '}
                                                    <span title="Bank questions">{teacher.bankQuestionCount} bank</span>
                                                    {' · '}
                                                    <span title="Submissions">{teacher.submissionCount} subs</span>
                                                </td>
                                                <td>
                                                    {teacher.active ? (
                                                        <span className="status-item neutral">
                                                            <CheckCircle2 size={14} aria-hidden /> Active
                                                        </span>
                                                    ) : (
                                                        <span className="status-item warning">
                                                            <CircleX size={14} aria-hidden /> Disabled
                                                        </span>
                                                    )}
                                                </td>
                                                <td>
                                                    {resettingUserId === teacher.userId ? (
                                                        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                                                            <input
                                                                type="text"
                                                                value={resetValue}
                                                                onChange={(event) => setResetValue(event.target.value)}
                                                                placeholder="New password"
                                                                style={{ width: 140 }}
                                                                autoFocus
                                                            />
                                                            <button
                                                                className="primary-button"
                                                                type="button"
                                                                onClick={() => onResetSave(teacher.userId)}
                                                                disabled={resetBusy}
                                                            >
                                                                {resetBusy ? '…' : 'Save'}
                                                            </button>
                                                            <button
                                                                className="ghost-button"
                                                                type="button"
                                                                onClick={() => {
                                                                    setResettingUserId(null);
                                                                    setResetValue('');
                                                                }}
                                                            >
                                                                Cancel
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <div className="row-actions" style={{ display: 'flex', gap: 4 }}>
                                                            <button
                                                                className="ghost-button icon-only"
                                                                type="button"
                                                                title="View as this teacher"
                                                                aria-label="View as this teacher"
                                                                onClick={() => onViewAs(teacher.userId)}
                                                                disabled={!teacher.active}
                                                            >
                                                                <Eye size={14} aria-hidden />
                                                            </button>
                                                            <button
                                                                className="ghost-button icon-only"
                                                                type="button"
                                                                title="Reset password"
                                                                aria-label="Reset password"
                                                                onClick={() => {
                                                                    setResettingUserId(teacher.userId);
                                                                    setResetValue('');
                                                                }}
                                                            >
                                                                <KeyRound size={14} aria-hidden />
                                                            </button>
                                                            <button
                                                                className="ghost-button icon-only"
                                                                type="button"
                                                                title="Edit capabilities"
                                                                aria-label="Edit capabilities"
                                                                onClick={() => setEditingCapsFor(teacher.userId)}
                                                            >
                                                                <Settings size={14} aria-hidden />
                                                            </button>
                                                            <button
                                                                className="ghost-button icon-only"
                                                                type="button"
                                                                title={teacher.active ? 'Disable' : 'Enable'}
                                                                aria-label={teacher.active ? 'Disable' : 'Enable'}
                                                                onClick={() =>
                                                                    onToggleActive(teacher.userId, !teacher.active)
                                                                }
                                                            >
                                                                <Power size={14} aria-hidden />
                                                            </button>
                                                            <button
                                                                className="ghost-button icon-only danger"
                                                                type="button"
                                                                title="Delete teacher"
                                                                aria-label="Delete teacher"
                                                                onClick={() =>
                                                                    onDeleteTeacher(teacher.userId, teacher.fullName)
                                                                }
                                                            >
                                                                <Trash2 size={14} aria-hidden />
                                                            </button>
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </>
                )}
            </section>
        </AdminShell>
    );
}

// ================================================================ CapabilitiesEditor

function isOn(v: boolean | undefined): boolean {
    return v === true;
}

// Ordered list of subgroups present in a group; [null] when the group has no
// subgroups (so the renderer uses one code path).
function subgroupsIn(entries: CapabilityEntry[]): Array<string | null> {
    const seen: Array<string | null> = [];
    for (const e of entries) {
        if (!seen.includes(e.subgroup)) seen.push(e.subgroup);
    }
    return seen;
}

function CapabilitiesEditor({
    teacher,
    groups,
    keys,
    subgroupLabels,
    onClose,
    onSaved,
}: {
    teacher: TeacherSummary;
    groups: CapabilityGroup[];
    keys: string[];
    subgroupLabels: Record<string, string>;
    onClose: () => void;
    onSaved: (caps: CapabilityMap) => void | Promise<void>;
}) {
    // Seed from the teacher's fully-populated map (every key present as boolean).
    const [caps, setCaps] = useState<CapabilityMap>(() => {
        const seed: CapabilityMap = {};
        for (const k of keys) seed[k] = isOn(teacher.capabilities[k]);
        return seed;
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [showTechIds, setShowTechIds] = useUIState('admin.teachers.capabilities.showTechIds', false);

    function toggle(key: string) {
        setCaps((cur) => ({ ...cur, [key]: !cur[key] }));
    }

    function applyToEntries(enable: boolean, predicate: (e: CapabilityEntry) => boolean) {
        setCaps((cur) => {
            const next = { ...cur };
            for (const group of groups) {
                for (const entry of group.entries) {
                    if (predicate(entry)) next[entry.key] = enable;
                }
            }
            return next;
        });
    }

    const enabledCount = useMemo(() => keys.filter((k) => caps[k]).length, [caps, keys]);

    async function onSave() {
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const data = await patchJson(`/admin/teachers/${teacher.userId}/capabilities`, { capabilities: caps });
            const saved: CapabilityMap = {};
            for (const k of keys) saved[k] = isOn(data.capabilities?.[k]);
            setMessage('Capabilities saved.');
            await onSaved(saved);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Could not save.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <header className="teacher-page-header">
                <div>
                    <h1>Capabilities — {teacher.fullName}</h1>
                    <p>
                        <code>{teacher.username}</code> · {enabledCount} of {keys.length} enabled. Takes effect on the
                        teacher&apos;s next page load.
                    </p>
                </div>
                <button className="ghost-button" type="button" onClick={onClose}>
                    <ArrowLeft size={15} aria-hidden /> Back to teachers
                </button>
            </header>

            {error ? <p className="form-error">{error}</p> : null}
            {message ? <p className="form-success">{message}</p> : null}

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Bulk actions</h2>
                        <p>Enable all: every toggle on. Disable all: every toggle off.</p>
                    </div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        <label
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                fontSize: '0.82rem',
                                color: 'var(--muted)',
                            }}
                        >
                            <input
                                type="checkbox"
                                checked={showTechIds}
                                onChange={(e) => setShowTechIds(e.target.checked)}
                            />
                            Show technical IDs
                        </label>
                        <button className="ghost-button" type="button" onClick={() => applyToEntries(true, () => true)}>
                            Enable all
                        </button>
                        <button className="ghost-button" type="button" onClick={() => applyToEntries(false, () => true)}>
                            Disable all
                        </button>
                    </div>
                </div>
            </section>

            {groups.map((group) => {
                if (group.entries.length === 0) return null;
                const enabledInGroup = group.entries.filter((e) => caps[e.key]).length;
                const subgroups = subgroupsIn(group.entries);
                const hasSubgroups = subgroups.length > 1 || subgroups[0] !== null;
                const isAiGroup = group.group === 'ai' || group.group === 'ai_param';

                return (
                    <section className={isAiGroup ? 'admin-panel admin-panel--ai' : 'admin-panel'} key={group.group}>
                        <div className="section-title-row">
                            <div>
                                <h2 style={isAiGroup ? { display: 'flex', alignItems: 'center', gap: 8 } : undefined}>
                                    {isAiGroup ? (
                                        <Sparkles size={18} aria-hidden style={{ color: 'var(--ai-accent, #4f46e5)' }} />
                                    ) : null}
                                    {group.label}
                                </h2>
                                <p>
                                    {enabledInGroup} of {group.entries.length} enabled.
                                </p>
                            </div>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button
                                    className="ghost-button"
                                    type="button"
                                    onClick={() => applyToEntries(true, (e) => e.group === group.group)}
                                >
                                    Enable all
                                </button>
                                <button
                                    className="ghost-button"
                                    type="button"
                                    onClick={() => applyToEntries(false, (e) => e.group === group.group)}
                                >
                                    Disable all
                                </button>
                            </div>
                        </div>

                        {subgroups.map((sg) => {
                            const subEntries = group.entries.filter((e) => e.subgroup === sg);
                            if (subEntries.length === 0) return null;
                            const enabledInSub = subEntries.filter((e) => caps[e.key]).length;
                            return (
                                <div key={sg ?? '_'} style={{ marginTop: sg ? 14 : 0 }}>
                                    {hasSubgroups && sg ? (
                                        <div
                                            style={{
                                                display: 'flex',
                                                alignItems: 'baseline',
                                                justifyContent: 'space-between',
                                                marginBottom: 6,
                                                paddingBottom: 4,
                                                borderBottom: '1px solid var(--border, rgba(0,0,0,0.08))',
                                            }}
                                        >
                                            <strong
                                                style={{
                                                    fontSize: '0.78rem',
                                                    textTransform: 'uppercase',
                                                    letterSpacing: '0.05em',
                                                    color: 'var(--muted)',
                                                }}
                                            >
                                                {subgroupLabels[sg] ?? sg}{' '}
                                                <span style={{ fontWeight: 400 }}>
                                                    ({enabledInSub}/{subEntries.length})
                                                </span>
                                            </strong>
                                            <span style={{ display: 'flex', gap: 6 }}>
                                                <button
                                                    type="button"
                                                    className="ghost-button"
                                                    style={{ padding: '1px 8px', fontSize: '0.78rem' }}
                                                    onClick={() =>
                                                        applyToEntries(
                                                            true,
                                                            (e) => e.group === group.group && e.subgroup === sg,
                                                        )
                                                    }
                                                >
                                                    Enable
                                                </button>
                                                <button
                                                    type="button"
                                                    className="ghost-button"
                                                    style={{ padding: '1px 8px', fontSize: '0.78rem' }}
                                                    onClick={() =>
                                                        applyToEntries(
                                                            false,
                                                            (e) => e.group === group.group && e.subgroup === sg,
                                                        )
                                                    }
                                                >
                                                    Disable
                                                </button>
                                            </span>
                                        </div>
                                    ) : null}

                                    <div className="capabilities-grid capabilities-grid--compact">
                                        {subEntries.map((entry) => (
                                            <label
                                                key={entry.key}
                                                className="capabilities-row capabilities-row--compact"
                                                title={entry.description ?? undefined}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={caps[entry.key] === true}
                                                    onChange={() => toggle(entry.key)}
                                                />
                                                <span className="capabilities-row-text">
                                                    <strong>{entry.label}</strong>
                                                    {showTechIds ? <code>{entry.key}</code> : null}
                                                    {entry.description ? <small>{entry.description}</small> : null}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </section>
                );
            })}

            <div style={{ display: 'flex', gap: 10, padding: '0 4px', alignItems: 'center' }}>
                <button className="primary-button" type="button" onClick={onSave} disabled={busy}>
                    <Save size={16} aria-hidden />
                    {busy ? 'Saving…' : 'Save capabilities'}
                </button>
                <button className="ghost-button" type="button" onClick={onClose} disabled={busy}>
                    Close
                </button>
                {message ? (
                    <span
                        style={{
                            color: 'var(--success, #0a7c2f)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 4,
                        }}
                    >
                        <CheckCircle2 size={14} aria-hidden /> {message}
                    </span>
                ) : null}
            </div>
        </>
    );
}
