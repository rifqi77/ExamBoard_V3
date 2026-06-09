export default function Welcome({ message }: { message?: string }) {
    return (
        <div style={{ padding: 40, fontFamily: 'system-ui, sans-serif' }}>
            <h1 style={{ fontSize: 24, fontWeight: 700 }}>Exam Dashboard</h1>
            <p style={{ color: '#475569' }}>{message ?? 'Inertia + React is live.'}</p>
        </div>
    );
}
