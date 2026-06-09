<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassStudent;
use App\Models\ExamSubmission;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCredential;
use App\Support\AcademicYear;
use App\Support\CryptoSecrets;
use App\Support\StudentCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Teacher → Classes. Ports the original roster pipeline:
 *   - parse:  client uploaded the .xlsx/.csv and parsed it in-browser, then
 *             POSTed the parsed JSON. Here we parse server-side with
 *             PhpSpreadsheet (port of student-class-parser.ts + excel-parser.ts)
 *             and return the same preview shape.
 *   - import: POST /api/teacher/classes/import — idempotent over (name,
 *             academicYear, owner); creates users + credentials + class links
 *             and returns the new credentials[] (source: provided|generated).
 *   - show:   collapsible class → roster detail.
 *
 * All rows scoped to the signed-in teacher via created_by = $user->id.
 */
class ClassController extends Controller
{
    private const USERNAME_RE = '/^[a-zA-Z0-9._-]{3,32}$/';

    /** First-row header detection — case-insensitive aliases (EN + ID). */
    private const HEADER_HINTS = [
        'name', 'nama', 'full name', 'fullname', 'student', 'siswa',
        'nama siswa', 'nama lengkap', 'username', 'email', 'no', 'no.',
        'number', 'id',
    ];

    /**
     * POST /teacher/classes/parse — upload a workbook, get a preview.
     *   multipart: file (.xlsx | .csv)
     *   200: { fileName, classes: [{ name, students: [{ fullName, username,
     *          password }] }] }
     * One sheet per class (xlsx); a CSV becomes a single class named after
     * the file. Column order: full name, username, password (latter two
     * optional → auto-generated at import time).
     */
    public function parse(Request $request)
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => 'No file uploaded.'], 400);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            return response()->json(['error' => 'Upload an .xlsx or .csv file.'], 400);
        }

        try {
            $spreadsheet = $this->loadSpreadsheet($file->getRealPath(), $ext);
            $classes = $this->extractClasses($spreadsheet, $file->getClientOriginalName());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not read the file.'], 400);
        }

        if (count($classes) === 0) {
            return response()->json(['error' => 'No classes or students found in this file.'], 400);
        }

        return response()->json([
            'fileName' => $file->getClientOriginalName(),
            'classes' => $classes,
        ]);
    }

    /**
     * POST /teacher/classes/import — commit a previewed roster.
     *   body: { fileName, academicYear, classes: [{ name, students }] }
     *   200: { classesCreated, classesUpdated, studentsCreated,
     *          studentsSkipped[], createdStudents[] }
     * Idempotent over (name, academicYear) for THIS teacher: re-importing the
     * same sheet/year refreshes the source file name and appends new students;
     * usernames/validation that fail are reported in studentsSkipped.
     */
    public function import(Request $request)
    {
        $user = $request->user();

        $fileName = is_string($request->input('fileName')) ? $request->input('fileName') : '(unknown)';
        $classesIn = $request->input('classes');
        if (! is_array($classesIn) || count($classesIn) === 0) {
            return response()->json(['error' => 'No classes in import.'], 400);
        }

        // Resolve the academic year: empty → current; provided-but-malformed → 400.
        $rawYear = $request->input('academicYear');
        if (is_string($rawYear) && trim($rawYear) !== '') {
            $academicYear = AcademicYear::parse($rawYear);
            if ($academicYear === null) {
                return response()->json([
                    'error' => 'Academic year must be "YYYY/YYYY" with consecutive years, e.g. "2025/2026".',
                ], 400);
            }
        } else {
            $academicYear = AcademicYear::current();
        }

        $taken = User::pluck('username')->map(fn ($u) => strtolower($u))->all();

        $classesCreated = 0;
        $classesUpdated = 0;
        $studentsCreated = 0;
        $skipped = [];
        $createdStudents = [];

        foreach ($classesIn as $classInput) {
            $className = is_array($classInput) && is_string($classInput['name'] ?? null)
                ? trim($classInput['name'])
                : '';
            if ($className === '') {
                $skipped[] = ['reason' => 'Class name missing', 'identifier' => '(unnamed sheet)'];

                continue;
            }

            // Find-or-create the class row scoped to the OWNER so two teachers
            // can both have a class with the same name + year.
            $cls = StudentClass::where('name', $className)
                ->where('academic_year', $academicYear)
                ->where('created_by', $user->id)
                ->first();
            if ($cls) {
                $classesUpdated++;
                $cls->source_file_name = $fileName;
                $cls->save();
            } else {
                $cls = StudentClass::create([
                    'name' => $className,
                    'academic_year' => $academicYear,
                    'source_file_name' => $fileName,
                    'created_by' => $user->id,
                ]);
                $classesCreated++;
            }

            $studentsIn = is_array($classInput['students'] ?? null) ? $classInput['students'] : [];
            foreach ($studentsIn as $studentInput) {
                if (! is_array($studentInput)) {
                    continue;
                }
                $fullName = is_string($studentInput['fullName'] ?? null) ? trim($studentInput['fullName']) : '';
                if ($fullName === '') {
                    $skipped[] = ['reason' => 'Full name missing', 'identifier' => '(blank row)'];

                    continue;
                }

                $username = is_string($studentInput['username'] ?? null) ? trim($studentInput['username']) : '';
                if ($username === '') {
                    $username = StudentCredentials::generateUsernameFromName($fullName, $taken);
                }
                if (in_array(strtolower($username), $taken, true)) {
                    $skipped[] = ['reason' => 'Username already exists', 'identifier' => $username];

                    continue;
                }
                if (! preg_match(self::USERNAME_RE, $username)) {
                    $skipped[] = ['reason' => 'Invalid username format', 'identifier' => $username];

                    continue;
                }

                $provided = is_string($studentInput['password'] ?? null) ? trim($studentInput['password']) : '';
                $passwordWasGenerated = $provided === '';
                $password = $passwordWasGenerated
                    ? StudentCredentials::generatePasswordFromName($fullName)
                    : $provided;
                if (strlen($password) < 6 || strlen($password) > 64) {
                    $skipped[] = ['reason' => 'Password length must be 6-64', 'identifier' => $username];

                    continue;
                }

                try {
                    DB::transaction(function () use ($username, $fullName, $password, $user, $cls) {
                        $u = User::create([
                            'username' => $username,
                            'full_name' => $fullName,
                            'role' => 'student',
                            'active' => true,
                            'created_by' => $user->id,
                        ]);
                        UserCredential::create([
                            'user_id' => $u->id,
                            'password_hash' => Hash::make($password),
                            'password_plain' => CryptoSecrets::encryptStudentPassword($password),
                            'password_set_by' => $user->id,
                        ]);
                        ClassStudent::create([
                            'class_id' => $cls->id,
                            'student_identifier' => $u->id,
                            'student_name' => $fullName,
                        ]);
                    });
                } catch (\Throwable $e) {
                    $skipped[] = ['reason' => 'Insert failed', 'identifier' => $username];

                    continue;
                }

                $taken[] = strtolower($username);
                $studentsCreated++;
                $createdStudents[] = [
                    'className' => $className,
                    'fullName' => $fullName,
                    'username' => $username,
                    'password' => $password,
                    'passwordWasGenerated' => $passwordWasGenerated,
                ];
            }
        }

        return response()->json([
            'classesCreated' => $classesCreated,
            'classesUpdated' => $classesUpdated,
            'studentsCreated' => $studentsCreated,
            'studentsSkipped' => $skipped,
            'createdStudents' => $createdStudents,
        ]);
    }

    /**
     * GET /teacher/classes/{classId} — collapsible class → roster detail.
     * Returns each student with username / active / revealed password /
     * submissionCount. Scoped to the owning teacher.
     */
    public function show(Request $request, string $classId)
    {
        $user = $request->user();

        $class = StudentClass::where('id', $classId)
            ->where('created_by', $user->id)
            ->first();
        if (! $class) {
            return response()->json(['error' => 'Class not found.'], 404);
        }

        $links = $class->students()->orderBy('student_name')->get();
        $userIds = $links->pluck('student_identifier')->all();

        $students = User::query()
            ->whereIn('id', $userIds)
            ->where('role', 'student')
            ->where('created_by', $user->id)
            ->withCount('submissions as total_submissions')
            ->with(['credential:user_id,password_plain'])
            ->get()
            ->keyBy('id');

        $lastByUser = ExamSubmission::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, MAX(submitted_at) as last_submitted_at')
            ->groupBy('user_id')
            ->pluck('last_submitted_at', 'user_id');

        $rows = [];
        $seen = [];
        foreach ($links as $link) {
            $u = $students->get($link->student_identifier);
            if (! $u || isset($seen[$u->id])) {
                continue;
            }
            $seen[$u->id] = true;

            $rawStored = $u->credential?->password_plain;
            $stored = $rawStored ? CryptoSecrets::decryptStudentPassword($rawStored) : null;
            $last = $lastByUser->get($u->id);

            $rows[] = [
                'userId' => $u->id,
                'username' => $u->username,
                'fullName' => $u->full_name,
                'active' => (bool) $u->active,
                'totalSubmissions' => (int) $u->total_submissions,
                'lastSubmissionAt' => $last ? Carbon::parse($last)->toIso8601String() : null,
                'passwordPlain' => $stored ?? $this->derivePasswordFromUsername($u->username, $u->created_at),
            ];
        }

        return response()->json([
            'class' => [
                'classId' => $class->id,
                'className' => $class->name,
                'academicYear' => $class->academic_year,
                'sourceFileName' => $class->source_file_name,
                'studentCount' => count($rows),
                'students' => $rows,
            ],
        ]);
    }

    // ---------------------------------------------------------------- parsing

    private function loadSpreadsheet(string $path, string $ext): Spreadsheet
    {
        if ($ext === 'csv') {
            $reader = IOFactory::createReader('Csv');
            $reader->setReadDataOnly(true);

            return $reader->load($path);
        }
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);

        return $reader->load($path);
    }

    /**
     * Port of student-class-parser.ts / excel-parser.ts: one sheet per class.
     * Skips a likely header row, then for each row picks email / numeric id /
     * name heuristically (column 1 = full name, 2 = username, 3 = password).
     */
    private function extractClasses(Spreadsheet $spreadsheet, string $fileName): array
    {
        $classes = [];

        foreach ($spreadsheet->getAllSheets() as $i => $sheet) {
            $sheetName = trim((string) $sheet->getTitle());
            // CSV sheets are titled "Worksheet" — fall back to the file's base name.
            if ($sheetName === '' || $sheetName === 'Worksheet') {
                $sheetName = pathinfo($fileName, PATHINFO_FILENAME) ?: ('Class '.($i + 1));
            }

            $students = [];
            $rows = $sheet->toArray(null, true, false, false);

            $firstCell = isset($rows[0][0]) ? mb_strtolower(trim((string) $rows[0][0])) : '';
            $skipFirstRow = $this->looksLikeHeader($firstCell);

            foreach ($rows as $rowIndex => $row) {
                if ($skipFirstRow && $rowIndex === 0) {
                    continue;
                }
                $cells = array_map(fn ($c) => trim((string) ($c ?? '')), $row);
                $nonEmpty = array_values(array_filter($cells, fn ($c) => $c !== ''));
                if (count($nonEmpty) === 0) {
                    continue;
                }

                // Column-positional first (matches the in-browser excel-parser),
                // then fall back to the heuristic single-column extraction.
                $fullName = $cells[0] ?? '';
                $username = $cells[1] ?? '';
                $password = $cells[2] ?? '';

                if ($fullName === '') {
                    // Heuristic: email → numeric id → first remaining word as name.
                    $name = '';
                    foreach ($nonEmpty as $value) {
                        if ($name === '' && ! $this->looksLikeEmail($value) && ! preg_match('/^\d+$/', $value)) {
                            $name = $value;
                            break;
                        }
                    }
                    if ($name === '') {
                        $name = $nonEmpty[count($nonEmpty) - 1];
                    }
                    $fullName = $name;
                    $username = '';
                    $password = '';
                }

                if ($fullName === '') {
                    continue;
                }

                $students[] = [
                    'fullName' => $fullName,
                    'username' => $username,
                    'password' => $password,
                ];
            }

            if (count($students) > 0) {
                $classes[] = ['name' => $sheetName, 'students' => $students];
            }
        }

        return $classes;
    }

    private function looksLikeHeader(string $firstCellLower): bool
    {
        foreach (self::HEADER_HINTS as $hint) {
            if ($firstCellLower !== '' && str_contains($firstCellLower, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value);
    }

    private function derivePasswordFromUsername(string $username, $createdAt): ?string
    {
        if (! preg_match('/^([a-z]+)\d{3}$/', $username, $m)) {
            return null;
        }
        $nickname = $m[1];
        if (strlen($nickname) < 2) {
            return null;
        }
        $year = $createdAt ? Carbon::parse($createdAt)->format('Y') : date('Y');

        return $nickname.$year;
    }
}
