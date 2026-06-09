import { useEffect, useRef, useState, Dispatch, SetStateAction } from 'react';

/**
 * Per-user UI-state persistence — drop-in replacement for `useState` whose
 * value survives navigation, sign-out, sign-in, and full page reloads.
 *
 * Mirrors the original Next.js app's `useUIState` hook (memory note: "many
 * components lost cross-nav persistence" in the new app — this restores it).
 *
 * • Storage: `localStorage`, keyed by **(username, pageKey)** so user A
 *   never sees user B's filters / tabs / expansion state.
 * • Auto-restores on mount, auto-saves on every change.
 * • Falls back gracefully when localStorage is unavailable / full / on the
 *   public Login page (no auth user yet — falls back to `__anonymous__`).
 * • Safe across page reloads: the username is re-read from the Inertia
 *   data-page payload, so impersonation / role switches get fresh state.
 *
 * **DO use for**: filter strings, dropdown selections, active tab, open
 * tree nodes, "show technical IDs" toggles, sort preferences, in-progress
 * form values the user might want to keep across navigation.
 *
 * **DO NOT use for**: passwords, sensitive credentials, ephemeral flags
 * like `busy`/`submitting`/`dirty`, modal open/close (those should reset).
 *
 * Usage:
 *   const [year, setYear] = useUIState('teacher.scores.year', currentAcademicYear);
 *   const [openTopics, setOpenTopics] = useUIState<string[]>('teacher.lo.openTopics', []);
 */

const STORAGE_PREFIX = 'ui_state::';

/** Read the current Inertia auth username from the data-page script tag. */
function currentUserKey(): string {
    if (typeof document === 'undefined') return '__anonymous__';
    try {
        const script = document.querySelector('script[data-page]');
        if (!script) return '__anonymous__';
        const page = JSON.parse(script.textContent || '{}');
        const username = page?.props?.auth?.user?.username;
        if (typeof username === 'string' && username.length > 0) {
            return username;
        }
        return '__anonymous__';
    } catch {
        return '__anonymous__';
    }
}

function buildKey(userKey: string, key: string): string {
    return `${STORAGE_PREFIX}${userKey}::${key}`;
}

function readStored<T>(userKey: string, key: string, fallback: T): T {
    if (typeof window === 'undefined' || !window.localStorage) return fallback;
    try {
        const raw = window.localStorage.getItem(buildKey(userKey, key));
        if (raw === null) return fallback;
        return JSON.parse(raw) as T;
    } catch {
        return fallback;
    }
}

function writeStored<T>(userKey: string, key: string, value: T): void {
    if (typeof window === 'undefined' || !window.localStorage) return;
    try {
        window.localStorage.setItem(buildKey(userKey, key), JSON.stringify(value));
    } catch {
        // localStorage quota exceeded — silently ignore, value still lives
        // in React state for this session.
    }
}

/**
 * Like React.useState, but the value is persisted to localStorage (scoped to
 * the signed-in user) so it survives navigation, sign-in/out, and reloads.
 *
 * @param key      Stable identifier for this piece of state (e.g.
 *                 `'teacher.lo.activeTab'`). Same key from two different
 *                 places shares state — keep keys unique per UI piece.
 * @param initial  Value to fall back to when no stored value exists.
 */
export function useUIState<T>(
    key: string,
    initial: T | (() => T),
): [T, Dispatch<SetStateAction<T>>] {
    // Snapshot the username at mount time. Components re-mount on full page
    // reload (which is what happens on sign-in/out), so this stays correct.
    const userKeyRef = useRef<string>(currentUserKey());

    const [value, setValue] = useState<T>(() => {
        const fallback = typeof initial === 'function' ? (initial as () => T)() : initial;
        return readStored<T>(userKeyRef.current, key, fallback);
    });

    // Persist on every change. Skip the first render — initial restore
    // already happened in useState's lazy initializer.
    const firstRender = useRef(true);
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        writeStored(userKeyRef.current, key, value);
    }, [value, key]);

    return [value, setValue];
}

/** Wipe ALL persisted UI state for ALL users — useful in tests or when the
 *  user explicitly clicks a "Reset interface" button. */
export function clearAllUIState(): void {
    if (typeof window === 'undefined' || !window.localStorage) return;
    const toRemove: string[] = [];
    for (let i = 0; i < window.localStorage.length; i++) {
        const k = window.localStorage.key(i);
        if (k && k.startsWith(STORAGE_PREFIX)) toRemove.push(k);
    }
    toRemove.forEach((k) => window.localStorage.removeItem(k));
}
