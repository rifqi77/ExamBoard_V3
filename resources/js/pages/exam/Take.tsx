import { router, usePage, Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

type Q = {
    id: string;
    position: number;
    type: 'single_choice' | 'multi_select' | 'short_text' | 'numeric' | 'essay';
    topic: string;
    tags: string[];
    prompt: string;
    options: { id: string; text: string }[] | null;
    points: number;
};
type Media = { id: string; questionId: string; type: string; url: string; altText: string | null; caption: string | null };

const LS_PREFIX = 'exam_draft::';
const LS_MAX_AGE_MS = 24 * 60 * 60 * 1000; // prune entries older than 24h
const AUTOSAVE_MS = 5_000;
const SUBMIT_RETRY_BACKOFF_MS = [800, 1800, 4000, 8000]; // 4 retries

function resolveMediaUrl(url: string, base: string | null): string {
    if (!url) return url;
    const drive = url.match(/drive\.google\.com\/file\/d\/([^/]+)/);
    if (drive) return `https://drive.google.com/uc?export=view&id=${drive[1]}`;
    if (/^https?:\/\//.test(url)) return url;
    if (base) return base.replace(/\/$/, '') + '/' + url.replace(/^\//, '');
    return url;
}

function fmt(sec: number): string {
    const s = Math.max(0, sec);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const ss = s % 60;
    const pad = (n: number) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(ss)}` : `${pad(m)}:${pad(ss)}`;
}

// Best-effort sweep of stale localStorage drafts (>24h old) so the browser
// doesn't accumulate hundreds of entries from previous exam sessions.
function pruneStaleLocalStorage() {
    try {
        const toRemove: string[] = [];
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (!key || !key.startsWith(LS_PREFIX)) continue;
            try {
                const raw = localStorage.getItem(key);
                if (!raw) continue;
                const parsed = JSON.parse(raw);
                if (typeof parsed?.at === 'number' && Date.now() - parsed.at > LS_MAX_AGE_MS) {
                    toRemove.push(key);
                }
            } catch {
                /* ignore */
            }
        }
        toRemove.forEach((k) => localStorage.removeItem(k));
    } catch {
        /* localStorage unavailable / quota — ignore */
    }
}

function readLocalAnswers(sessionId: string): Record<string, any> | null {
    try {
        const raw = localStorage.getItem(LS_PREFIX + sessionId);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object' && parsed.answers && typeof parsed.answers === 'object') {
            return parsed.answers as Record<string, any>;
        }
        return null;
    } catch {
        return null;
    }
}

function writeLocalAnswers(sessionId: string, answers: Record<string, any>) {
    try {
        localStorage.setItem(LS_PREFIX + sessionId, JSON.stringify({ at: Date.now(), answers }));
    } catch {
        /* quota — ignore, server autosave is the durable layer */
    }
}

function clearLocalAnswers(sessionId: string) {
    try {
        localStorage.removeItem(LS_PREFIX + sessionId);
    } catch {
        /* ignore */
    }
}

export default function Take() {
    const { metadata, session, questions, media, draftAnswers } = usePage().props as any;
    const qs: Q[] = questions;

    // On mount: merge localStorage answers UNCONDITIONALLY over server drafts.
    // Local wins — guarantees recovery of answers that never reached the server
    // (Case 2 + 4: typed during a network outage, autosave failed, then page
    // is reopened). If LS has nothing, just use server drafts.
    const initialAnswers = useMemo<Record<string, any>>(() => {
        const local = readLocalAnswers(session.id);
        return { ...draftAnswers, ...(local ?? {}) };
    }, [session.id, draftAnswers]);

    const [answers, setAnswers] = useState<Record<string, any>>(initialAnswers);
    const [idx, setIdx] = useState(0);
    const [remaining, setRemaining] = useState<number>(session.timeRemainingSeconds);
    const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'offline'>('saved');
    const [submitting, setSubmitting] = useState(false);
    const [recoverable, setRecoverable] = useState<string | null>(null); // submit-failed banner message
    const dirty = useRef<Record<string, boolean>>({});
    const submittingRef = useRef(false);
    const answersRef = useRef(answers);
    answersRef.current = answers;

    const mediaByQ = useMemo(() => {
        const map: Record<string, Media[]> = {};
        (media as Media[]).forEach((m) => {
            (map[m.questionId] ||= []).push(m);
        });
        return map;
    }, [media]);

    // Prune stale LS once, AFTER we've read this session's entry.
    useEffect(() => {
        pruneStaleLocalStorage();
        // If we resurrected local-only answers, mark them dirty so the very
        // next autosave tick syncs them to the server.
        const local = readLocalAnswers(session.id);
        if (local) {
            Object.keys(local).forEach((k) => (dirty.current[k] = true));
            if (Object.keys(local).length > 0) setSaveState('saving');
        }
        // Also persist the merged initial state to LS so the entry is fresh.
        writeLocalAnswers(session.id, initialAnswers);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ----- Autosave (server) -----
    const flush = useCallback(async (): Promise<boolean> => {
        const keys = Object.keys(dirty.current);
        if (keys.length === 0) return true;
        const payload: Record<string, any> = {};
        keys.forEach((k) => (payload[k] = answersRef.current[k]));
        // Optimistically clear dirty; re-mark on failure.
        dirty.current = {};
        setSaveState('saving');
        try {
            const res = await fetch(`/api/exams/${metadata.examId}/draft`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ answers: payload }),
                credentials: 'same-origin',
                keepalive: true,
            });
            if (!res.ok) throw new Error('autosave-' + res.status);
            setSaveState('saved');
            return true;
        } catch {
            keys.forEach((k) => (dirty.current[k] = true));
            setSaveState('offline');
            return false;
        }
    }, [metadata.examId]);

    // ----- Submit with retry + recoverable banner -----
    const doSubmit = useCallback(
        async (auto = false) => {
            if (submittingRef.current) return;
            if (!auto && !window.confirm('Submit your exam? You cannot change answers after this.')) return;
            submittingRef.current = true;
            setSubmitting(true);
            setRecoverable(null);
            // Persist anything still in flight before the network call.
            await flush();

            const body = JSON.stringify({
                answers: answersRef.current, // FULL local snapshot — defense in depth (Case 2)
                events: [], // anti-cheat events placeholder (richer event flushing comes with ExamClient port)
            });

            const attempts = SUBMIT_RETRY_BACKOFF_MS.length + 1;
            for (let attempt = 0; attempt < attempts; attempt++) {
                try {
                    const res = await fetch(`/exams/${metadata.examId}/submit`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        body,
                        credentials: 'same-origin',
                        keepalive: true,
                    });
                    if (!res.ok) throw new Error('submit-' + res.status);
                    const json = (await res.json().catch(() => null)) as { submissionId?: string } | null;
                    if (!json?.submissionId) throw new Error('submit-no-id');
                    // SUCCESS — only NOW is it safe to clear the local backup.
                    clearLocalAnswers(session.id);
                    router.visit('/results/' + json.submissionId);
                    return;
                } catch (err) {
                    if (attempt < SUBMIT_RETRY_BACKOFF_MS.length) {
                        // Sleep with exponential backoff before retrying.
                        await new Promise((r) => setTimeout(r, SUBMIT_RETRY_BACKOFF_MS[attempt]));
                        continue;
                    }
                    // All retries exhausted — keep the local backup intact,
                    // show a recoverable banner, set up auto-retry.
                    submittingRef.current = false;
                    setSubmitting(false);
                    setRecoverable(
                        'Your answers are saved locally but the submit did not reach the server. We will keep retrying — leave this page open.',
                    );
                    return;
                }
            }
        },
        [flush, metadata.examId, session.id],
    );

    // ----- Countdown (auto-submit on zero) -----
    useEffect(() => {
        const t = setInterval(() => {
            setRemaining((r) => {
                if (r <= 1) {
                    clearInterval(t);
                    void doSubmit(true);
                    return 0;
                }
                return r - 1;
            });
        }, 1000);
        return () => clearInterval(t);
    }, [doSubmit]);

    // ----- Periodic autosave -----
    useEffect(() => {
        const t = setInterval(() => void flush(), AUTOSAVE_MS);
        return () => clearInterval(t);
    }, [flush]);

    // ----- Flush on tab hide / page unload (keepalive request survives tab close) -----
    useEffect(() => {
        const onHide = () => void flush();
        document.addEventListener('visibilitychange', onHide);
        window.addEventListener('pagehide', onHide);
        window.addEventListener('beforeunload', onHide);
        return () => {
            document.removeEventListener('visibilitychange', onHide);
            window.removeEventListener('pagehide', onHide);
            window.removeEventListener('beforeunload', onHide);
        };
    }, [flush]);

    // ----- Recovery: auto-retry submit on `online` + every 10s while banner showing -----
    useEffect(() => {
        if (!recoverable) return;
        const retry = () => {
            if (submittingRef.current) return;
            void doSubmit(true);
        };
        const onOnline = () => retry();
        window.addEventListener('online', onOnline);
        const t = setInterval(retry, 10_000);
        return () => {
            window.removeEventListener('online', onOnline);
            clearInterval(t);
        };
    }, [recoverable, doSubmit]);

    function setAnswer(qid: string, val: any) {
        setAnswers((a) => {
            const next = { ...a, [qid]: val };
            // localStorage FIRST (synchronous, before any network) so even a
            // crash a millisecond later preserves the keystroke.
            writeLocalAnswers(session.id, next);
            return next;
        });
        dirty.current[qid] = true;
        setSaveState('saving');
    }

    const q = qs[idx];
    const answeredCount = qs.filter((x) => {
        const v = answers[x.id];
        return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0);
    }).length;

    const saveLabel =
        saveState === 'saved' ? 'Saved' : saveState === 'saving' ? 'Saving…' : saveState === 'offline' ? 'Offline — retrying' : '';

    return (
        <div className="min-h-screen bg-slate-100">
            <Head title={metadata.name} />
            <header className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-3">
                <div>
                    <h1 className="font-semibold text-slate-900">{metadata.name}</h1>
                    <p className="text-xs text-slate-500">
                        {answeredCount}/{qs.length} answered · <span className={saveState === 'offline' ? 'text-amber-700' : ''}>{saveLabel}</span>
                    </p>
                </div>
                <div className={`rounded-md px-3 py-1.5 font-mono text-lg font-bold ${remaining < 60 ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-800'}`}>
                    {fmt(remaining)}
                </div>
            </header>

            {recoverable && (
                <div role="alert" className="mx-auto mt-4 max-w-6xl rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong className="block">Submit didn't reach the server.</strong>
                    <span>{recoverable}</span>
                    <button
                        type="button"
                        onClick={() => void doSubmit(true)}
                        className="ml-3 rounded-md bg-amber-600 px-3 py-1 text-xs font-semibold text-white hover:bg-amber-700"
                    >
                        Retry now
                    </button>
                </div>
            )}

            <div className="mx-auto grid max-w-6xl grid-cols-1 gap-6 p-6 lg:grid-cols-[1fr_220px]">
                <main className="space-y-4">
                    {metadata.generalInstructions && idx === 0 && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 whitespace-pre-wrap">{metadata.generalInstructions}</div>
                    )}
                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-sm font-medium text-slate-500">
                                Question {q.position} · {q.topic} · {q.points} pt{q.points === 1 ? '' : 's'}
                            </span>
                        </div>
                        <div className="prose prose-slate max-w-none whitespace-pre-wrap text-slate-900">{q.prompt}</div>

                        {(mediaByQ[q.id] || []).map((m) => (
                            <div key={m.id} className="my-3">
                                {m.type === 'image' && <img src={resolveMediaUrl(m.url, metadata.mediaBaseUrl)} alt={m.altText ?? ''} className="max-h-80 rounded border" />}
                                {m.type === 'audio' && <audio controls src={resolveMediaUrl(m.url, metadata.mediaBaseUrl)} />}
                                {m.type === 'video' && <video controls src={resolveMediaUrl(m.url, metadata.mediaBaseUrl)} className="max-h-80 rounded border" />}
                                {m.caption && <p className="mt-1 text-xs text-slate-500">{m.caption}</p>}
                            </div>
                        ))}

                        <div className="mt-5">{renderInput(q, answers[q.id], (v) => setAnswer(q.id, v))}</div>
                    </div>

                    <div className="flex items-center justify-between">
                        <button onClick={() => setIdx((i) => Math.max(0, i - 1))} disabled={idx === 0} className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm disabled:opacity-50">
                            ← Previous
                        </button>
                        {idx < qs.length - 1 ? (
                            <button onClick={() => setIdx((i) => Math.min(qs.length - 1, i + 1))} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                Next →
                            </button>
                        ) : (
                            <button onClick={() => void doSubmit(false)} disabled={submitting} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                                {submitting ? 'Submitting…' : 'Submit exam'}
                            </button>
                        )}
                    </div>
                </main>

                <aside className="space-y-3">
                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <p className="mb-2 text-xs font-medium text-slate-500">Questions</p>
                        <div className="grid grid-cols-5 gap-1.5">
                            {qs.map((x, i) => {
                                const v = answers[x.id];
                                const done = v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0);
                                return (
                                    <button
                                        key={x.id}
                                        onClick={() => setIdx(i)}
                                        className={`h-8 rounded text-xs font-medium ${i === idx ? 'ring-2 ring-indigo-500 ' : ''}${done ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}
                                    >
                                        {i + 1}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <button onClick={() => void doSubmit(false)} disabled={submitting} className="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                        Submit exam
                    </button>
                </aside>
            </div>
        </div>
    );
}

function renderInput(q: Q, value: any, onChange: (v: any) => void) {
    if (q.type === 'single_choice') {
        return (
            <div className="space-y-2">
                {(q.options || []).map((o) => (
                    <label key={o.id} className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 ${value === o.id ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'}`}>
                        <input type="radio" name={q.id} checked={value === o.id} onChange={() => onChange(o.id)} />
                        <span className="text-sm text-slate-800">{o.text}</span>
                    </label>
                ))}
            </div>
        );
    }
    if (q.type === 'multi_select') {
        const arr: string[] = Array.isArray(value) ? value : [];
        return (
            <div className="space-y-2">
                {(q.options || []).map((o) => {
                    const checked = arr.includes(o.id);
                    return (
                        <label key={o.id} className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 ${checked ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'}`}>
                            <input type="checkbox" checked={checked} onChange={() => onChange(checked ? arr.filter((x) => x !== o.id) : [...arr, o.id])} />
                            <span className="text-sm text-slate-800">{o.text}</span>
                        </label>
                    );
                })}
            </div>
        );
    }
    if (q.type === 'short_text') {
        return <input type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Your answer" />;
    }
    if (q.type === 'numeric') {
        return <input type="number" value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Numeric answer" />;
    }
    return <textarea value={value ?? ''} onChange={(e) => onChange(e.target.value)} rows={8} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Write your answer…" />;
}
