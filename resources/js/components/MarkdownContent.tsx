import ReactMarkdown from 'react-markdown';
import rehypeKatex from 'rehype-katex';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';

// Renders prompt / explanation / essay markdown with GFM tables + KaTeX math
// ($…$ and $$…$$). No raw-HTML passthrough — untrusted teacher/AI text.
// (katex CSS is imported globally in app.css.)
export function MarkdownContent({ text, className }: { text: string; className?: string }) {
    return (
        <div className={className ? `markdown-content ${className}` : 'markdown-content'}>
            <ReactMarkdown remarkPlugins={[remarkGfm, remarkMath]} rehypePlugins={[rehypeKatex]}>
                {text || ''}
            </ReactMarkdown>
        </div>
    );
}

export default MarkdownContent;
