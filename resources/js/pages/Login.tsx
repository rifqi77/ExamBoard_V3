import { useForm, Head } from '@inertiajs/react';
import { LockKeyhole, ShieldCheck } from 'lucide-react';
import { FormEvent } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ username: '', password: '' });

    function onSubmit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <main className="auth-shell auth-shell--centered">
            <Head title="Sign in" />
            <section className="auth-panel">
                <div className="brand-lockup">
                    <div className="brand-mark">
                        <ShieldCheck size={24} aria-hidden />
                    </div>
                    <div>
                        <h1>Exam Dashboard</h1>
                        <p>Sign in with your account to continue.</p>
                    </div>
                </div>

                <form className="login-form" onSubmit={onSubmit}>
                    <label>
                        Username
                        <input
                            autoComplete="username"
                            value={data.username}
                            onChange={(e) => setData('username', e.target.value)}
                            required
                            autoFocus
                        />
                    </label>

                    <label>
                        Password
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                    </label>

                    {errors.username ? <p className="form-error">{errors.username}</p> : null}

                    <button className="primary-button" type="submit" disabled={processing}>
                        <LockKeyhole size={18} aria-hidden />
                        {processing ? 'Signing in...' : 'Sign in'}
                    </button>
                </form>
            </section>
        </main>
    );
}
