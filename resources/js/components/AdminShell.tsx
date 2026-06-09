import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3, BookOpenCheck, CheckCircle2, Clock, FileBarChart, GraduationCap,
    Home, LineChart, Library, LogOut, Radio, ShieldCheck, Sparkles, Users, UserSquare2,
} from 'lucide-react';
import { ReactNode } from 'react';

type NavGroup = 'workspace' | 'people' | 'content' | 'results' | 'system';

const NAV_GROUP_LABELS: Record<NavGroup, string> = {
    workspace: 'Workspace', people: 'People', content: 'Content', results: 'Results', system: 'System',
};
const NAV_GROUP_ORDER: NavGroup[] = ['workspace', 'people', 'content', 'results', 'system'];

const NAV = [
    { href: '/admin', label: 'Overview', icon: Home, match: 'exact', group: 'workspace' },
    { href: '/admin/teachers', label: 'Teachers', icon: UserSquare2, match: 'prefix', group: 'people' },
    { href: '/admin/students', label: 'Students', icon: Users, match: 'prefix', group: 'people' },
    { href: '/admin/learning-objectives', label: 'Curriculum', icon: GraduationCap, match: 'prefix', group: 'content' },
    { href: '/admin/ai-generate', label: 'AI Generation', icon: Sparkles, match: 'prefix', group: 'content' },
    { href: '/admin/bank', label: 'Question Bank', icon: Library, match: 'prefix', group: 'content' },
    { href: '/admin/exams', label: 'Exams', icon: BookOpenCheck, match: 'prefix', group: 'content' },
    { href: '/admin/all-exams', label: 'All Exams', icon: Radio, match: 'prefix', group: 'results' },
    { href: '/admin/auto-score', label: 'Auto Score', icon: CheckCircle2, match: 'prefix', group: 'results' },
    { href: '/admin/pending-score', label: 'Pending Score', icon: Clock, match: 'prefix', group: 'results' },
    { href: '/admin/scores', label: 'Scores', icon: BarChart3, match: 'prefix', group: 'results' },
    { href: '/admin/reports', label: 'Reports', icon: FileBarChart, match: 'prefix', group: 'results' },
    { href: '/admin/analyze', label: 'Analyze', icon: LineChart, match: 'prefix', group: 'results' },
    { href: '/admin/ai-settings', label: 'AI settings', icon: Sparkles, match: 'prefix', group: 'system' },
] as const;

export default function AdminShell({ children }: { children: ReactNode }) {
    const page = usePage();
    const user = (page.props as any).auth?.user;
    const path = page.url.split('?')[0];

    const isActive = (href: string, match: string) =>
        match === 'exact' ? path === href : path === href || path.startsWith(href + '/');

    return (
        <div className="teacher-shell">
            <aside className="teacher-sidebar">
                <div className="teacher-sidebar-brand">
                    <div className="brand-mark">
                        <ShieldCheck size={18} aria-hidden />
                    </div>
                    <div>
                        <strong>Exam Dashboard</strong>
                        <p>Super admin</p>
                    </div>
                </div>

                <nav className="teacher-nav">
                    {NAV_GROUP_ORDER.map((group) => {
                        const items = NAV.filter((n) => n.group === group);
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
                        <span className="teacher-subject-pill admin">Admin</span>
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
