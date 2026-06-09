import { usePage, router, Head } from '@inertiajs/react';

export default function Dashboard() {
    const { auth } = usePage().props as any;
    return (
        <div className="p-10">
            <Head title="Student" />
            <h1 className="text-2xl font-bold text-slate-900">Student Home</h1>
            <p className="mt-2 text-slate-600">
                Signed in as <strong>{auth.user?.fullName}</strong> ({auth.user?.role})
            </p>
            <button
                onClick={() => router.post('/logout')}
                className="mt-6 rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-900"
            >
                Log out
            </button>
        </div>
    );
}
