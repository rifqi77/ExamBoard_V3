import { Head } from '@inertiajs/react';
import AdminShell from '@/components/AdminShell';
import { AiGenerateBody } from '@/pages/teacher/AiGenerate';

// ---------------------------------------------------------------------------
// Admin · Generate exam with AI.
//
// Same generator as the teacher page, wrapped in AdminShell. The body lives in
// pages/teacher/AiGenerate.tsx (exported as AiGenerateBody) and is driven by
// the `basePath` page prop ("/admin" here), so every endpoint + back-link
// targets the admin routes. The controller sets isAdmin + grants every
// capability, so admins bypass all per-teacher AI gates.
// ---------------------------------------------------------------------------

export default function AdminAiGenerate() {
    return (
        <AdminShell>
            <Head title="Admin · Generate exam with AI" />
            <AiGenerateBody />
        </AdminShell>
    );
}
