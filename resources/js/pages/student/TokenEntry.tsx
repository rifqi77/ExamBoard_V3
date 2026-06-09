import { useForm, usePage, router, Head } from '@inertiajs/react';
import { KeyRound, LogOut, ShieldCheck } from 'lucide-react';
import { FormEvent, useEffect } from 'react';

export default function TokenEntry() {
    const { auth } = usePage().props as any;
    const { data, setData, post, processing, errors } = useForm({ token: '' });

    useEffect(() => {
        const t = new URLSearchParams(window.location.search).get('token');
        if (t) setData('token', t.toUpperCase());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function onSubmit(e: FormEvent) {
        e.preventDefault();
        post('/token');
    }

    return (
        <main className="auth-shell">
            <Head title="Enter token" />
            <section className="auth-panel">
                <div className="brand-lockup">
                    <div className="brand-mark">
                        <ShieldCheck size={24} aria-hidden />
                    </div>
                    <div>
                        <h1>Exam Dashboard</h1>
                        <p>
                            Welcome, <strong>{auth.user?.fullName}</strong>. Enter the exam token provided by your teacher.
                        </p>
                    </div>
                </div>

                <form className="login-form" onSubmit={onSubmit}>
                    <label>
                        Exam token
                        <input
                            autoCapitalize="characters"
                            autoComplete="off"
                            value={data.token}
                            onChange={(e) => setData('token', e.target.value.toUpperCase())}
                            placeholder="MATH-2026"
                            required
                            autoFocus
                        />
                    </label>

                    {errors.token ? <p className="form-error">{errors.token}</p> : null}

                    <button className="primary-button" type="submit" disabled={processing}>
                        <KeyRound size={18} aria-hidden />
                        {processing ? 'Verifying token' : 'Start exam'}
                    </button>

                    <button
                        className="ghost-button"
                        type="button"
                        onClick={() => router.post('/logout')}
                        style={{ marginTop: '0.75rem', justifyContent: 'center' }}
                    >
                        <LogOut size={17} aria-hidden />
                        Sign out
                    </button>
                </form>
            </section>

            <aside className="auth-context" aria-label="Token notes">
                <div>
                    <span className="context-number">2</span>
                    <p>Different exam, different token. Make sure the token matches the exam you&apos;re about to take.</p>
                </div>
                <div>
                    <span className="context-number">15s</span>
                    <p>Answers are saved automatically on every change and every 15 seconds.</p>
                </div>
                <div>
                    <span className="context-number">1x</span>
                    <p>The token binds to your account on validation. Do not share it with other students.</p>
                </div>
            </aside>
        </main>
    );
}
