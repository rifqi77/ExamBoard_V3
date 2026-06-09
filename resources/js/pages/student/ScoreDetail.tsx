import { Link, usePage, Head } from '@inertiajs/react';

function fmtAnswer(v: any): string {
    if (v === null || v === undefined || v === '') return '—';
    if (Array.isArray(v)) return v.join(', ');
    return String(v);
}

export default function ScoreDetail() {
    const { submission: s } = usePage().props as any;

    return (
        <div className="min-h-screen bg-slate-100">
            <Head title={`Review · ${s.examName}`} />
            <header className="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                <h1 className="text-lg font-semibold text-slate-900">{s.examName}</h1>
                <Link href="/student/scores" className="text-sm text-indigo-600 hover:underline">
                    All scores
                </Link>
            </header>

            <main className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-3xl font-bold text-slate-900">{s.percentScore}%</p>
                            <p className="text-sm text-slate-500">
                                {s.finalScore} / {s.possibleScore} points · pass mark {s.passingGrade}%
                            </p>
                        </div>
                        {s.pendingEssayCount > 0 ? (
                            <span className="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-700">{s.pendingEssayCount} pending grading</span>
                        ) : s.passed ? (
                            <span className="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-700">Passed</span>
                        ) : (
                            <span className="rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-700">Not passed</span>
                        )}
                    </div>
                </div>

                {s.topicBreakdown.length > 0 && (
                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <h2 className="mb-3 text-sm font-semibold text-slate-700">By topic</h2>
                        <div className="space-y-2">
                            {s.topicBreakdown.map((t: any) => (
                                <div key={t.topic} className="flex items-center gap-3">
                                    <span className="w-32 truncate text-sm text-slate-600">{t.topic}</span>
                                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                        <div className="h-full bg-indigo-500" style={{ width: `${t.percent}%` }} />
                                    </div>
                                    <span className="w-20 text-right text-xs text-slate-500">
                                        {t.earned}/{t.possible}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="space-y-3">
                    {s.items.map((it: any) => (
                        <div key={it.question.id} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-xs font-medium text-slate-500">
                                    {it.question.topic} · {it.awarded}/{it.possible} pt
                                </span>
                                {it.requiresGrading ? (
                                    <span className="text-xs font-medium text-amber-600">Pending</span>
                                ) : it.isCorrect ? (
                                    <span className="text-xs font-medium text-emerald-600">Correct</span>
                                ) : (
                                    <span className="text-xs font-medium text-red-600">Incorrect</span>
                                )}
                            </div>
                            <p className="whitespace-pre-wrap text-sm text-slate-900">{it.question.prompt}</p>
                            <div className="mt-3 space-y-1 text-sm">
                                <p>
                                    <span className="text-slate-500">Your answer: </span>
                                    <span className="text-slate-800">{fmtAnswer(it.studentAnswer)}</span>
                                </p>
                                {it.isAutoGraded && (
                                    <p>
                                        <span className="text-slate-500">Correct answer: </span>
                                        <span className="text-emerald-700">{fmtAnswer(it.correctAnswer)}</span>
                                    </p>
                                )}
                                {it.explanationText && (
                                    <p className="mt-2 rounded bg-slate-50 p-2 text-slate-600">
                                        <span className="font-medium">Explanation: </span>
                                        {it.explanationText}
                                    </p>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </main>
        </div>
    );
}
