import { useEffect, useState } from 'react';

// Renders student-supplied LaTeX read-only via MathLive's converter —
// port of the original teacher/MathLatexView.tsx. Falls back to the raw
// latex string if MathLive fails to load.
export function MathLatexView({ latex }: { latex: string }) {
    const [markup, setMarkup] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const mod = await import('mathlive');
                if (cancelled) return;
                setMarkup(mod.convertLatexToMarkup(latex));
            } catch {
                if (!cancelled) setFailed(true);
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [latex]);

    if (failed) return <span>{latex}</span>;
    if (markup === null) return <span style={{ color: 'var(--muted)' }}>{latex}</span>;

    return <span className="math-latex-view" dangerouslySetInnerHTML={{ __html: markup }} />;
}

export default MathLatexView;
