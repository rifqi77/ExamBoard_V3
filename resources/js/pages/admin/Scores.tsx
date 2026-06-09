import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    CircleX,
    Clock,
    FileSpreadsheet,
    PenLine,
    Trash2,
    UserSquare2,
    Users,
    UserX,
} from 'lucide-react';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import AdminShell from '@/components/AdminShell';
import { useUIState } from '@/lib/useUIState';

// ---------------------------------------------------------------------------
// Admin Scores — school-wide oversight. Port of the admin scores view, but
// rebuilt as a Teacher → Exam → Class → Students tree with the SAME rich
// features as the teacher Scores page (auto/essay split, per-class averages,
// not-submitted roster + diagnostics, bulk delete) plus a teacher picker that
// scopes the tree via ?teacherId=. Data is built by Admin\ScoresController.
// ---------------------------------------------------------------------------

type SubmissionSummary = {
    id: string;
    userId: string;
    examId: string;
    examName: string;
    studentName: string;
    username: string;
    finalScore: number;
    possibleScore: number;
    percentScore: number;
    passed: boolean;
    pendingEssayCount: number;
    autoEarned: number;
    autoPossible: number;
    essayEarned: number;
    pendingEssayPoints: number;
    essayTotalPoints: number;
    gradedPercent: number;
    nonEssayPercent: number;
    essayPercent: number | null;
    nonEssayPassed: boolean;
    essayPassed: boolean | null;
    gradingStatus: 'pending_grading' | 'graded';
    submittedAt: string | null;
};

type NotSubmitted = {
    studentIdentifier: string;
    studentName: string;
    username: string | null;
    lastSignInAt: string | null;
    sessionStartedAt: string | null;
    sessionLastSavedAt: string | null;
    sessionStatus: string | null;
    sessionTimeUsedSeconds: number;
    sessionAntiCheatEventCount: number;
    sessionDraftCount: number;
    sessionId: string | null;
};

type ClassGroupT = {
    classId: string | null;
    className: string;
    academicYear: string | null;
    studentCount: number;
    submissionCount: number;
    passedCount: number;
    pendingCount: number;
    averagePercent: number | null;
    notSubmittedCount: number;
    notSubmitted: NotSubmitted[];
    rosterSize: number;
    submissions: SubmissionSummary[];
};

type ExamGroupT = {
    examDatabaseId: string;
    examId: string;
    examName: string;
    passingGrade: number;
    ownerName: string | null;
    totalSubmissions: number;
    pendingCount: number;
    passedCount: number;
    averagePercent: number | null;
    classes: ClassGroupT[];
};

type TeacherGroupT = {
    teacherId: string | null;
    teacherName: string;
    teacherSubject: string | null;
    teacherActive: boolean | null;
    examCount: number;
    totalSubmissions: number;
    pendingCount: number;
    passedCount: number;
    exams: ExamGroupT[];
};

type TeacherOption = { userId: string; fullName: string; subject: string | null; active: boolean };

type PageProps = {
    teacherGroups: TeacherGroupT[];
    teachers: TeacherOption[];
    teacherId: string | null;
    flash?: { success?: string | null; error?: string | null };
};

function formatDate(value: string | null): string {
    if (!value) return '—';
    const ms = new Date(value).getTime();
    if (Number.isNaN(ms) || ms < 86_400_000) return '—';
    return new Date(value).toLocaleString();
}

// ---- Tri-state class-level select toggle (port of teacher Scores) -------

function ClassSelectToggle({
    ids,
    selected,
    onChange,
    label,
}: {
    ids: string[];
    selected: Set<string>;
    onChange: (next: { add?: string[]; remove?: string[] }) => void;
    label: ReactNode;
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
        <label className="scores-class-toggle" onClick={(e) => e.stopPropagation()}>
            <input
                ref={ref}
                type="checkbox"
                checked={allOn}
                onChange={(e) => (e.target.checked ? onChange({ add: ids }) : onChange({ remove: ids }))}
            />
            <span className="scores-class-toggle-label">{label}</span>
            {inGroup > 0 ? (
                <span className="scores-class-toggle-count">
                    {inGroup}/{total}
                </span>
            ) : null}
        </label>
    );
}

export default function Scores() {
    const { teacherGroups, teachers, teacherId, flash } = usePage().props as unknown as PageProps;

    const [openTeachers, setOpenTeachers] = useUIState<string[]>('admin.scores.openTeachers', []);
    const [openExams, setOpenExams] = useUIState<string[]>('admin.scores.openExams', []);
    const [openClasses, setOpenClasses] = useUIState<string[]>('admin.scores.openClasses', []);
    const [yearFilter, setYearFilter] = useUIState<string>('admin.scores.yearFilter', '');

    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [bulkBusy, setBulkBusy] = useState(false);

    const teacherKey = (id: string | null) => id ?? 'no-teacher';
    const isTeacherOpen = (id: string | null) => openTeachers.includes(teacherKey(id));
    const isExamOpen = (examId: string) => openExams.includes(examId);
    const classKey = (examId: string, classId: string | null) => `${examId}::${classId ?? 'no-class'}`;
    const isClassOpen = (examId: string, classId: string | null) => openClasses.includes(classKey(examId, classId));

    function toggleTeacher(id: string | null) {
        const key = teacherKey(id);
        setOpenTeachers(openTeachers.includes(key) ? openTeachers.filter((k) => k !== key) : [...openTeachers, key]);
    }
    function toggleExam(examId: string) {
        if (openExams.includes(examId)) {
            setOpenExams(openExams.filter((k) => k !== examId));
            const prefix = `${examId}::`;
            setOpenClasses(openClasses.filter((k) => !k.startsWith(prefix)));
        } else {
            setOpenExams([...openExams, examId]);
        }
    }
    function toggleClass(examId: string, classId: string | null) {
        const key = classKey(examId, classId);
        setOpenClasses(openClasses.includes(key) ? openClasses.filter((k) => k !== key) : [...openClasses, key]);
    }

    function onScope(next: string) {
        router.get('/admin/scores', next ? { teacherId: next } : {}, { preserveState: false, preserveScroll: true });
    }

    // Drop stale selected ids after a refresh removes rows.
    useEffect(() => {
        if (selectedIds.size === 0) return;
        const visible = new Set<string>();
        for (const tg of teacherGroups)
            for (const g of tg.exams) for (const c of g.classes) for (const s of c.submissions) visible.add(s.id);
        let needsPrune = false;
        for (const id of selectedIds)
            if (!visible.has(id)) {
                needsPrune = true;
                break;
            }
        if (needsPrune) {
            const next = new Set<string>();
            for (const id of selectedIds) if (visible.has(id)) next.add(id);
            setSelectedIds(next);
        }
    }, [teacherGroups]);

    function applyGroupSelection(change: { add?: string[]; remove?: string[] }) {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            change.add?.forEach((id) => next.add(id));
            change.remove?.forEach((id) => next.delete(id));
            return next;
        });
    }

    function onDeleteAllScores(group: ExamGroupT) {
        const allIds: string[] = [];
        for (const cls of group.classes) for (const s of cls.submissions) allIds.push(s.id);
        if (allIds.length === 0) return;
        if (
            !window.confirm(
                `Delete ALL ${allIds.length} submission${allIds.length === 1 ? '' : 's'} for "${group.examName}"? The exam itself stays; only the score data is removed. Cannot be undone.`,
            )
        )
            return;
        setBulkBusy(true);
        router.post(
            `/admin/exams/${encodeURIComponent(group.examId)}/scores/delete-all`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBulkBusy(false),
                onSuccess: () =>
                    setSelectedIds((prev) => {
                        const next = new Set(prev);
                        for (const id of allIds) next.delete(id);
                        return next;
                    }),
            },
        );
    }

    function onDeleteIds(ids: string[]) {
        if (ids.length === 0) return;
        if (
            !window.confirm(
                `Delete ${ids.length} submission${ids.length === 1 ? '' : 's'}? This permanently removes the student's answers and grading. Cannot be undone.`,
            )
        )
            return;
        setBulkBusy(true);
        router.post(
            '/admin/submissions/bulk-delete',
            { ids },
            {
                preserveScroll: true,
                onFinish: () => setBulkBusy(false),
                onSuccess: () =>
                    setSelectedIds((prev) => {
                        const next = new Set(prev);
                        for (const id of ids) next.delete(id);
                        return next;
                    }),
            },
        );
    }

    const availableYears = useMemo(
        () =>
            Array.from(
                new Set(
                    teacherGroups
                        .flatMap((tg) => tg.exams)
                        .flatMap((g) => g.classes.map((c) => c.academicYear))
                        .filter((y): y is string => Boolean(y)),
                ),
            ).sort((a, b) => b.localeCompare(a)),
        [teacherGroups],
    );

    // Apply year filter at the class level, re-deriving exam + teacher rollups.
    const filteredTeacherGroups = useMemo<TeacherGroupT[]>(
        () =>
            teacherGroups
                .map((tg) => {
                    const exams = tg.exams
                        .map((g) => {
                            const keptClasses = g.classes.filter(
                                (c) => !yearFilter || c.academicYear === null || c.academicYear === yearFilter,
                            );
                            const totalSubmissions = keptClasses.reduce((sum, c) => sum + c.submissionCount, 0);
                            const pendingCount = keptClasses.reduce((sum, c) => sum + c.pendingCount, 0);
                            const passedCount = keptClasses.reduce((sum, c) => sum + c.passedCount, 0);
                            const allSubs = keptClasses.flatMap((c) => c.submissions);
                            const averagePercent =
                                allSubs.length === 0
                                    ? null
                                    : Number(
                                          (allSubs.reduce((sum, s) => sum + s.percentScore, 0) / allSubs.length).toFixed(2),
                                      );
                            return { ...g, classes: keptClasses, totalSubmissions, pendingCount, passedCount, averagePercent };
                        })
                        .filter((g) => yearFilter === '' || g.classes.length > 0);
                    return {
                        ...tg,
                        exams,
                        examCount: exams.length,
                        totalSubmissions: exams.reduce((s, g) => s + g.totalSubmissions, 0),
                        pendingCount: exams.reduce((s, g) => s + g.pendingCount, 0),
                        passedCount: exams.reduce((s, g) => s + g.passedCount, 0),
                    };
                })
                .filter((tg) => yearFilter === '' || tg.exams.length > 0),
        [teacherGroups, yearFilter],
    );

    const totalPending = filteredTeacherGroups.reduce((sum, tg) => sum + tg.pendingCount, 0);
    const totalSubmissions = filteredTeacherGroups.reduce((sum, tg) => sum + tg.totalSubmissions, 0);
    const totalPassed = filteredTeacherGroups.reduce((sum, tg) => sum + tg.passedCount, 0);

    const allTeacherKeys = filteredTeacherGroups.filter((tg) => tg.totalSubmissions > 0).map((tg) => teacherKey(tg.teacherId));
    const allTeachersOpen = allTeacherKeys.length > 0 && allTeacherKeys.every((k) => openTeachers.includes(k));

    return (
        <AdminShell>
            <Head title="Admin · Scores" />
            <header className="teacher-page-header">
                <div>
                    <h1>Scores</h1>
                    <p>
                        School-wide submissions grouped by teacher, then exam, then class. Auto-graded portion is scored on
                        submit; essay answers need a manual grade before the result is final.
                        {allTeacherKeys.length > 0 ? (
                            <>
                                {' · '}
                                <button
                                    type="button"
                                    className="inline-link-button"
                                    onClick={() => setOpenTeachers(allTeachersOpen ? [] : allTeacherKeys)}
                                >
                                    {allTeachersOpen ? 'Collapse all' : 'Expand all'}
                                </button>
                            </>
                        ) : null}
                    </p>
                </div>
            </header>

            {flash?.error ? <p className="form-error">{flash.error}</p> : null}
            {flash?.success ? <p className="form-success">{flash.success}</p> : null}

            <TeacherScopePicker teachers={teachers} value={teacherId} onChange={onScope} />

            <section className="admin-metrics admin-metrics--3col">
                <div className="admin-panel metric-card">
                    <FileSpreadsheet size={18} aria-hidden />
                    <span>Total submissions</span>
                    <strong>{totalSubmissions}</strong>
                    <small className="metric-card-sub">across every teacher</small>
                </div>
                <div className="admin-panel metric-card">
                    <CheckCircle2 size={18} aria-hidden />
                    <span>Passed</span>
                    <strong>{totalPassed}</strong>
                    <small className="metric-card-sub">graded submissions above passing grade</small>
                </div>
                <div className="admin-panel metric-card">
                    <PenLine size={18} aria-hidden />
                    <span>Awaiting grading</span>
                    <strong>{totalPending}</strong>
                    <small className="metric-card-sub">essays still need a manual score</small>
                </div>
            </section>

            {availableYears.length > 0 ? (
                <div className="year-filter">
                    <label htmlFor="scores-year-filter">Academic year:</label>
                    <select id="scores-year-filter" value={yearFilter} onChange={(e) => setYearFilter(e.target.value)}>
                        <option value="">All years</option>
                        {availableYears.map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            {filteredTeacherGroups.length === 0 ? (
                <section className="admin-panel">
                    <p style={{ color: 'var(--muted)', margin: 0 }}>
                        {teacherGroups.length > 0 ? 'No submissions match this year filter.' : 'No exams in the system yet.'}
                    </p>
                </section>
            ) : (
                filteredTeacherGroups.map((tg) => (
                    <TeacherSection
                        key={teacherKey(tg.teacherId)}
                        group={tg}
                        isOpen={isTeacherOpen(tg.teacherId)}
                        onToggle={() => toggleTeacher(tg.teacherId)}
                        isExamOpen={isExamOpen}
                        onToggleExam={toggleExam}
                        isClassOpen={isClassOpen}
                        onToggleClass={toggleClass}
                        selectedIds={selectedIds}
                        onChangeSelection={applyGroupSelection}
                        onToggleRow={(id, checked) =>
                            setSelectedIds((prev) => {
                                const next = new Set(prev);
                                if (checked) next.add(id);
                                else next.delete(id);
                                return next;
                            })
                        }
                        onDeleteSelected={onDeleteIds}
                        onDeleteAllScores={onDeleteAllScores}
                        bulkBusy={bulkBusy}
                    />
                ))
            )}
        </AdminShell>
    );
}

function TeacherSection({
    group,
    isOpen,
    onToggle,
    isExamOpen,
    onToggleExam,
    isClassOpen,
    onToggleClass,
    selectedIds,
    onChangeSelection,
    onToggleRow,
    onDeleteSelected,
    onDeleteAllScores,
    bulkBusy,
}: {
    group: TeacherGroupT;
    isOpen: boolean;
    onToggle: () => void;
    isExamOpen: (examId: string) => boolean;
    onToggleExam: (examId: string) => void;
    isClassOpen: (examId: string, classId: string | null) => boolean;
    onToggleClass: (examId: string, classId: string | null) => void;
    selectedIds: Set<string>;
    onChangeSelection: (next: { add?: string[]; remove?: string[] }) => void;
    onToggleRow: (id: string, checked: boolean) => void;
    onDeleteSelected: (ids: string[]) => void;
    onDeleteAllScores: (group: ExamGroupT) => void;
    bulkBusy: boolean;
}) {
    const empty = group.totalSubmissions === 0 && group.exams.every((g) => g.totalSubmissions === 0);
    return (
        <section className="admin-panel class-panel">
            <div className="section-title-row">
                <button type="button" className="class-panel-header" aria-expanded={isOpen} onClick={onToggle}>
                    {isOpen ? (
                        <ChevronDown size={16} aria-hidden className="class-panel-chevron" />
                    ) : (
                        <ChevronRight size={16} aria-hidden className="class-panel-chevron" />
                    )}
                    <div>
                        <h2>
                            <UserSquare2 size={16} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
                            {group.teacherName}
                            {group.teacherSubject ? <span className="muted"> · {group.teacherSubject}</span> : null}
                            {group.teacherActive === false ? <span className="muted"> (inactive)</span> : null}
                        </h2>
                        <p>
                            {group.examCount} exam{group.examCount === 1 ? '' : 's'} · {group.totalSubmissions} submission
                            {group.totalSubmissions === 1 ? '' : 's'}
                            {group.pendingCount > 0 ? ` · ${group.pendingCount} pending grading` : ''}
                        </p>
                    </div>
                </button>
            </div>

            {!isOpen ? null : empty ? (
                <p style={{ color: 'var(--muted)' }}>No submissions yet for this teacher.</p>
            ) : (
                <div className="scores-class-list">
                    {group.exams.map((exam) => (
                        <ExamSection
                            key={exam.examDatabaseId}
                            group={exam}
                            isExamOpen={isExamOpen(exam.examId)}
                            onToggleExam={() => onToggleExam(exam.examId)}
                            isClassOpen={(classId) => isClassOpen(exam.examId, classId)}
                            onToggleClass={(classId) => onToggleClass(exam.examId, classId)}
                            selectedIds={selectedIds}
                            onChangeSelection={onChangeSelection}
                            onToggleRow={onToggleRow}
                            onDeleteSelected={onDeleteSelected}
                            onDeleteAllScores={onDeleteAllScores}
                            bulkBusy={bulkBusy}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function ExamSection({
    group,
    isExamOpen,
    onToggleExam,
    isClassOpen,
    onToggleClass,
    selectedIds,
    onChangeSelection,
    onToggleRow,
    onDeleteSelected,
    onDeleteAllScores,
    bulkBusy,
}: {
    group: ExamGroupT;
    isExamOpen: boolean;
    onToggleExam: () => void;
    isClassOpen: (classId: string | null) => boolean;
    onToggleClass: (classId: string | null) => void;
    selectedIds: Set<string>;
    onChangeSelection: (next: { add?: string[]; remove?: string[] }) => void;
    onToggleRow: (id: string, checked: boolean) => void;
    onDeleteSelected: (ids: string[]) => void;
    onDeleteAllScores: (group: ExamGroupT) => void;
    bulkBusy: boolean;
}) {
    const empty = group.totalSubmissions === 0;
    const selectedInExamIds: string[] = [];
    for (const cls of group.classes) for (const sub of cls.submissions) if (selectedIds.has(sub.id)) selectedInExamIds.push(sub.id);

    return (
        <div className="scores-class-group">
            <div className="scores-class-header">
                <button
                    type="button"
                    className="lo-chev"
                    aria-expanded={isExamOpen}
                    aria-label={`${isExamOpen ? 'Collapse' : 'Expand'} ${group.examName}`}
                    onClick={onToggleExam}
                    disabled={empty}
                >
                    {isExamOpen ? <ChevronDown size={13} aria-hidden /> : <ChevronRight size={13} aria-hidden />}
                </button>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <h3 style={{ margin: 0 }}>{group.examName}</h3>
                    <span className="scores-class-meta">
                        <code>{group.examId}</code> · {group.totalSubmissions} submission
                        {group.totalSubmissions === 1 ? '' : 's'}
                        {group.averagePercent !== null ? ` · avg ${group.averagePercent}%` : ''}
                        {group.pendingCount > 0 ? ` · ${group.pendingCount} pending` : ''}
                    </span>
                </div>
                {selectedInExamIds.length > 0 ? (
                    <button
                        type="button"
                        className="ghost-button danger"
                        onClick={() => onDeleteSelected(selectedInExamIds)}
                        disabled={bulkBusy}
                        style={{ width: 'auto' }}
                    >
                        <Trash2 size={14} aria-hidden /> {bulkBusy ? 'Deleting…' : `Delete ${selectedInExamIds.length} selected`}
                    </button>
                ) : null}
                {group.totalSubmissions > 0 ? (
                    <button
                        type="button"
                        className="ghost-button danger"
                        onClick={() => onDeleteAllScores(group)}
                        disabled={bulkBusy}
                        style={{ width: 'auto' }}
                        title="Delete every submission for this exam (the exam itself stays)"
                    >
                        <Trash2 size={14} aria-hidden /> Delete all scores
                    </button>
                ) : null}
                <Link className="ghost-button" href={`/admin/exams/${group.examId}`}>
                    Manage exam
                </Link>
            </div>

            {!isExamOpen ? null : empty ? (
                <p style={{ color: 'var(--muted)' }}>No submissions yet.</p>
            ) : (
                <div className="scores-class-list" style={{ marginLeft: 18 }}>
                    {group.classes.map((cls) => (
                        <ClassGroup
                            key={cls.classId ?? 'no-class'}
                            cls={cls}
                            isClassOpen={isClassOpen(cls.classId)}
                            onToggleClass={() => onToggleClass(cls.classId)}
                            selectedIds={selectedIds}
                            onChangeSelection={onChangeSelection}
                            onToggleRow={onToggleRow}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function ClassGroup({
    cls,
    isClassOpen,
    onToggleClass,
    selectedIds,
    onChangeSelection,
    onToggleRow,
}: {
    cls: ClassGroupT;
    isClassOpen: boolean;
    onToggleClass: () => void;
    selectedIds: Set<string>;
    onChangeSelection: (next: { add?: string[]; remove?: string[] }) => void;
    onToggleRow: (id: string, checked: boolean) => void;
}) {
    const submissionIds = cls.submissions.map((s) => s.id);
    return (
        <div className="scores-class-group">
            <div className="scores-class-header">
                <button
                    type="button"
                    className="lo-chev"
                    aria-expanded={isClassOpen}
                    aria-label={`${isClassOpen ? 'Collapse' : 'Expand'} ${cls.className}`}
                    onClick={onToggleClass}
                >
                    {isClassOpen ? <ChevronDown size={13} aria-hidden /> : <ChevronRight size={13} aria-hidden />}
                </button>
                <ClassSelectToggle
                    ids={submissionIds}
                    selected={selectedIds}
                    onChange={onChangeSelection}
                    label={
                        <>
                            <h3>
                                {cls.classId === null ? (
                                    <em style={{ color: 'var(--muted)' }}>{cls.className}</em>
                                ) : (
                                    cls.className
                                )}
                                {cls.academicYear ? <span className="muted"> · {cls.academicYear}</span> : null}
                            </h3>
                            <span className="scores-class-meta">
                                {cls.submissionCount} submission{cls.submissionCount === 1 ? '' : 's'}
                                {cls.averagePercent !== null ? ` · avg ${cls.averagePercent}%` : ''}
                                {cls.pendingCount > 0 ? ` · ${cls.pendingCount} pending` : ''}
                                {cls.notSubmittedCount > 0 ? ` · ${cls.notSubmittedCount} not submitted` : ''}
                            </span>
                        </>
                    }
                />
            </div>
            {isClassOpen ? (
                <>
                    <table className="dashboard-table scores-class-table">
                        <thead>
                            <tr>
                                <th aria-label="Select" style={{ width: 28 }} />
                                <th>Student</th>
                                <th>Score</th>
                                <th>Percent</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {cls.submissions.map((submission) => (
                                <SubmissionRow
                                    key={submission.id}
                                    submission={submission}
                                    checked={selectedIds.has(submission.id)}
                                    onToggle={(checked) => onToggleRow(submission.id, checked)}
                                />
                            ))}
                        </tbody>
                    </table>
                    {cls.notSubmitted.length > 0 ? <NotSubmittedTable rows={cls.notSubmitted} /> : null}
                </>
            ) : null}
        </div>
    );
}

function SubmissionRow({
    submission,
    checked,
    onToggle,
}: {
    submission: SubmissionSummary;
    checked: boolean;
    onToggle: (checked: boolean) => void;
}) {
    return (
        <tr>
            <td style={{ width: 28 }}>
                <input
                    type="checkbox"
                    checked={checked}
                    onChange={(e) => onToggle(e.target.checked)}
                    aria-label={`Select ${submission.studentName}`}
                />
            </td>
            <td>
                <div style={{ display: 'flex', alignItems: 'baseline', flexWrap: 'wrap', gap: '2px 8px', minWidth: 0 }}>
                    <strong>{submission.studentName}</strong>
                    <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>
                        <code>{submission.username}</code>
                    </span>
                </div>
            </td>
            <td>
                {submission.finalScore}
                <span style={{ color: 'var(--muted)' }}> / {submission.possibleScore}</span>
            </td>
            <td>{submission.percentScore}%</td>
            <td>
                {submission.gradingStatus === 'pending_grading' ? (
                    <span className="status-item warning">
                        <Clock size={14} aria-hidden /> Pending ({submission.pendingEssayCount} essay
                        {submission.pendingEssayCount === 1 ? '' : 's'})
                    </span>
                ) : submission.passed ? (
                    <span className="status-item neutral">
                        <CheckCircle2 size={14} aria-hidden /> Passed
                    </span>
                ) : (
                    <span className="status-item warning">
                        <CircleX size={14} aria-hidden /> Not passed
                    </span>
                )}
            </td>
            <td>{formatDate(submission.submittedAt)}</td>
            <td>
                <Link className="ghost-button" href={`/admin/scores/${submission.id}`}>
                    {submission.gradingStatus === 'pending_grading' ? (
                        <>
                            <PenLine size={14} aria-hidden /> Grade
                        </>
                    ) : (
                        'View'
                    )}
                </Link>
            </td>
        </tr>
    );
}

function NotSubmittedTable({ rows }: { rows: NotSubmitted[] }) {
    return (
        <div style={{ marginTop: 8 }}>
            <p
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    color: 'var(--muted)',
                    fontSize: '0.85rem',
                    margin: '4px 0',
                }}
            >
                <UserX size={14} aria-hidden /> Not submitted ({rows.length})
            </p>
            <table className="dashboard-table dashboard-table--compact">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Last sign-in</th>
                        <th>Opened exam</th>
                        <th>Last autosave</th>
                        <th>Drafts</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.studentIdentifier}>
                            <td>
                                <strong>{r.studentName}</strong>
                                {r.username ? (
                                    <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                                        {' '}
                                        <code>{r.username}</code>
                                    </span>
                                ) : null}
                            </td>
                            <td>{formatDate(r.lastSignInAt)}</td>
                            <td>{formatDate(r.sessionStartedAt)}</td>
                            <td>{formatDate(r.sessionLastSavedAt)}</td>
                            <td>{r.sessionDraftCount}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
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
