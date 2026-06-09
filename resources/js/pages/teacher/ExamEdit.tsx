import { Head, useForm, usePage } from '@inertiajs/react';
import TeacherShell from '@/components/TeacherShell';
import { ExamForm, isoToLocalInput, type ExamFormData } from './ExamCreate';

// Edit-settings page. Reuses the exported <ExamForm> from ExamCreate so the
// markup is a single source of truth across both flows (parity with the
// original CreateExamClient which handled create + edit in one component).
//
// Exam code + display name are NOT editable post-creation (the original
// PATCH route refuses to touch them), so they're hidden in edit mode. The
// form seeds from the saved exam — no localStorage draft.
export default function ExamEdit() {
    const { gates, subjectChoices, exam } = usePage().props as any;

    const form = useForm<ExamFormData>({
        examCode: exam.examId,
        name: exam.name,
        durationMinutes: exam.durationMinutes,
        passingGrade: exam.passingGrade,
        generalInstructions: exam.generalInstructions ?? '',
        examMode: exam.examMode,
        shuffleQuestions: exam.shuffleQuestions,
        shuffleOptions: exam.shuffleOptions,
        language: exam.language,
        subject: exam.subject ?? '',
        mediaBaseUrl: exam.mediaBaseUrl ?? '',
        startTime: isoToLocalInput(exam.startTime),
        endTime: isoToLocalInput(exam.endTime),
        sebRequired: exam.sebRequired,
        typeDistribution: exam.typeDistribution,
        difficultyDistribution: exam.difficultyDistribution,
        mediaTargets: exam.mediaTargets,
    });

    function onSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(`/teacher/exams/${exam.examId}`);
    }

    return (
        <TeacherShell>
            <Head title="Teacher · Edit exam settings" />
            <ExamForm
                mode="edit"
                form={form}
                gates={gates}
                subjectChoices={subjectChoices as string[]}
                onSubmit={onSubmit}
                backHref={`/teacher/exams/${exam.examId}`}
                examName={exam.name}
            />
        </TeacherShell>
    );
}
