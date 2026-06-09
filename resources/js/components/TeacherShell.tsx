import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftCircle, BarChart3, BookOpenCheck, CheckCircle2, Clock, FileBarChart,
    GraduationCap, Home, Library, LogOut, ShieldCheck, Sparkles, Users,
} from 'lucide-react';
import { ReactNode } from 'react';

type NavGroup = 'workspace' | 'class' | 'content' | 'results';

const NAV_GROUP_LABELS: Record<NavGroup, string> = {
    workspace: 'Workspace', class: 'Class', content: 'Content', results: 'Results',
};
const NAV_GROUP_ORDER: NavGroup[] = ['workspace', 'class', 'content', 'results'];

const NAV = [
    { href: '/teacher', label: 'Overview', icon: Home, match: 'exact', group: 'workspace' },
    { href: '/teacher/students', label: 'Students', icon: Users, match: 'prefix', group: 'class' },
    { href: '/teacher/learning-objectives', label: 'Curriculum', icon: GraduationCap, match: 'prefix', group: 'content', requiresCap: 'curriculum.manage' },
    { href: '/teacher/ai-generate', label: 'AI Generation', icon: Sparkles, match: 'prefix', group: 'content', requiresCap: 'ai.generate' },
    { href: '/teacher/bank', label: 'Question Bank', icon: Library, match: 'prefix', group: 'content' },
    { href: '/teacher/exams', label: 'Exams', icon: BookOpenCheck, match: 'prefix', group: 'content' },
    { href: '/teacher/auto-score', label: 'Auto Score', icon: CheckCircle2, match: 'prefix', group: 'results' },
    { href: '/teacher/pending-score', label: 'Pending Score', icon: Clock, match: 'prefix', group: 'results' },
    { href: '/teacher/scores', label: 'Scores', icon: BarChart3, match: 'prefix', group: 'results' },
    { href: '/teacher/reports', label: 'Reports', icon: FileBarChart, match: 'prefix', group: 'results' },
] as const;

export default function TeacherShell({ children }: { children: ReactNode }) {
    const page = usePage();
    const auth = (page.props as any).auth;
    const user = auth?.user;
    const impersonator = auth?.impersonator;
    const path = page.url.split('?')[0];

    const isActive = (href: string, match: string) =>
        match === 'exact' ? path === href : path === href || path.startsWith(href + '/');

    const caps = user?.capabilities ?? {};
    const hasCap = (key?: string) => !key || caps[key] === true || (typeof caps[key] === 'number' && caps[key] > 0);

    return (
        <div className="teacher-shell">
            {impersonator && (
                <div className="impersonation-banner">
                    <span>
                        Viewing as <strong>{user?.fullName}</strong> (<code>{user?.username}</code>) — actions affect this teacher&apos;s account.
                    </span>
                    <button className="ghost-button" type="button" onClick={() => router.post('/impersonate/stop')}>
                        <ArrowLeftCircle size={15} aria-hidden /> Return to admin
                    </button>
                </div>
            )}
            <aside className="teacher-sidebar">
                <div className="teacher-sidebar-brand">
                    <div className="brand-mark">
                        <ShieldCheck size={18} aria-hidden />
                    </div>
                    <div>
                        <strong>Exam Dashboard</strong>
                        <p>Teacher</p>
                    </div>
                </div>

                <nav className="teacher-nav">
                    {NAV_GROUP_ORDER.map((group) => {
                        const items = NAV.filter((n) => n.group === group).filter((item) => hasCap((item as any).requiresCap));
                        if (items.length === 0) return null;
                        return (
                            <div className="nav-group" key={group}>
                                <span className="nav-group-label">{NAV_GROUP_LABELS[group]}</span>
                                {items.map((item) => {
                                    const Icon = item.icon;
                                    return (
                                        <Link key={item.href} href={item.href} className={isActive(item.href, item.match) ? 'active' : ''}>
                                            <Icon size={17} aria-hidden />
                                            {item.label}
                                        </Link>
                                    );
                                })}
                            </div>
                        );
                    })}
                </nav>

                <div className="teacher-sidebar-footer">
                    <div className="teacher-user-card">
                        <strong>{user?.fullName}</strong>
                        <span>{user?.username}</span>
                        {user?.role === 'admin' && <span className="teacher-subject-pill admin">Admin</span>}
                    </div>
                    <button className="ghost-button" type="button" onClick={() => router.post('/logout')}>
                        <LogOut size={15} aria-hidden /> Sign out
                    </button>
                </div>
            </aside>

            <main className="teacher-main">{children}</main>
        </div>
    );
}
