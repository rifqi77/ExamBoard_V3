import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Filter, GraduationCap, Pencil, Plus, Trash2, Upload, X } from 'lucide-react';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import TeacherShell from '@/components/TeacherShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------------------
// Teacher Curriculum (Learning Objectives) — port of LearningObjectivesClient.
//
// Four curriculum tabs, each with its own Excel upload + catalog. The Excel is
// parsed SERVER-SIDE (LearningObjectiveController@upload phase 1) and returned
// as a `preview` prop; the teacher confirms to import (phase 2). The catalog is
// a collapsible topic → subtopic → LO tree with inline add / edit / delete and
// multi-select bulk delete. Teachers see only LOs they uploaded; admins see all.
// ---------------------------------------------------------------------------

type CurriculumKey = 'kurikulum_merdeka' | 'as_a_level' | 'ib' | 'olympiad';

const CURRICULA: Array<{ key: CurriculumKey; label: string; short: string; hint: string }> = [
    {
        key: 'kurikulum_merdeka',
        label: 'Kurikulum Merdeka',
        short: 'Merdeka',
        hint: 'Indonesian national curriculum (Capaian Pembelajaran / Tujuan Pembelajaran)',
    },
    {
        key: 'as_a_level',
        label: 'AS / A Level (Cambridge)',
        short: 'Cambridge',
        hint: 'Cambridge International AS & A Level syllabus objectives',
    },
    {
        key: 'ib',
        label: 'International Baccalaureate (IB)',
        short: 'IB',
        hint: 'IB Diploma syllabus / assessment objectives',
    },
    {
        key: 'olympiad',
        label: 'Olympiad',
        short: 'Olympiad',
        hint: 'Olympiad / contest-level topic outlines (IPhO, IChO, IMO, KSN, ...)',
    },
];

const OTHER_SUBJECT_VALUE = '__other__';
const NO_SUBTOPIC = '(no subtopic)';

type LoRow = {
    id: string;
    curriculum: CurriculumKey;
    language: string;
    subject: string;
    topic: string;
    subtopic: string | null;
    text: string;
    uploadedBy: string | null;
    uploadedByName: string | null;
    sourceFileName: string | null;
    createdAt: string | null;
};

type ParsedRow = { topic: string; subtopic: string | null; text: string };
type PreviewPayload = { fileName: string; curriculum: CurriculumKey; rows: ParsedRow[]; warnings: string[] };

// --- Inline subject picker (port of SubjectPicker) -------------------------
function SubjectPicker({
    value,
    onChange,
    choices,
    disabled = false,
}: {
    value: string;
    onChange: (next: string) => void;
    choices: string[];
    disabled?: boolean;
}) {
    const [isOther, setIsOther] = useState(false);
    const showOther = isOther || (value.length > 0 && !choices.includes(value));
    return (
        <>
            <select
                value={showOther ? OTHER_SUBJECT_VALUE : value || ''}
                disabled={disabled}
                onChange={(e) => {
                    const v = e.target.value;
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
            {showOther ? (
                <input
                    value={value}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value.toUpperCase())}
                    placeholder="E.G. ASTRONOMY / ASTRONOMI"
                    style={{ textTransform: 'uppercase', marginTop: 4 }}
                    autoFocus
                />
            ) : null}
        </>
    );
}

// --- Tri-state group checkbox (port of GroupSelectToggle) ------------------
function GroupSelectToggle({
    ids,
    selected,
    onChange,
    label,
    level,
}: {
    ids: string[];
    selected: Set<string>;
    onChange: (next: { add?: string[]; remove?: string[] }) => void;
    label: ReactNode;
    level: 'topic' | 'subtopic';
}) {
    const ref = useRef<HTMLInputElement>(null);
    const inGroup = ids.filter((id) => selected.has(id)).length;
    const total = ids.length;
    const allOn = inGroup === total && total > 0;
    const allOff = inGroup === 0;
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = !allOn && !allOff;
    }, [allOn, allOff]);
    return (
        <label className={`lo-mgr-group lo-mgr-group--${level}`} onClick={(e) => e.stopPropagation()}>
            <input
                ref={ref}
                type="checkbox"
                checked={allOn}
                onChange={(e) => (e.target.checked ? onChange({ add: ids }) : onChange({ remove: ids }))}
            />
            <span className="lo-mgr-group-label">{label}</span>
            {inGroup > 0 ? (
                <span className="lo-mgr-group-count">
                    {inGroup}/{total}
                </span>
            ) : null}
        </label>
    );
}

export default function LearningObjectives() {
    const { learningObjectives, subjectChoices, accountSubject, isAdmin, preview, flash } = usePage().props as unknown as {
        learningObjectives: LoRow[];
        subjectChoices: string[];
        accountSubject: string | null;
        isAdmin: boolean;
        preview: PreviewPayload | null;
        flash: { success?: string | null; error?: string | null };
    };

    const acctSubject = (accountSubject ?? '').trim();
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Persisted UI state (per-user, survives navigation + sign-in/out).
    const [activeTab, setActiveTab] = useUIState<CurriculumKey>('teacher.lo.activeTab', 'kurikulum_merdeka');
    const [language, setLanguage] = useUIState<string>('teacher.lo.language', 'Indonesia');
    const [subject, setSubject] = useUIState<string>('teacher.lo.subject', acctSubject || '');
    const [topicFilter, setTopicFilter] = useUIState<string>('teacher.lo.topicFilter', '');
    // `busy` is intentionally ephemeral — must reset across navigations.
    const [busy, setBusy] = useState(false);

    // When a preview arrives from the server, jump to its curriculum tab.
    useEffect(() => {
        if (preview) setActiveTab(preview.curriculum);
    }, [preview]);

    // Tree expansion state — persisted per-user so the user's careful drilling
    // into a 300-LO catalog isn't undone on every page change.
    const [openTopics, setOpenTopics] = useUIState<string[]>('teacher.lo.openTopics', []);
    const [openSubtopics, setOpenSubtopics] = useUIState<string[]>('teacher.lo.openSubtopics', []);
    const isTopicOpen = (topic: string) => openTopics.includes(`${activeTab}::${topic}`);
    const isSubtopicOpen = (topic: string, sub: string) => openSubtopics.includes(`${activeTab}::${topic}::${sub}`);
    function toggleTopic(topic: string) {
        const key = `${activeTab}::${topic}`;
        if (openTopics.includes(key)) {
            setOpenTopics(openTopics.filter((k) => k !== key));
            const prefix = `${key}::`;
            setOpenSubtopics(openSubtopics.filter((k) => !k.startsWith(prefix)));
        } else {
            setOpenTopics([...openTopics, key]);
        }
    }
    function toggleSubtopic(topic: string, sub: string) {
        const key = `${activeTab}::${topic}::${sub}`;
        setOpenSubtopics(openSubtopics.includes(key) ? openSubtopics.filter((k) => k !== key) : [...openSubtopics, key]);
    }

    const [selectedForDelete, setSelectedForDelete] = useState<Set<string>>(new Set());
    const [editing, setEditing] = useState<{ id: string; topic: string; subtopic: string; text: string } | null>(null);
    const [adding, setAdding] = useState<{ topic: string; subtopic: string; text: string } | null>(null);

    // Drop any selected ids that aren't in the current rows (tab/filter change).
    useEffect(() => {
        if (selectedForDelete.size === 0) return;
        const visible = new Set(learningObjectives.map((r) => r.id));
        let prune = false;
        for (const id of selectedForDelete)
            if (!visible.has(id)) {
                prune = true;
                break;
            }
        if (prune) {
            setSelectedForDelete((prev) => {
                const next = new Set<string>();
                for (const id of prev) if (visible.has(id)) next.add(id);
                return next;
            });
        }
    }, [learningObjectives, selectedForDelete]);

    useEffect(() => {
        if (!subject && acctSubject) setSubject(acctSubject);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [acctSubject]);

    function applyGroupSelection(change: { add?: string[]; remove?: string[] }) {
        setSelectedForDelete((prev) => {
            const next = new Set(prev);
            change.add?.forEach((id) => next.add(id));
            change.remove?.forEach((id) => next.delete(id));
            return next;
        });
    }

    // --- Upload phase 1: send the file, server parses + returns a preview ---
    function onFileSelected(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        // Client-side sanity check — surface a clear message instead of letting
        // Laravel's 422 silently bounce.
        const name = file.name.toLowerCase();
        if (!name.endsWith('.xlsx') && !name.endsWith('.xls')) {
            setImportHint(`"${file.name}" is not an .xlsx / .xls file. Save your spreadsheet as Excel format first.`);
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            setImportHint(`File is ${(file.size / (1024 * 1024)).toFixed(1)}MB — must be 10MB or smaller.`);
            return;
        }
        setImportHint(null);
        setBusy(true);
        router.post(
            '/teacher/learning-objectives/upload',
            { file, curriculum: activeTab },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => setBusy(false),
                onError: (errors) => {
                    // Inertia surfaces server validation errors here. Show the
                    // first one as an inline hint so the user knows what went
                    // wrong (silent failures lead to "it doesn't work" reports).
                    const first = Object.values(errors)[0];
                    if (first) setImportHint(`Upload failed: ${String(first)}`);
                },
            },
        );
    }

    // Local error surfaced under the Import button when client-side validation
    // fails (replaces the old silent `return` that left users wondering why
    // the button "did nothing"). Cleared automatically as soon as the issue
    // is resolved (subject filled, preview present, etc.).
    const [importHint, setImportHint] = useState<string | null>(null);
    useEffect(() => {
        if (subject.trim() && importHint) setImportHint(null);
    }, [subject, importHint]);

    // --- Upload phase 2: confirm the parsed rows -> import ---
    function onConfirmImport() {
        if (!preview) {
            setImportHint('No preview to import — upload an Excel file first.');
            return;
        }
        if (!subject.trim()) {
            setImportHint('Pick a Subject above before importing.');
            // Scroll the Subject field into view so it's impossible to miss.
            document.querySelector('[data-lo-subject-field]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        setImportHint(null);
        setBusy(true);
        router.post(
            '/teacher/learning-objectives/upload',
            {
                confirm: 1,
                curriculum: preview.curriculum,
                language: language.trim() || 'English',
                subject: subject.trim(),
                fileName: preview.fileName,
                rows: preview.rows,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    function onCancelPreview() {
        // Re-render index without the flashed preview.
        router.get('/teacher/learning-objectives', {}, { preserveScroll: true, preserveState: false });
    }

    function onDelete(id: string) {
        if (!confirm('Delete this learning objective? This cannot be undone.')) return;
        router.delete(`/teacher/learning-objectives/${encodeURIComponent(id)}`, { preserveScroll: true });
    }

    function onBulkDelete() {
        if (selectedForDelete.size === 0) return;
        const n = selectedForDelete.size;
        if (!confirm(`Delete ${n} learning objective${n === 1 ? '' : 's'}? This cannot be undone.`)) return;
        setBusy(true);
        router.post(
            '/teacher/learning-objectives/bulk-delete',
            { ids: Array.from(selectedForDelete) },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedForDelete(new Set()),
                onFinish: () => setBusy(false),
            },
        );
    }

    function submitAdd() {
        if (!adding) return;
        if (!subject.trim() || !adding.topic.trim() || adding.text.trim().length < 3) return;
        setBusy(true);
        router.post(
            '/teacher/learning-objectives',
            {
                curriculum: activeTab,
                language: language.trim() || 'English',
                subject: subject.trim(),
                topic: adding.topic.trim(),
                subtopic: adding.subtopic.trim() || null,
                text: adding.text.trim(),
            },
            { preserveScroll: true, onSuccess: () => setAdding(null), onFinish: () => setBusy(false) },
        );
    }

    function submitEdit() {
        if (!editing) return;
        if (!editing.topic.trim() || editing.text.trim().length < 3) return;
        setBusy(true);
        router.patch(
            `/teacher/learning-objectives/${encodeURIComponent(editing.id)}`,
            {
                topic: editing.topic.trim(),
                subtopic: editing.subtopic.trim() || null,
                text: editing.text.trim(),
            },
            { preserveScroll: true, onSuccess: () => setEditing(null), onFinish: () => setBusy(false) },
        );
    }

    // Per-tab counts for the tab badges.
    const countsPerCurriculum = useMemo(() => {
        const m: Record<CurriculumKey, number> = { kurikulum_merdeka: 0, as_a_level: 0, ib: 0, olympiad: 0 };
        for (const r of learningObjectives) m[r.curriculum] = (m[r.curriculum] ?? 0) + 1;
        return m;
    }, [learningObjectives]);

    // Filter to active tab + topic filter.
    const activeRows = useMemo(() => {
        const base = learningObjectives.filter((r) => r.curriculum === activeTab);
        const f = topicFilter.trim().toLowerCase();
        if (!f) return base;
        return base.filter(
            (r) =>
                r.topic.toLowerCase().includes(f) ||
                (r.subtopic ?? '').toLowerCase().includes(f) ||
                r.text.toLowerCase().includes(f),
        );
    }, [learningObjectives, activeTab, topicFilter]);

    const grouped = useMemo(() => {
        const byTopic = new Map<string, Map<string, LoRow[]>>();
        for (const r of activeRows) {
            const t = r.topic;
            const s = r.subtopic ?? NO_SUBTOPIC;
            if (!byTopic.has(t)) byTopic.set(t, new Map());
            const sub = byTopic.get(t)!;
            if (!sub.has(s)) sub.set(s, []);
            sub.get(s)!.push(r);
        }
        return byTopic;
    }, [activeRows]);

    const activeMeta = CURRICULA.find((c) => c.key === activeTab)!;
    const total = activeRows.length;
    const subjectLocked = !!acctSubject && !isAdmin;

    return (
        <TeacherShell>
            <Head title="Teacher · Curriculum" />
            <header className="teacher-page-header">
                <div>
                    <h1>
                        <GraduationCap size={22} aria-hidden style={{ verticalAlign: '-4px' }} /> Curriculum
                    </h1>
                    <p>
                        Upload Excel files of learning objectives per curriculum framework. Use the tabs below to switch
                        between curricula — each tab has its own upload + catalog.
                        {isAdmin
                            ? ' Admin view — every uploaded LO across teachers.'
                            : ' You only see LOs you uploaded yourself.'}
                    </p>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'flex-end' }}>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        hidden
                        onChange={onFileSelected}
                    />
                    <button
                        className="primary-button"
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={busy}
                        style={{ width: 'auto' }}
                    >
                        <Upload size={17} aria-hidden /> {busy ? 'Working…' : `Upload Excel → ${activeMeta.short}`}
                    </button>
                    {/* Visible hint so it's impossible to upload into the wrong curriculum
                        tab by accident (the most common cause of "the import didn't work"
                        — the file actually imports fine, just under a different tab than
                        the teacher expected). */}
                    <small style={{ color: 'var(--muted)', fontSize: '0.75rem' }}>
                        Goes into <strong style={{ color: 'var(--text)' }}>{activeMeta.label}</strong> · click another tab below to change
                    </small>
                </div>
            </header>

            {flash?.error ? <p className="form-error">{flash.error}</p> : null}
            {flash?.success ? <p className="form-success">{flash.success}</p> : null}

            {/* Tabs */}
            <div className="curriculum-tabs" role="tablist" aria-label="Curriculum">
                {CURRICULA.map((c) => {
                    const active = activeTab === c.key;
                    return (
                        <button
                            key={c.key}
                            role="tab"
                            type="button"
                            aria-selected={active}
                            className={`curriculum-tab${active ? ' active' : ''}`}
                            title={c.hint}
                            onClick={() => setActiveTab(c.key)}
                        >
                            <span>{c.label}</span>
                            <span className="curriculum-tab-count">{countsPerCurriculum[c.key] ?? 0}</span>
                        </button>
                    );
                })}
            </div>

            {/* Preview panel — when an Excel was just parsed server-side */}
            {preview ? (
                <section className="admin-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Preview: {preview.fileName}</h2>
                            <p>
                                {preview.rows.length} LO(s) recognised. Will be imported into{' '}
                                <strong>{CURRICULA.find((c) => c.key === preview.curriculum)?.label}</strong>.
                                {preview.warnings.length > 0 ? ` · ${preview.warnings.length} parser warning(s)` : ''}
                            </p>
                        </div>
                    </div>

                    <div className="ai-param-grid" style={{ marginBottom: 12 }}>
                        <label className="ai-param-field">
                            <span className="ai-param-label">Language</span>
                            <input value={language} onChange={(e) => setLanguage(e.target.value)} placeholder="Indonesia" />
                        </label>
                        <label className="ai-param-field" data-lo-subject-field>
                            <span className="ai-param-label">
                                Subject
                                {acctSubject ? (
                                    <span className="ai-param-hint">from your account</span>
                                ) : !subject.trim() ? (
                                    <span className="ai-param-hint" style={{ color: 'var(--danger, #b91c1c)', fontWeight: 600 }}>
                                        * required
                                    </span>
                                ) : null}
                            </span>
                            <div style={!subject.trim() ? { outline: '2px solid var(--danger, #b91c1c)', borderRadius: 6 } : undefined}>
                                <SubjectPicker value={subject} onChange={setSubject} choices={subjectChoices} disabled={subjectLocked} />
                            </div>
                        </label>
                        <label className="ai-param-field">
                            <span className="ai-param-label">Curriculum</span>
                            <input value={CURRICULA.find((c) => c.key === preview.curriculum)?.label ?? ''} readOnly aria-readonly />
                        </label>
                    </div>

                    {preview.warnings.length > 0 ? (
                        <ul className="lo-warning-list" style={{ marginBottom: 12 }}>
                            {preview.warnings.slice(0, 6).map((w, i) => (
                                <li key={i} style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
                                    {w}
                                </li>
                            ))}
                            {preview.warnings.length > 6 ? (
                                <li style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
                                    +{preview.warnings.length - 6} more…
                                </li>
                            ) : null}
                        </ul>
                    ) : null}

                    <ul className="import-preview-list">
                        {preview.rows.slice(0, 8).map((r, i) => (
                            <li key={i}>
                                <span>
                                    <strong>{r.topic}</strong>
                                    {r.subtopic ? ` · ${r.subtopic}` : ''}
                                </span>
                                <span>{r.text}</span>
                            </li>
                        ))}
                        {preview.rows.length > 8 ? (
                            <li>
                                <span style={{ color: 'var(--muted)' }}>+{preview.rows.length - 8} more…</span>
                            </li>
                        ) : null}
                    </ul>

                    <div style={{ display: 'flex', gap: 8, marginTop: 14, alignItems: 'center', flexWrap: 'wrap' }}>
                        <button
                            className="primary-button"
                            type="button"
                            onClick={onConfirmImport}
                            disabled={busy || !subject.trim()}
                            style={{ width: 'auto' }}
                            title={!subject.trim() ? 'Pick a Subject above before importing' : undefined}
                        >
                            {busy ? 'Importing…' : `Import into ${CURRICULA.find((c) => c.key === preview.curriculum)?.short}`}
                        </button>
                        <button className="ghost-button" type="button" onClick={onCancelPreview} disabled={busy}>
                            Cancel
                        </button>
                        {!subject.trim() ? (
                            <small style={{ color: 'var(--danger, #b91c1c)', fontSize: '0.78rem' }}>
                                ↑ Pick a Subject above first
                            </small>
                        ) : null}
                        {importHint ? (
                            <small style={{ color: 'var(--danger, #b91c1c)', fontSize: '0.78rem' }}>{importHint}</small>
                        ) : null}
                    </div>
                </section>
            ) : null}

            {/* Catalog list, scoped to active tab */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>{activeMeta.label}</h2>
                        <p>
                            {total === 0
                                ? `No learning objectives yet for ${activeMeta.short}. Upload an Excel to get started.`
                                : `${total} learning objective${total === 1 ? '' : 's'} across ${grouped.size} topic${grouped.size === 1 ? '' : 's'}.`}
                        </p>
                    </div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
                        <button
                            type="button"
                            className="ghost-button"
                            onClick={() =>
                                setAdding(adding ? null : { topic: '', subtopic: '', text: '' })
                            }
                            style={{ width: 'auto' }}
                        >
                            <Plus size={14} aria-hidden /> Add LO
                        </button>
                        {total > 0 ? (
                            <label className="ai-param-field" style={{ maxWidth: 220, margin: 0 }}>
                                <span className="ai-param-label">
                                    <Filter size={12} aria-hidden /> Filter
                                </span>
                                <input
                                    value={topicFilter}
                                    onChange={(e) => setTopicFilter(e.target.value)}
                                    placeholder="Search topic / subtopic / LO…"
                                />
                            </label>
                        ) : null}
                    </div>
                </div>

                {/* Inline add form */}
                {adding ? (
                    <div className="lo-add-form ai-param-grid" style={{ marginBottom: 14 }}>
                        <label className="ai-param-field">
                            <span className="ai-param-label">Topic</span>
                            <input
                                value={adding.topic}
                                onChange={(e) => setAdding({ ...adding, topic: e.target.value })}
                                placeholder="e.g. Mechanics"
                            />
                        </label>
                        <label className="ai-param-field">
                            <span className="ai-param-label">Subtopic (optional)</span>
                            <input
                                value={adding.subtopic}
                                onChange={(e) => setAdding({ ...adding, subtopic: e.target.value })}
                                placeholder="e.g. Kinematics"
                            />
                        </label>
                        <label className="ai-param-field" style={{ gridColumn: '1 / -1' }}>
                            <span className="ai-param-label">Learning objective</span>
                            <input
                                value={adding.text}
                                onChange={(e) => setAdding({ ...adding, text: e.target.value })}
                                placeholder="Describe what the student should be able to do…"
                            />
                        </label>
                        <div style={{ display: 'flex', gap: 8, gridColumn: '1 / -1' }}>
                            <button
                                className="primary-button"
                                type="button"
                                onClick={submitAdd}
                                disabled={busy || !subject.trim() || !adding.topic.trim() || adding.text.trim().length < 3}
                                style={{ width: 'auto' }}
                            >
                                Save LO
                            </button>
                            <button className="ghost-button" type="button" onClick={() => setAdding(null)}>
                                Cancel
                            </button>
                            {!subject.trim() ? (
                                <span style={{ color: 'var(--muted)', alignSelf: 'center', fontSize: '0.82rem' }}>
                                    Pick a subject (in the upload panel) first.
                                </span>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                {/* Bulk-action toolbar */}
                {total > 0 && activeRows.length > 0 ? (
                    <div className="lo-mgr-toolbar">
                        <strong>
                            {selectedForDelete.size > 0 ? `${selectedForDelete.size} selected` : `${activeRows.length} visible`}
                        </strong>
                        <div className="lo-mgr-toolbar-actions">
                            <button
                                type="button"
                                className="ghost-button"
                                onClick={() => setSelectedForDelete(new Set(activeRows.map((r) => r.id)))}
                                disabled={activeRows.length === 0 || activeRows.every((r) => selectedForDelete.has(r.id))}
                                style={{ padding: '4px 10px', fontSize: '0.78rem', width: 'auto', minHeight: 28 }}
                            >
                                Select all visible
                            </button>
                            <button
                                type="button"
                                className="ghost-button"
                                onClick={() => setSelectedForDelete(new Set())}
                                disabled={selectedForDelete.size === 0}
                                style={{ padding: '4px 10px', fontSize: '0.78rem', width: 'auto', minHeight: 28 }}
                            >
                                Clear
                            </button>
                            <button
                                type="button"
                                className="ghost-button danger"
                                onClick={onBulkDelete}
                                disabled={selectedForDelete.size === 0 || busy}
                                style={{ padding: '4px 12px', fontSize: '0.78rem', width: 'auto', minHeight: 28 }}
                            >
                                <Trash2 size={13} aria-hidden />{' '}
                                {busy
                                    ? 'Deleting…'
                                    : `Delete selected${selectedForDelete.size > 0 ? ` (${selectedForDelete.size})` : ''}`}
                            </button>
                        </div>
                    </div>
                ) : null}

                {grouped.size > 0 ? (
                    <div className="lo-catalog">
                        {Array.from(grouped.entries()).map(([topic, subtopicMap]) => {
                            const topicIds: string[] = [];
                            for (const list of subtopicMap.values()) for (const r of list) topicIds.push(r.id);
                            const topicOpen = isTopicOpen(topic);
                            return (
                                <div className="lo-topic" key={topic}>
                                    <div className="lo-row-head">
                                        <button
                                            type="button"
                                            className="lo-chev"
                                            aria-expanded={topicOpen}
                                            aria-label={`${topicOpen ? 'Collapse' : 'Expand'} ${topic}`}
                                            onClick={() => toggleTopic(topic)}
                                        >
                                            {topicOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
                                        </button>
                                        <GroupSelectToggle
                                            ids={topicIds}
                                            selected={selectedForDelete}
                                            onChange={applyGroupSelection}
                                            label={topic}
                                            level="topic"
                                        />
                                    </div>
                                    {topicOpen
                                        ? Array.from(subtopicMap.entries()).map(([sub, list]) => {
                                              const subtopicIds = list.map((r) => r.id);
                                              const subOpen = isSubtopicOpen(topic, sub);
                                              return (
                                                  <div className="lo-subtopic" key={sub}>
                                                      <div className="lo-row-head">
                                                          <button
                                                              type="button"
                                                              className="lo-chev"
                                                              aria-expanded={subOpen}
                                                              aria-label={`${subOpen ? 'Collapse' : 'Expand'} ${sub}`}
                                                              onClick={() => toggleSubtopic(topic, sub)}
                                                          >
                                                              {subOpen ? (
                                                                  <ChevronDown size={13} aria-hidden />
                                                              ) : (
                                                                  <ChevronRight size={13} aria-hidden />
                                                              )}
                                                          </button>
                                                          <GroupSelectToggle
                                                              ids={subtopicIds}
                                                              selected={selectedForDelete}
                                                              onChange={applyGroupSelection}
                                                              label={
                                                                  <>
                                                                      {sub === NO_SUBTOPIC ? (
                                                                          <em style={{ color: 'var(--muted)' }}>(no subtopic)</em>
                                                                      ) : (
                                                                          sub
                                                                      )}
                                                                      <span className="lo-subtopic-count">
                                                                          {list.length} LO{list.length === 1 ? '' : 's'}
                                                                      </span>
                                                                  </>
                                                              }
                                                              level="subtopic"
                                                          />
                                                      </div>
                                                      {subOpen ? (
                                                          <ul className="lo-list">
                                                              {list.map((r) => {
                                                                  const checked = selectedForDelete.has(r.id);
                                                                  const isEditing = editing?.id === r.id;
                                                                  return (
                                                                      <li key={r.id} className="lo-row">
                                                                          {isEditing ? (
                                                                              <div className="lo-edit-form" style={{ width: '100%' }}>
                                                                                  <div className="ai-param-grid">
                                                                                      <label className="ai-param-field">
                                                                                          <span className="ai-param-label">Topic</span>
                                                                                          <input
                                                                                              value={editing.topic}
                                                                                              onChange={(e) =>
                                                                                                  setEditing({ ...editing, topic: e.target.value })
                                                                                              }
                                                                                          />
                                                                                      </label>
                                                                                      <label className="ai-param-field">
                                                                                          <span className="ai-param-label">Subtopic</span>
                                                                                          <input
                                                                                              value={editing.subtopic}
                                                                                              onChange={(e) =>
                                                                                                  setEditing({
                                                                                                      ...editing,
                                                                                                      subtopic: e.target.value,
                                                                                                  })
                                                                                              }
                                                                                          />
                                                                                      </label>
                                                                                      <label
                                                                                          className="ai-param-field"
                                                                                          style={{ gridColumn: '1 / -1' }}
                                                                                      >
                                                                                          <span className="ai-param-label">
                                                                                              Learning objective
                                                                                          </span>
                                                                                          <input
                                                                                              value={editing.text}
                                                                                              onChange={(e) =>
                                                                                                  setEditing({ ...editing, text: e.target.value })
                                                                                              }
                                                                                          />
                                                                                      </label>
                                                                                  </div>
                                                                                  <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                                                                                      <button
                                                                                          className="primary-button"
                                                                                          type="button"
                                                                                          onClick={submitEdit}
                                                                                          disabled={
                                                                                              busy ||
                                                                                              !editing.topic.trim() ||
                                                                                              editing.text.trim().length < 3
                                                                                          }
                                                                                          style={{ width: 'auto' }}
                                                                                      >
                                                                                          Save
                                                                                      </button>
                                                                                      <button
                                                                                          className="ghost-button"
                                                                                          type="button"
                                                                                          onClick={() => setEditing(null)}
                                                                                          style={{ width: 'auto' }}
                                                                                      >
                                                                                          <X size={13} aria-hidden /> Cancel
                                                                                      </button>
                                                                                  </div>
                                                                              </div>
                                                                          ) : (
                                                                              <>
                                                                                  <input
                                                                                      type="checkbox"
                                                                                      className="lo-row-check"
                                                                                      checked={checked}
                                                                                      onChange={(e) =>
                                                                                          setSelectedForDelete((prev) => {
                                                                                              const next = new Set(prev);
                                                                                              if (e.target.checked) next.add(r.id);
                                                                                              else next.delete(r.id);
                                                                                              return next;
                                                                                          })
                                                                                      }
                                                                                      aria-label={`Select ${r.text}`}
                                                                                  />
                                                                                  <span className="lo-row-text">{r.text}</span>
                                                                                  <div className="lo-row-meta">
                                                                                      {r.sourceFileName ? (
                                                                                          <span
                                                                                              className="lo-row-source"
                                                                                              title={r.sourceFileName}
                                                                                          >
                                                                                              {r.sourceFileName}
                                                                                          </span>
                                                                                      ) : null}
                                                                                      <button
                                                                                          type="button"
                                                                                          className="ghost-button"
                                                                                          onClick={() =>
                                                                                              setEditing({
                                                                                                  id: r.id,
                                                                                                  topic: r.topic,
                                                                                                  subtopic: r.subtopic ?? '',
                                                                                                  text: r.text,
                                                                                              })
                                                                                          }
                                                                                          title="Edit this LO"
                                                                                          style={{
                                                                                              padding: '2px 8px',
                                                                                              fontSize: '0.78rem',
                                                                                              minHeight: 26,
                                                                                              width: 'auto',
                                                                                          }}
                                                                                      >
                                                                                          <Pencil size={13} aria-hidden /> Edit
                                                                                      </button>
                                                                                      <button
                                                                                          type="button"
                                                                                          className="ghost-button danger"
                                                                                          onClick={() => onDelete(r.id)}
                                                                                          title="Delete this LO"
                                                                                          style={{
                                                                                              padding: '2px 8px',
                                                                                              fontSize: '0.78rem',
                                                                                              minHeight: 26,
                                                                                              width: 'auto',
                                                                                          }}
                                                                                      >
                                                                                          <Trash2 size={13} aria-hidden /> Delete
                                                                                      </button>
                                                                                  </div>
                                                                              </>
                                                                          )}
                                                                      </li>
                                                                  );
                                                              })}
                                                          </ul>
                                                      ) : null}
                                                  </div>
                                              );
                                          })
                                        : null}
                                </div>
                            );
                        })}
                    </div>
                ) : total === 0 && topicFilter.trim() === '' ? null : (
                    <p style={{ color: 'var(--muted)' }}>No topics match &quot;{topicFilter}&quot;.</p>
                )}
            </section>
        </TeacherShell>
    );
}
