import { createElement, useEffect, useRef } from 'react';

type Props = {
    value: string;
    onChange: (latex: string) => void;
    placeholder?: string;
    ariaLabel?: string;
    readOnly?: boolean;
};

// MathLive `<math-field>` essay/math input — port of the original
// exam/MathFieldEditor.tsx. Emits LaTeX on every edit.
export function MathFieldEditor({ value, onChange, placeholder, ariaLabel, readOnly }: Props) {
    const ref = useRef<HTMLElement | null>(null);
    const loadedRef = useRef(false);

    useEffect(() => {
        if (loadedRef.current) return;
        loadedRef.current = true;
        void import('mathlive');
    }, []);

    useEffect(() => {
        const el = ref.current as HTMLElement | null;
        if (!el) return;
        const handler = (event: Event) => {
            const target = event.target as HTMLElement & { value?: string; getValue?: (format?: string) => string };
            const latex =
                typeof target.getValue === 'function'
                    ? target.getValue('latex')
                    : typeof target.value === 'string'
                      ? target.value
                      : '';
            onChange(latex);
        };
        el.addEventListener('input', handler);
        return () => el.removeEventListener('input', handler);
    }, [onChange]);

    useEffect(() => {
        const el = ref.current as
            | (HTMLElement & {
                  value?: string;
                  getValue?: (format?: string) => string;
                  setValue?: (latex: string, options?: { silenceNotifications?: boolean }) => void;
              })
            | null;
        if (!el) return;
        const current = typeof el.getValue === 'function' ? el.getValue('latex') : (el.value ?? '');
        if (current === value) return;
        if (typeof el.setValue === 'function') {
            el.setValue(value, { silenceNotifications: true });
        } else {
            el.value = value;
        }
    }, [value]);

    return createElement('math-field', {
        ref,
        className: 'math-field-editor',
        placeholder,
        'aria-label': ariaLabel,
        ...(readOnly ? { 'read-only': '' } : {}),
    });
}

export default MathFieldEditor;
