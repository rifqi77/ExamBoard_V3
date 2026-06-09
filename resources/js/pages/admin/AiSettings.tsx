import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, CircleX, Save, Sparkles } from 'lucide-react';
import { useState } from 'react';
import AdminShell from '@/components/AdminShell';

// ---------------------------------------------------------------------------
// Admin · AI settings — port of the original AiSettingsClient.
//
// Three ordered sections:
//   1. API keys        — paste/clear per-provider keys (stored encrypted;
//                         only a masked set/unset status is ever shown).
//   2. Text generation — provider + model dropdowns + temperature slider
//                         with the live value inline in the label.
//   3. Image generation — image provider dropdown.
//
// Settings persist on the app_config_ai singleton via the controller; keys
// go through a separate PATCH so a key save never rewrites the settings.
// ---------------------------------------------------------------------------

const PROVIDER_LABELS: Record<string, string> = {
    pollinations: 'Pollinations.ai (free, no key)',
    gemini: 'Google Gemini',
    claude: 'Anthropic Claude',
    openai: 'OpenAI',
};
const IMAGE_PROVIDER_LABELS: Record<string, string> = {
    off: 'Off (no image generation)',
    pollinations: 'Pollinations.ai (Flux, free, no key)',
    gemini: 'Google Imagen (Gemini)',
    openai: 'OpenAI DALL·E / gpt-image',
};

type KeyStatus = { pollinations: boolean; gemini: boolean; claude: boolean; openai: boolean };

type PageProps = {
    settings: { textProvider: string; textModel: string; temperature: number; imageProvider: string };
    keyStatus: KeyStatus;
    providers: { text: string[]; image: string[]; models: Record<string, string[]> };
    flash: { success?: string | null; error?: string | null };
};

export default function AiSettings() {
    const { settings, keyStatus, providers, flash } = usePage().props as unknown as PageProps;

    const [textProvider, setTextProvider] = useState(settings.textProvider);
    const [textModel, setTextModel] = useState(settings.textModel);
    const [temperature, setTemperature] = useState(settings.temperature);
    const [imageProvider, setImageProvider] = useState(settings.imageProvider);
    const [busy, setBusy] = useState(false);

    // When provider changes, snap model to that provider's first option so we
    // never submit an invalid (provider, model) pair.
    function onProviderChange(next: string) {
        setTextProvider(next);
        const models = providers.models[next] ?? [];
        if (models.length > 0 && !models.includes(textModel)) {
            setTextModel(models[0]);
        }
    }

    function onSave() {
        setBusy(true);
        router.put(
            '/admin/ai-settings',
            { textProvider, textModel, temperature, imageProvider },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    const availableModels = providers.models[textProvider] ?? [];
    const textKeyOk =
        textProvider === 'pollinations' ||
        (textProvider === 'gemini' && keyStatus.gemini) ||
        (textProvider === 'claude' && keyStatus.claude) ||
        (textProvider === 'openai' && keyStatus.openai);
    const imageKeyOk =
        imageProvider === 'off' ||
        imageProvider === 'pollinations' ||
        (imageProvider === 'gemini' && keyStatus.gemini) ||
        (imageProvider === 'openai' && keyStatus.openai);

    return (
        <AdminShell>
            <Head title="Admin · AI settings" />
            <header className="teacher-page-header">
                <div>
                    <h1>AI settings</h1>
                    <p>
                        Pick the provider that the teacher &quot;Generate exam with AI&quot; button calls. Paste keys
                        below — they&apos;re stored encrypted in the database (AES-256-GCM via SESSION_SECRET).{' '}
                        <code>.env</code> still works as a fallback for any provider you leave empty here.
                    </p>
                </div>
            </header>

            {flash?.error ? <p className="form-error">{flash.error}</p> : null}
            {flash?.success ? <p className="form-success">{flash.success}</p> : null}

            {/* 1 · API keys */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>API keys</h2>
                        <p>
                            Paste a new key to overwrite. Leave the field blank and save to clear that provider&apos;s
                            stored key (the env var fallback kicks in next).
                        </p>
                    </div>
                </div>
                <div style={{ display: 'grid', gap: 14 }}>
                    <KeyInputRow provider="gemini" label="Google Gemini" envName="GEMINI_API_KEY" present={keyStatus.gemini} />
                    <KeyInputRow provider="claude" label="Anthropic Claude" envName="ANTHROPIC_API_KEY" present={keyStatus.claude} />
                    <KeyInputRow provider="openai" label="OpenAI" envName="OPENAI_API_KEY" present={keyStatus.openai} />
                </div>
            </section>

            {/* 2 · Text generation */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Text generation</h2>
                        <p>
                            The provider + model used to draft exam questions when a teacher clicks{' '}
                            <strong>Generate now</strong>.
                        </p>
                    </div>
                </div>
                <div className="exam-form-row">
                    <label>
                        Provider
                        <select value={textProvider} onChange={(e) => onProviderChange(e.target.value)}>
                            {providers.text.map((p) => (
                                <option key={p} value={p}>
                                    {PROVIDER_LABELS[p] ?? p}
                                </option>
                            ))}
                        </select>
                        {textKeyOk ? null : (
                            <small style={{ color: 'var(--amber, #b45309)' }}>
                                Selected provider&apos;s API key is not configured.
                            </small>
                        )}
                    </label>
                    <label>
                        Model
                        <select value={textModel} onChange={(e) => setTextModel(e.target.value)}>
                            {availableModels.map((m) => (
                                <option key={m} value={m}>
                                    {m}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Temperature ({temperature.toFixed(2)})
                        <input
                            type="range"
                            min={0}
                            max={2}
                            step={0.1}
                            value={temperature}
                            onChange={(e) => setTemperature(Number(e.target.value))}
                        />
                        <small>
                            0 = deterministic, 1 = balanced, 2 = wild. Most providers cap at 1.0 — values above that are
                            clamped.
                        </small>
                    </label>
                </div>
            </section>

            {/* 3 · Image generation */}
            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Image generation</h2>
                        <p>
                            Off = AI returns only an <code>imagePrompt</code> string and the teacher generates images
                            themselves. Gemini and OpenAI both produce PNGs the server uploads alongside the exam.
                        </p>
                    </div>
                </div>
                <div className="exam-form-row">
                    <label>
                        Image provider
                        <select value={imageProvider} onChange={(e) => setImageProvider(e.target.value)}>
                            {providers.image.map((p) => (
                                <option key={p} value={p}>
                                    {IMAGE_PROVIDER_LABELS[p] ?? p}
                                </option>
                            ))}
                        </select>
                        {imageKeyOk ? null : (
                            <small style={{ color: 'var(--amber, #b45309)' }}>
                                Image provider&apos;s API key is not configured.
                            </small>
                        )}
                    </label>
                </div>
            </section>

            <div style={{ display: 'flex', gap: 10, padding: '0 4px' }}>
                <button className="primary-button" type="button" onClick={onSave} disabled={busy}>
                    <Save size={16} aria-hidden />
                    {busy ? 'Saving…' : 'Save AI settings'}
                </button>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'var(--muted)' }}>
                    <Sparkles size={14} aria-hidden /> Active: {PROVIDER_LABELS[settings.textProvider]} ·{' '}
                    {settings.textModel} · T={settings.temperature.toFixed(2)} · images:{' '}
                    {IMAGE_PROVIDER_LABELS[settings.imageProvider]}
                </span>
            </div>
        </AdminShell>
    );
}

function KeyInputRow({
    provider,
    label,
    envName,
    present,
}: {
    provider: 'gemini' | 'claude' | 'openai';
    label: string;
    envName: string;
    present: boolean;
}) {
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);

    function save(clear: boolean) {
        setBusy(true);
        router.patch(
            '/admin/ai-settings/keys',
            { keys: { [provider]: clear ? '' : value } },
            {
                preserveScroll: true,
                onSuccess: () => setValue(''),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <div
            style={{
                display: 'grid',
                gridTemplateColumns: 'auto 1fr auto auto',
                gap: 10,
                alignItems: 'center',
                padding: '10px 12px',
                border: '1px solid var(--border, #e2e6ee)',
                borderRadius: 'var(--radius, 8px)',
                background: '#fff',
            }}
        >
            {present ? (
                <CheckCircle2 size={18} color="#16a34a" aria-hidden />
            ) : (
                <CircleX size={18} color="#b45309" aria-hidden />
            )}
            <div style={{ display: 'grid', gap: 2 }}>
                <strong>{label}</strong>
                <small style={{ color: 'var(--muted)' }}>
                    <code>{envName}</code> · {present ? 'Configured' : 'Not configured'}
                </small>
            </div>
            <input
                type="password"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={present ? 'Paste a new key to replace' : 'Paste key here'}
                style={{ width: 280, padding: '6px 10px', fontSize: '0.85rem' }}
                autoComplete="off"
                spellCheck={false}
            />
            <div style={{ display: 'flex', gap: 6 }}>
                <button
                    className="primary-button"
                    type="button"
                    onClick={() => save(false)}
                    disabled={busy || value.trim().length === 0}
                    style={{ padding: '6px 12px', fontSize: '0.82rem' }}
                >
                    {busy ? '…' : 'Save'}
                </button>
                {present ? (
                    <button
                        className="ghost-button"
                        type="button"
                        onClick={() => save(true)}
                        disabled={busy}
                        style={{ padding: '6px 10px', fontSize: '0.82rem' }}
                    >
                        Clear
                    </button>
                ) : null}
            </div>
        </div>
    );
}
