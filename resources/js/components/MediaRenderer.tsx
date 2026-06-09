import { useState } from 'react';

export type QMedia = { id: string; type: string; url: string; altText?: string | null; caption?: string | null };

// Google-Drive share-link normalisation + relative-filename join against the
// exam's mediaBaseUrl + absolute pass-through.
export function resolveMediaUrl(url: string, base?: string | null): string {
    if (!url) return url;
    const drive = url.match(/drive\.google\.com\/file\/d\/([^/]+)/);
    if (drive) return `https://drive.google.com/uc?export=view&id=${drive[1]}`;
    const open = url.match(/drive\.google\.com\/open\?id=([^&]+)/);
    if (open) return `https://drive.google.com/uc?export=view&id=${open[1]}`;
    if (/^https?:\/\//.test(url) || /^data:/.test(url)) return url;
    if (base) return base.replace(/\/$/, '') + '/' + url.replace(/^\//, '');
    return url;
}

export function MediaRenderer({ media, mediaBaseUrl }: { media: QMedia; mediaBaseUrl?: string | null }) {
    const [failed, setFailed] = useState(false);
    const src = resolveMediaUrl(media.url, mediaBaseUrl);
    const caption = media.caption || media.altText || undefined;

    if (failed) {
        return <div className="media-unavailable">Media unavailable</div>;
    }

    return (
        <figure className="question-media">
            {media.type === 'image' && <img src={src} alt={media.altText ?? ''} onError={() => setFailed(true)} />}
            {media.type === 'audio' && <audio controls src={src} onError={() => setFailed(true)} />}
            {media.type === 'video' && <video controls src={src} onError={() => setFailed(true)} />}
            {caption && <figcaption>{caption}</figcaption>}
        </figure>
    );
}

export default MediaRenderer;
