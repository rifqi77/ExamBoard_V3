<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExamAccessController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\Teacher\BankController as TeacherBankController;
use App\Http\Controllers\Teacher\ClassController as TeacherClassController;
use App\Http\Controllers\Teacher\ExamDetailController as TeacherExamDetailController;
use App\Http\Controllers\Teacher\ExamManageController as TeacherExamManageController;
use App\Http\Controllers\Teacher\LearningObjectiveController as TeacherLOController;
use App\Http\Controllers\Teacher\ReportsController as TeacherReportsController;
use App\Http\Controllers\Teacher\ScoresController as TeacherScoresController;
use App\Http\Controllers\Teacher\StudentController as TeacherStudentController;
use App\Http\Controllers\Teacher\TeacherController;
use App\Http\Controllers\AiGenerateController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\AnalyzeController as AdminAnalyzeController;
use App\Http\Controllers\Admin\ContentController as AdminContentController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ExamController as AdminExamController;
use App\Http\Controllers\Admin\ReportsController as AdminReportsController;
use App\Http\Controllers\Admin\ScoresController as AdminScoresController;
use App\Http\Controllers\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Admin\TeacherController as AdminTeacherController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// --- Public ---
Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/api/health', fn () => response()->json(['ok' => true, 'ts' => now()->toIso8601String()]));

// --- Impersonation ---
Route::post('/admin/impersonate/{uid}', [ImpersonationController::class, 'start'])
    ->middleware(['auth.session', 'role:admin']);
Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth.session');

// --- Admin console ---
Route::middleware(['auth.session', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    // Teachers (+ capabilities)
    Route::get('/teachers', [AdminTeacherController::class, 'index'])->name('admin.teachers');
    Route::post('/teachers', [AdminTeacherController::class, 'store']);
    Route::patch('/teachers/{uid}', [AdminTeacherController::class, 'update']);
    Route::delete('/teachers/{uid}', [AdminTeacherController::class, 'destroy']);
    Route::patch('/teachers/{uid}/capabilities', [AdminTeacherController::class, 'updateCapabilities']);

    // Students (school-wide)
    Route::get('/students', [AdminStudentController::class, 'index'])->name('admin.students');
    Route::get('/students/groups', [AdminStudentController::class, 'groups']);
    Route::patch('/students/{uid}', [AdminStudentController::class, 'update']);
    Route::post('/students/bulk', [AdminStudentController::class, 'bulk']);

    // Analyze (system dashboard + item analysis)
    Route::get('/analyze', [AdminAnalyzeController::class, 'index'])->name('admin.analyze');

    // AI settings + generate
    Route::get('/ai-settings', [AiSettingsController::class, 'show'])->name('admin.ai-settings');
    Route::put('/ai-settings', [AiSettingsController::class, 'update']);
    Route::patch('/ai-settings/keys', [AiSettingsController::class, 'updateKeys']);
    Route::get('/ai-generate', [AiGenerateController::class, 'showAdmin'])->name('admin.ai-generate');
    Route::get('/ai-generate/status', [AiGenerateController::class, 'status']);
    Route::get('/ai-generate/learning-objectives', [AiGenerateController::class, 'learningObjectives']);
    Route::post('/ai-generate/prompt', [AiGenerateController::class, 'prompt']);
    Route::post('/ai-generate/run', [AiGenerateController::class, 'run']);

    // Content: question bank (school-wide)
    Route::get('/bank', [AdminContentController::class, 'bank'])->name('admin.bank');
    Route::post('/bank', [AdminContentController::class, 'bankStore']);
    Route::post('/bank/upload', [AdminContentController::class, 'bankUpload']);
    Route::put('/bank/{id}', [AdminContentController::class, 'bankUpdate']);
    Route::delete('/bank/{id}', [AdminContentController::class, 'bankDestroy']);

    // Content: curriculum / learning objectives (school-wide)
    Route::get('/learning-objectives', [AdminContentController::class, 'learningObjectives'])->name('admin.learning-objectives');
    Route::post('/learning-objectives', [AdminContentController::class, 'loStore']);
    Route::post('/learning-objectives/upload', [AdminContentController::class, 'loUpload']);
    Route::post('/learning-objectives/bulk-delete', [AdminContentController::class, 'loBulkDelete']);
    Route::patch('/learning-objectives/{id}', [AdminContentController::class, 'loUpdate']);
    Route::delete('/learning-objectives/{id}', [AdminContentController::class, 'loDestroy']);

    // Scores + grading (school-wide) + auto/pending. Literals before {submissionId}.
    Route::get('/scores', [AdminScoresController::class, 'index'])->name('admin.scores');
    Route::post('/submissions/bulk-delete', [AdminScoresController::class, 'bulkDelete']);
    Route::get('/auto-score', [AdminContentController::class, 'autoScore']);
    Route::get('/pending-score', [AdminContentController::class, 'pendingScore']);
    Route::post('/grade-bulk', [AdminContentController::class, 'gradeBulk']);
    Route::get('/scores/{submissionId}', [AdminScoresController::class, 'show']);
    Route::post('/scores/{submissionId}/grade', [AdminScoresController::class, 'grade']);

    // Reports (school-wide + Excel export)
    Route::get('/reports', [AdminReportsController::class, 'index'])->name('admin.reports');
    Route::post('/reports/export', [AdminReportsController::class, 'export']);

    // Exams: list + all-exams + per-exam (literals before {examId})
    Route::get('/exams', [AdminExamController::class, 'index'])->name('admin.exams');
    Route::get('/all-exams', [AdminExamController::class, 'allExams'])->name('admin.all-exams');
    Route::get('/exams/new', [AdminExamController::class, 'create']);
    Route::post('/exams', [AdminExamController::class, 'store']);
    Route::post('/exams/import', [AdminExamController::class, 'importPackage']);
    Route::post('/exams/tokens/{tokenId}/regenerate', [AdminExamController::class, 'regenerateToken']);
    Route::delete('/exams/tokens/{tokenId}', [AdminExamController::class, 'deleteToken']);
    Route::post('/exams/{examId}/scores/delete-all', [AdminScoresController::class, 'deleteAllForExam']);
    Route::get('/exams/{examId}', [AdminExamController::class, 'examDetail'])->name('admin.exams.show');
    Route::get('/exams/{examId}/edit', [AdminExamController::class, 'editSettings']);
    Route::delete('/exams/{examId}', [AdminExamController::class, 'destroy']);
    Route::get('/exams/{examId}/live', [AdminExamController::class, 'live']);
    Route::get('/exams/{examId}/live-scores', [AdminExamController::class, 'liveScores']);
    Route::get('/exams/{examId}/audit', [AdminExamController::class, 'audit']);
    Route::get('/exams/{examId}/answer-audit', [AdminExamController::class, 'answerAudit']);
});

// --- Teacher console ---
Route::middleware(['auth.session', 'role:teacher'])->prefix('teacher')->group(function () {
    Route::get('/', [TeacherController::class, 'dashboard'])->name('teacher.dashboard');

    // Students + classes
    Route::get('/students', [TeacherStudentController::class, 'index'])->name('teacher.students');
    Route::get('/students/groups', [TeacherStudentController::class, 'groups']);
    Route::post('/students', [TeacherStudentController::class, 'store']);
    Route::post('/students/bulk', [TeacherStudentController::class, 'bulk']);
    Route::post('/students/bulk-create', [TeacherStudentController::class, 'bulkCreate']);
    Route::patch('/students/{uid}', [TeacherStudentController::class, 'update']);
    Route::delete('/students/{uid}', [TeacherStudentController::class, 'destroy']);
    Route::post('/classes/parse', [TeacherClassController::class, 'parse']);
    Route::post('/classes/import', [TeacherClassController::class, 'import']);
    Route::get('/classes/{classId}', [TeacherClassController::class, 'show']);

    // Question bank
    Route::get('/bank', [TeacherBankController::class, 'index'])->name('teacher.bank');
    Route::post('/bank', [TeacherBankController::class, 'store']);
    Route::post('/bank/upload', [TeacherBankController::class, 'upload']);
    Route::get('/bank-picker', [TeacherExamDetailController::class, 'bankQuestions']);
    Route::get('/bank-picker/options', [TeacherExamDetailController::class, 'bankOptions']);
    Route::put('/bank/{id}', [TeacherBankController::class, 'update']);
    Route::delete('/bank/{id}', [TeacherBankController::class, 'destroy']);

    // Scores + grading
    Route::get('/scores', [TeacherScoresController::class, 'index'])->name('teacher.scores');
    Route::get('/auto-score', [TeacherScoresController::class, 'autoScore']);
    Route::get('/pending-score', [TeacherScoresController::class, 'pendingScore']);
    Route::get('/scores/{submissionId}', [TeacherScoresController::class, 'show']);
    Route::post('/scores/{submissionId}/grade', [TeacherScoresController::class, 'grade']);
    Route::post('/grade-bulk', [TeacherScoresController::class, 'gradeBulk']);
    Route::post('/submissions/bulk-delete', [TeacherScoresController::class, 'bulkDelete']);

    // Reports + curriculum
    Route::get('/reports', [TeacherReportsController::class, 'index'])->name('teacher.reports');
    Route::post('/reports/export', [TeacherReportsController::class, 'export']);
    Route::get('/learning-objectives', [TeacherLOController::class, 'index'])->name('teacher.learning-objectives');
    Route::post('/learning-objectives', [TeacherLOController::class, 'store']);
    Route::post('/learning-objectives/upload', [TeacherLOController::class, 'upload']);
    Route::post('/learning-objectives/bulk-delete', [TeacherLOController::class, 'bulkDelete']);
    Route::patch('/learning-objectives/{id}', [TeacherLOController::class, 'update']);
    Route::delete('/learning-objectives/{id}', [TeacherLOController::class, 'destroy']);

    // Exams: list / create / import / token actions (literals before {examId})
    Route::get('/exams', [TeacherExamManageController::class, 'index'])->name('teacher.exams');
    Route::get('/exams/new', [TeacherExamManageController::class, 'create']);
    Route::post('/exams', [TeacherExamManageController::class, 'store']);
    Route::post('/exams/import', [TeacherExamManageController::class, 'importPackage']);
    Route::post('/exams/tokens/{tokenId}/regenerate', [TeacherExamManageController::class, 'regenerateToken']);
    Route::delete('/exams/tokens/{tokenId}', [TeacherExamManageController::class, 'deleteToken']);

    // Exam detail (by id)
    Route::get('/exams/{examId}', [TeacherExamDetailController::class, 'show'])->name('teacher.exams.show');
    Route::get('/exams/{examId}/detail', [TeacherExamDetailController::class, 'detail']);
    Route::get('/exams/{examId}/edit', [TeacherExamManageController::class, 'editSettings']);
    Route::patch('/exams/{examId}', [TeacherExamManageController::class, 'updateSettings']);
    Route::delete('/exams/{examId}', [TeacherExamManageController::class, 'destroy']);
    Route::get('/exams/{examId}/questions', [TeacherExamDetailController::class, 'questions']);
    Route::post('/exams/{examId}/questions', [TeacherExamDetailController::class, 'addQuestion']);
    Route::post('/exams/{examId}/questions/from-bank', [TeacherExamDetailController::class, 'addFromBank']);
    Route::patch('/exams/{examId}/questions/{qId}', [TeacherExamDetailController::class, 'updateQuestion']);
    Route::delete('/exams/{examId}/questions/{qId}', [TeacherExamDetailController::class, 'deleteQuestion']);
    Route::post('/exams/{examId}/questions/{qId}/replace', [TeacherExamDetailController::class, 'replaceFromBank']);
    Route::post('/exams/{examId}/auto-fill', [TeacherExamDetailController::class, 'autoFill']);
    Route::get('/exams/{examId}/tokens', [TeacherExamDetailController::class, 'tokens']);
    Route::post('/exams/{examId}/tokens', [TeacherExamDetailController::class, 'createToken']);
    Route::patch('/exams/{examId}/seb', [TeacherExamDetailController::class, 'setSeb']);
    Route::post('/exams/{examId}/finalize-drafts', [TeacherExamDetailController::class, 'finalizeDrafts']);
    Route::post('/exams/{examId}/reset-session', [TeacherExamDetailController::class, 'resetSession']);
    Route::get('/exams/{examId}/submissions', [TeacherExamDetailController::class, 'submissions']);
    Route::get('/exams/{examId}/bank-in-exam', [TeacherExamDetailController::class, 'bankInExam']);
    Route::post('/exams/{examId}/scores/delete-all', [TeacherScoresController::class, 'deleteAllForExam']);

    // Token actions (exam-detail page)
    Route::delete('/tokens/{tokenId}', [TeacherExamDetailController::class, 'deleteToken']);
    Route::post('/tokens/{tokenId}/regenerate', [TeacherExamDetailController::class, 'regenerateToken']);

    // AI generate (controller gates ai.generate capability; admin bypasses)
    Route::get('/ai-generate', [AiGenerateController::class, 'showTeacher'])->name('teacher.ai-generate');
    Route::get('/ai-generate/status', [AiGenerateController::class, 'status']);
    Route::get('/ai-generate/learning-objectives', [AiGenerateController::class, 'learningObjectives']);
    Route::post('/ai-generate/prompt', [AiGenerateController::class, 'prompt']);
    Route::post('/ai-generate/run', [AiGenerateController::class, 'run']);
});

// --- Student ---
Route::middleware(['auth.session', 'role:student'])->group(function () {
    Route::get('/student', [StudentController::class, 'home'])->name('student.home');
    Route::get('/student/scores', [SubmissionController::class, 'studentScores']);
    Route::get('/student/scores/{id}', [SubmissionController::class, 'studentScoreDetail']);
    Route::get('/results/{id}', [SubmissionController::class, 'result']);
});

// --- Exam taking (any signed-in user; exam-access cookie gates non-admins) ---
Route::middleware('auth.session')->group(function () {
    Route::get('/token', [ExamAccessController::class, 'showTokenEntry']);
    Route::post('/token', [ExamAccessController::class, 'validateToken']);
    Route::get('/exams/{examId}', [ExamController::class, 'show']);
    // Per-attempt resume — re-issues an exam-access cookie + drops the student
    // back on the exam page with answers intact. Survives cookie expiry.
    Route::get('/exams/{examId}/resume/{resumeToken}', [ExamAccessController::class, 'resume']);
    Route::post('/exams/{examId}/submit', [ExamController::class, 'submit']);
    Route::put('/api/exams/{examId}/draft', [ExamController::class, 'saveDraft']);
    Route::post('/api/exams/{examId}/events', [ExamController::class, 'events']);
});
