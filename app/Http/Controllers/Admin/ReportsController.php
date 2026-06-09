<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassStudent;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin Reports — school-wide port of the teacher reports route +
 * report-excel.ts. The on-screen matrix (index) and the .xlsx export
 * (export) share one server-side aggregator so the numbers always match.
 *
 * Visibility:
 *   - default: EVERY teacher's exams + students (admin sees all)
 *   - ?teacherId=: narrows to one teacher (exams/students created_by = that id)
 *
 * The per-class matrix + Excel workbook are identical in shape to the teacher
 * version; the only difference is the scope and a teacher picker on the page.
 */
class ReportsController extends Controller
{
    /** Column keys that can be toggled into the workbook. */
    private const COLUMN_KEYS = [
        'username', 'perExam', 'average', 'passed', 'pending', 'strongest', 'weakest',
    ];

    /** GET /admin/reports — on-screen per-class / per-exam matrix. */
    public function index(Request $request)
    {
        $teacherFilter = $this->scopeTeacherId($request);

        return Inertia::render('admin/Reports', [
            'report' => $this->buildReport($teacherFilter),
            'currentAcademicYear' => AcademicYear::current(),
            'teachers' => $this->teacherOptions(),
            'teacherId' => $teacherFilter,
        ]);
    }

    /**
     * POST /admin/reports/export — build the workbook for the chosen classes
     * + columns (optionally scoped to ?teacherId=) and stream it back.
     *
     * Body:
     *   classIds: class id strings; "No class" bucket = literal "__no_class__".
     *   columns:  map<columnKey, bool> (omitted keys default to true)
     *   label:    human label used in the workbook Title + flash toast
     *   filename: download filename (client already slugified it)
     */
    public function export(Request $request): StreamedResponse
    {
        $teacherFilter = $this->scopeTeacherId($request);
        $report = $this->buildReport($teacherFilter);

        $requestedRaw = $request->input('classIds', []);
        $requested = is_array($requestedRaw) ? array_values($requestedRaw) : [];
        $wantAll = count($requested) === 0;

        $columns = $this->resolveColumns($request->input('columns', []));
        $label = (string) ($request->input('label') ?: 'classes');
        $filename = $this->safeFilename((string) ($request->input('filename') ?: 'report.xlsx'));

        $author = $request->user()->full_name ?? 'Administrator';

        $selected = array_values(array_filter($report['classes'], function ($cls) use ($requested, $wantAll) {
            $key = $cls['classId'] ?? '__no_class__';

            return $wantAll || in_array($key, $requested, true)
                || ($cls['classId'] !== null && in_array($cls['classId'], $requested, true));
        }));

        $spreadsheet = $this->buildWorkbook($report['exams'], $selected, $columns, $author, $label);
        $writer = new XlsxWriter($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ------------------------------------------------------------------
    // Aggregation (shared by index + export)
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   exams: array<int,array{examDatabaseId:string,examId:string,examName:string,passingGrade:int}>,
     *   classes: array<int,array{classId:?string,className:string,academicYear:?string,studentCount:int,students:array<int,array<string,mixed>>}>
     * }
     */
    private function buildReport(?string $teacherFilter): array
    {
        $examQuery = Exam::query();
        if ($teacherFilter) {
            $examQuery->where('created_by', $teacherFilter);
        }
        $examsRows = $examQuery->orderBy('name')
            ->get(['id', 'exam_code', 'name', 'passing_grade']);

        $studentQuery = User::query()->where('role', 'student');
        if ($teacherFilter) {
            $studentQuery->where('created_by', $teacherFilter);
        }
        $studentRows = $studentQuery->orderBy('full_name')
            ->get(['id', 'full_name', 'username']);

        $examCols = $examsRows->map(fn ($e) => [
            'examDatabaseId' => $e->id,
            'examId' => $e->exam_code,
            'examName' => $e->name,
            'passingGrade' => (int) $e->passing_grade,
        ])->values()->all();
        $examIds = array_column($examCols, 'examDatabaseId');
        $examIdSet = array_flip($examIds);

        if (count($examCols) === 0 || $studentRows->isEmpty()) {
            $classes = $studentRows->isEmpty()
                ? []
                : [[
                    'classId' => null,
                    'className' => 'No class',
                    'academicYear' => null,
                    'studentCount' => $studentRows->count(),
                    'students' => $studentRows
                        ->map(fn ($s) => $this->buildStudentRow($s, collect(), $examIdSet))
                        ->all(),
                ]];

            return ['exams' => $examCols, 'classes' => $classes];
        }

        $studentIds = $studentRows->pluck('id')->all();

        $subs = ExamSubmission::query()
            ->whereIn('user_id', $studentIds)
            ->whereIn('exam_id', $examIds)
            ->get(['exam_id', 'user_id', 'percent_score', 'passed', 'pending_essay_count', 'topic_breakdown']);
        $subsByStudent = $subs->groupBy('user_id');

        // Bucket students by their first class (admin sees every class; the
        // synthetic "No class" bucket catches students with no membership).
        $memberships = ClassStudent::query()
            ->whereIn('student_identifier', $studentIds)
            ->with(['studentClass:id,name,academic_year,created_by'])
            ->get()
            ->filter(fn ($m) => $m->studentClass !== null)
            ->sortBy(fn ($m) => $m->studentClass->name)
            ->values();

        $firstClassByStudent = [];
        foreach ($memberships as $m) {
            $cls = $m->studentClass;
            if (! isset($firstClassByStudent[$m->student_identifier])) {
                $firstClassByStudent[$m->student_identifier] = [
                    'id' => $cls->id,
                    'name' => $cls->name,
                    'academicYear' => $cls->academic_year,
                ];
            }
        }

        $classBuckets = [];
        $noClass = [];
        foreach ($studentRows as $stu) {
            $row = $this->buildStudentRow($stu, $subsByStudent->get($stu->id, collect()), $examIdSet);
            $cls = $firstClassByStudent[$stu->id] ?? null;
            if ($cls) {
                if (! isset($classBuckets[$cls['id']])) {
                    $classBuckets[$cls['id']] = [
                        'classId' => $cls['id'],
                        'className' => $cls['name'],
                        'academicYear' => $cls['academicYear'],
                        'students' => [],
                    ];
                }
                $classBuckets[$cls['id']]['students'][] = $row;
            } else {
                $noClass[] = $row;
            }
        }

        $classes = collect($classBuckets)
            ->sortBy('className', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function ($c) {
                usort($c['students'], fn ($a, $b) => strcasecmp($a['studentName'], $b['studentName']));

                return [
                    'classId' => $c['classId'],
                    'className' => $c['className'],
                    'academicYear' => $c['academicYear'],
                    'studentCount' => count($c['students']),
                    'students' => $c['students'],
                ];
            })
            ->values()
            ->all();

        if (count($noClass) > 0) {
            usort($noClass, fn ($a, $b) => strcasecmp($a['studentName'], $b['studentName']));
            $classes[] = [
                'classId' => null,
                'className' => 'No class',
                'academicYear' => null,
                'studentCount' => count($noClass),
                'students' => $noClass,
            ];
        }

        return ['exams' => $examCols, 'classes' => $classes];
    }

    /**
     * Per-student aggregates from their submissions' stored topicBreakdown.
     *
     * @param  \Illuminate\Support\Collection  $subs
     * @param  array<string,int>  $examIdSet
     * @return array<string,mixed>
     */
    private function buildStudentRow(User $student, $subs, array $examIdSet): array
    {
        $perExam = [];
        $totalGradedPercent = 0.0;
        $gradedCount = 0;
        $pendingCount = 0;
        $passedCount = 0;

        foreach ($subs as $sub) {
            if (! isset($examIdSet[$sub->exam_id])) {
                continue;
            }
            $pending = (int) $sub->pending_essay_count > 0;
            $perExam[$sub->exam_id] = [
                'percent' => (float) $sub->percent_score,
                'passed' => (bool) $sub->passed,
                'status' => $pending ? 'pending_grading' : 'graded',
            ];
            if ($pending) {
                $pendingCount++;
            } else {
                $gradedCount++;
                $totalGradedPercent += (float) $sub->percent_score;
                if ($sub->passed) {
                    $passedCount++;
                }
            }
        }

        $averagePercent = $gradedCount === 0
            ? null
            : round($totalGradedPercent / $gradedCount, 2);

        // Strongest / weakest topic across graded submissions.
        $topicEarn = [];
        foreach ($subs as $sub) {
            if ((int) $sub->pending_essay_count > 0) {
                continue;
            }
            $rows = is_array($sub->topic_breakdown) ? $sub->topic_breakdown : [];
            foreach ($rows as $r) {
                if (! is_array($r) || ! isset($r['topic']) || ! is_string($r['topic'])) {
                    continue;
                }
                $t = $r['topic'];
                if (! isset($topicEarn[$t])) {
                    $topicEarn[$t] = ['earned' => 0.0, 'possible' => 0.0];
                }
                $topicEarn[$t]['earned'] += (float) ($r['earned'] ?? 0);
                $topicEarn[$t]['possible'] += (float) ($r['possible'] ?? 0);
            }
        }

        $strongestTopic = null;
        $weakestTopic = null;
        if (count($topicEarn) > 0) {
            $entries = [];
            foreach ($topicEarn as $topic => $t) {
                $entries[] = [
                    'topic' => $topic,
                    'percent' => $t['possible'] == 0.0 ? 0.0 : ($t['earned'] / $t['possible']) * 100,
                ];
            }
            usort($entries, fn ($a, $b) => $b['percent'] <=> $a['percent']);
            $strongestTopic = $entries[0]['topic'];
            $weakestTopic = $entries[count($entries) - 1]['topic'];
            if (count($entries) === 1) {
                $weakestTopic = null;
            }
        }

        return [
            'studentId' => $student->id,
            'studentName' => $student->full_name,
            'username' => $student->username,
            'perExam' => (object) $perExam,
            'examsTaken' => $gradedCount,
            'examsPassed' => $passedCount,
            'pendingCount' => $pendingCount,
            'averagePercent' => $averagePercent,
            'strongestTopic' => $strongestTopic,
            'weakestTopic' => $weakestTopic,
        ];
    }

    // ------------------------------------------------------------------
    // Workbook generation (PHP port of report-excel.ts)
    // ------------------------------------------------------------------

    /**
     * @param  array<int,array<string,mixed>>  $exams
     * @param  array<int,array<string,mixed>>  $classes
     * @param  array<string,bool>  $columns
     */
    private function buildWorkbook(array $exams, array $classes, array $columns, string $author, string $label): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $spreadsheet->getProperties()
            ->setCreator($author)
            ->setLastModifiedBy($author)
            ->setTitle("Student report — {$label}")
            ->setCreated((new Carbon)->getTimestamp());

        if (count($classes) === 0) {
            $spreadsheet->createSheet()->setTitle('Report');
        }

        $usedNames = [];
        foreach ($classes as $cls) {
            $this->addClassSheet($spreadsheet, $cls, $exams, $columns, $usedNames);
        }

        if (count($classes) > 1) {
            $this->addCombinedSheet($spreadsheet, $classes, $exams, $columns, $usedNames);
        }

        $this->autoFitColumns($spreadsheet);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<string,bool>  $columns
     * @return string[]
     */
    private function buildHeader(array $exams, array $columns, bool $includeClassColumn): array
    {
        $header = [];
        if ($includeClassColumn) {
            $header[] = 'Class';
        }
        $header[] = 'Student';
        if ($columns['username']) {
            $header[] = 'Username';
        }
        if ($columns['perExam']) {
            foreach ($exams as $exam) {
                $header[] = $exam['examName'];
            }
        }
        if ($columns['average']) {
            $header[] = 'Average %';
        }
        if ($columns['passed']) {
            $header[] = 'Passed / Taken';
        }
        if ($columns['pending']) {
            $header[] = 'Pending grading';
        }
        if ($columns['strongest']) {
            $header[] = 'Strongest topic';
        }
        if ($columns['weakest']) {
            $header[] = 'Weakest topic';
        }

        return $header;
    }

    /**
     * @param  array<string,mixed>  $student
     * @param  array<int,array<string,mixed>>  $exams
     * @param  array<string,bool>  $columns
     * @return array<int,string|int|float>
     */
    private function buildRow(array $student, array $exams, array $columns, ?string $className): array
    {
        $perExam = (array) $student['perExam'];
        $row = [];
        if ($className !== null) {
            $row[] = $className;
        }
        $row[] = $student['studentName'];
        if ($columns['username']) {
            $row[] = $student['username'];
        }
        if ($columns['perExam']) {
            foreach ($exams as $exam) {
                $cell = $perExam[$exam['examDatabaseId']] ?? null;
                if (! $cell) {
                    $row[] = '—';
                } elseif (($cell['status'] ?? '') === 'pending_grading') {
                    $row[] = "{$cell['percent']}% (pending)";
                } else {
                    $row[] = "{$cell['percent']}%".(($cell['passed'] ?? false) ? ' ✓' : '');
                }
            }
        }
        if ($columns['average']) {
            $row[] = $student['averagePercent'] === null ? '—' : "{$student['averagePercent']}%";
        }
        if ($columns['passed']) {
            $row[] = "{$student['examsPassed']} / {$student['examsTaken']}";
        }
        if ($columns['pending']) {
            $row[] = (int) $student['pendingCount'];
        }
        if ($columns['strongest']) {
            $row[] = $student['strongestTopic'] ?? '—';
        }
        if ($columns['weakest']) {
            $row[] = $student['weakestTopic'] ?? '—';
        }

        return $row;
    }

    /**
     * @param  array<string,mixed>  $cls
     * @param  array<int,array<string,mixed>>  $exams
     * @param  array<string,bool>  $columns
     * @param  array<string,bool>  $usedNames
     */
    private function addClassSheet(Spreadsheet $spreadsheet, array $cls, array $exams, array $columns, array &$usedNames): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->safeSheetName($cls['className'], $usedNames));

        $rowIdx = 1;
        $sheet->fromArray($this->buildHeader($exams, $columns, false), null, "A{$rowIdx}", true);
        foreach ($cls['students'] as $student) {
            $rowIdx++;
            $sheet->fromArray($this->buildRow($student, $exams, $columns, null), null, "A{$rowIdx}", true);
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->getStyle('1:1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A2');
    }

    /**
     * @param  array<int,array<string,mixed>>  $classes
     * @param  array<int,array<string,mixed>>  $exams
     * @param  array<string,bool>  $columns
     * @param  array<string,bool>  $usedNames
     */
    private function addCombinedSheet(Spreadsheet $spreadsheet, array $classes, array $exams, array $columns, array &$usedNames): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->safeSheetName('Combined', $usedNames));
        $sheet->getTabColor()->setRGB('0EA5E9');

        $rowIdx = 1;
        $sheet->fromArray($this->buildHeader($exams, $columns, true), null, "A{$rowIdx}", true);
        foreach ($classes as $cls) {
            foreach ($cls['students'] as $student) {
                $rowIdx++;
                $sheet->fromArray($this->buildRow($student, $exams, $columns, $cls['className']), null, "A{$rowIdx}", true);
            }
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
    }

    /**
     * Auto-fit every column, clamped to [12, 40] (port of report-excel.ts).
     */
    private function autoFitColumns(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
            $highestRow = $sheet->getHighestRow();

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $maxLen = 0;
                for ($r = 1; $r <= $highestRow; $r++) {
                    $val = (string) $sheet->getCell($letter.$r)->getValue();
                    $len = mb_strlen($val);
                    if ($len > $maxLen) {
                        $maxLen = $len;
                    }
                }
                $desired = min(40, max(12, $maxLen + 2));
                $sheet->getColumnDimension($letter)->setWidth($desired);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Admin scope: read ?teacherId= (string) or null for "all teachers". */
    private function scopeTeacherId(Request $request): ?string
    {
        $teacherId = $request->input('teacherId', $request->query('teacherId'));

        return is_string($teacherId) && $teacherId !== '' ? $teacherId : null;
    }

    /**
     * @param  mixed  $raw
     * @return array<string,bool>
     */
    private function resolveColumns($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (self::COLUMN_KEYS as $key) {
            $out[$key] = ! array_key_exists($key, $raw) || filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /** Strip Excel-illegal chars and clamp to 31 chars; dedupe across sheets. */
    private function safeSheetName(string $name, array &$usedNames): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $name);
        $clean = mb_substr($clean ?? '', 0, 31);
        if ($clean === '' || $clean === null) {
            $clean = 'Class';
        }
        $base = $clean;
        $i = 2;
        while (isset($usedNames[mb_strtolower($clean)])) {
            $suffix = ' ('.$i.')';
            $clean = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            $i++;
        }
        $usedNames[mb_strtolower($clean)] = true;

        return $clean;
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'report.xlsx';
        if (! str_ends_with(strtolower($name), '.xlsx')) {
            $name .= '.xlsx';
        }

        return $name;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function teacherOptions(): array
    {
        return User::query()
            ->where('role', 'teacher')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'subject', 'active'])
            ->map(fn ($t) => [
                'userId' => $t->id,
                'fullName' => $t->full_name,
                'subject' => $t->subject,
                'active' => (bool) $t->active,
            ])->all();
    }
}
