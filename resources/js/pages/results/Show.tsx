import { Link, usePage, Head } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Eye, ShieldCheck } from 'lucide-react';

// Immediate post-submit result — port of the original ResultsClient:
// non-essay vs essay totals shown separately, score panel, topic breakdown.
export default function Show() {
    const { result } = usePage().props as any;

    return (
        <main className="results-shell">
            <Head title={result.examName} />
            <header className="results-header">
                <div className="brand-lockup compact">
                    <div className="brand-mark">
                        <ShieldCheck size={21} aria-hidden />
                    </div>
                    <div>
                        <h1>{result.examName}</h1>
                        <p>Submitted {new Date(result.submittedAt).toLocaleString()}</p>
                    </div>
                </div>
                <Link className="ghost-button" href="/student">
                    <ArrowLeft size={17} aria-hidden /> Home
                </Link>
            </header>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, margin: '0 0 18px' }}>
                <div style={{ background: 'var(--surface, #fff)', border: '1px solid var(--border, #e6e8ec)', borderLeft: '4px solid #4f46e5', borderRadius: 12, padding: '16px 18px' }}>
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.03em' }}>
                        Non-essay questions · total
                    </span>
                    <div style={{ fontSize: '2.1rem', fontWeight: 800, lineHeight: 1.1, marginTop: 6 }}>
                        {result.autoPossible === 0 ? '—' : `${result.autoPct}%`}
                    </div>
                    <div style={{ color: 'var(--muted)', fontSize: '0.82rem', marginTop: 4 }}>
                        {result.autoEarned} / {result.autoPossible} pts (auto-graded)
                    </div>
                </div>

                <div style={{ background: 'var(--surface, #fff)', border: '1px solid var(--border, #e6e8ec)', borderLeft: '4px solid #d97706', borderRadius: 12, padding: '16px 18px' }}>
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.03em' }}>
                        Essay questions · total
                    </span>
                    <div style={{ fontSize: '2.1rem', fontWeight: 800, lineHeight: 1.1, marginTop: 6 }}>
                        {result.essayPossible === 0 ? '—' : result.essayPendingCount > 0 ? <span style={{ color: '#d97706' }}>Pending</span> : `${result.essayPct}%`}
                    </div>
                    <div style={{ color: 'var(--muted)', fontSize: '0.82rem', marginTop: 4 }}>
                        {result.essayPossible === 0
                            ? 'No essays in this exam'
                            : result.essayPendingCount > 0
                              ? `${result.essayEarned} / ${result.essayPossible} pts graded · ${result.essayPendingCount} awaiting grading`
                              : `${result.essayEarned} / ${result.essayPossible} pts`}
                    </div>
                </div>
            </div>

            <section className="result-dashboard">
                <div className={`score-panel ${result.passed ? 'passed' : 'failed'}`}>
                    <span>{result.passed ? 'Passed' : 'Not passed'}</span>
                    <strong>{result.percentScore}%</strong>
                    <p>
                        Final score {result.finalScore}. Passing grade {result.passingGrade}%.
                    </p>
                </div>

                <div className="breakdown-panel">
                    <div className="section-title-row">
                        <div>
                            <h2>Topic Breakdown</h2>
                            <p>Score aggregation by category.</p>
                        </div>
                        <BarChart3 size={22} aria-hidden />
                    </div>

                    <div className="breakdown-list">
                        {result.topicBreakdown.map((topic: any) => (
                            <div className="breakdown-row" key={topic.topic}>
                                <div>
                                    <span>{topic.topic}</span>
                                    <strong>{topic.percent}%</strong>
                                </div>
                                <progress value={topic.earned} max={topic.possible} />
                                <p>
                                    {topic.correct}/{topic.total} correct
                                </p>
                            </div>
                        ))}
                    </div>

                    {result.examMode === 'try_out' ? (
                        <Link className="primary-button" href={`/student/scores/${encodeURIComponent(result.id)}`}>
                            <Eye size={18} aria-hidden /> Review Exam
                        </Link>
                    ) : (
                        <p style={{ color: 'var(--muted)', fontSize: '0.9rem', margin: '10px 0 0' }}>
                            This exam is in <strong>strict mode</strong>. Per-question review is not available — only your total and topic scores are shown.
                        </p>
                    )}
                </div>
            </section>
        </main>
    );
}
