import { Link, router, usePage, Head } from '@inertiajs/react';
import { BarChart3, Clock, KeyRound, LogOut, RotateCcw, ShieldCheck } from 'lucide-react';

// Resume-card payload — passed by StudentController::home for each in-progress
// draft session the student has. `resumeToken` lets the student re-enter the
// exam via /exams/{examId}/resume/{resumeToken} even if their 8h
// exam-access cookie has expired (network outage / closed-tab / browser
// crash recovery). Falls back to /exams/{examId} for legacy sessions.
type Resumable = {
    examId: string;
    examName: string;
    mode: 'strict' | 'try_out';
    timeRemainingSeconds: number;
    startedAt: string;
    resumeToken: string | null;
};

function formatRemaining(sec: number): string {
    if (sec < 60) return `${sec}s left`;
    const m = Math.floor(sec / 60);
    if (m < 60) return `${m} min left`;
    const h = Math.floor(m / 60);
    const rem = m % 60;
    return rem > 0 ? `${h}h ${rem}m left` : `${h}h left`;
}

// Two-card chooser shown to students right after login (faithful to the
// original StudentHomeClient): Take an exam (-> /token) or View my scores.
// PLUS a high-visibility "Resume your exam" banner for any in-progress
// attempts so students don't accidentally restart from zero.
export default function Hub() {
    const { auth, resumable } = usePage().props as unknown as {
        auth: { user: { fullName: string } | null };
        resumable?: Resumable[];
    };
    const resumeList: Resumable[] = Array.isArray(resumable) ? resumable : [];

    return (
        <main className="auth-shell auth-shell--centered">
            <Head title="Home" />
            <section className="auth-panel student-hub-panel">
                <div className="brand-lockup">
                    <div className="brand-mark">
                        <ShieldCheck size={24} aria-hidden />
                    </div>
                    <div>
                        <h1>Welcome, {auth.user?.fullName}</h1>
                        <p>What would you like to do?</p>
                    </div>
                </div>

                {resumeList.length > 0 ? (
                    <div className="student-hub-resume">
                        {resumeList.map((r) => {
                            // Use the per-attempt resume token (post-migration sessions),
                            // fall back to the bare exam URL for legacy/null-token rows.
                            const href = r.resumeToken
                                ? `/exams/${encodeURIComponent(r.examId)}/resume/${encodeURIComponent(r.resumeToken)}`
                                : `/exams/${encodeURIComponent(r.examId)}`;
                            return (
                                <Link key={r.examId + (r.resumeToken ?? '')} className="student-hub-resume-card" href={href}>
                                    <div className="student-hub-card-icon">
                                        <RotateCcw size={28} aria-hidden />
                                    </div>
                                    <div style={{ flex: 1 }}>
                                        <h2>Resume {r.examName}</h2>
                                        <p>
                                            <Clock size={14} aria-hidden style={{ verticalAlign: '-2px' }} />{' '}
                                            {formatRemaining(r.timeRemainingSeconds)} · all your saved answers are preserved.
                                        </p>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                ) : null}

                <div className="student-hub-grid">
                    <Link className="student-hub-card" href="/token">
                        <div className="student-hub-card-icon">
                            <KeyRound size={28} aria-hidden />
                        </div>
                        <div>
                            <h2>Take an exam</h2>
                            <p>Enter the access token from your teacher and start the exam.</p>
                        </div>
                    </Link>

                    <Link className="student-hub-card" href="/student/scores">
                        <div className="student-hub-card-icon">
                            <BarChart3 size={28} aria-hidden />
                        </div>
                        <div>
                            <h2>View my scores</h2>
                            <p>See every exam you&apos;ve already taken, with detailed question-by-question review.</p>
                        </div>
                    </Link>
                </div>

                <button type="button" className="ghost-button student-hub-logout" onClick={() => router.post('/logout')}>
                    <LogOut size={16} aria-hidden /> Sign out
                </button>
            </section>
        </main>
    );
}
