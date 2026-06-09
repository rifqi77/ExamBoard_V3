<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace the legacy easy/medium/hard/hots difficulty levels with Bloom's
 * revised taxonomy levels (remember/understand/apply/analyze/evaluate/create),
 * keeping `olympiad` as-is.
 *
 * Mapping (cognitive-complexity ordered):
 *   easy   → remember
 *   medium → understand
 *   hard   → analyze
 *   hots   → evaluate
 *   apply, create are NEW levels (not auto-assigned to any old value)
 *
 * Affects:
 *   - exam_questions.difficulty ENUM
 *   - bank_questions.difficulty ENUM
 *   - users.capabilities JSON keys (ai.param.difficulty.* and exam.param.difficulty.*)
 */
return new class extends Migration
{
    private const NEW_LEVELS = "'remember','understand','apply','analyze','evaluate','create','olympiad'";

    private const BRIDGE_LEVELS = "'easy','medium','hard','hots','remember','understand','apply','analyze','evaluate','create','olympiad'";

    private const COLUMN_MAP = [
        'exam_questions',
        'bank_questions',
    ];

    private const VALUE_MAP = [
        'easy' => 'remember',
        'medium' => 'understand',
        'hard' => 'analyze',
        'hots' => 'evaluate',
    ];

    public function up(): void
    {
        // 1. Bridge the enum to accept BOTH old and new values so the UPDATE
        //    below can map data row-by-row without violating the column type.
        foreach (self::COLUMN_MAP as $table) {
            DB::statement('ALTER TABLE `'.$table.'` MODIFY COLUMN `difficulty` ENUM('.self::BRIDGE_LEVELS.') NULL');
        }

        // 2. Map legacy values to the Bloom's equivalents.
        foreach (self::COLUMN_MAP as $table) {
            foreach (self::VALUE_MAP as $old => $new) {
                DB::table($table)->where('difficulty', $old)->update(['difficulty' => $new]);
            }
        }

        // 3. Tighten the enum to only the new (Bloom's + olympiad) levels.
        foreach (self::COLUMN_MAP as $table) {
            DB::statement('ALTER TABLE `'.$table.'` MODIFY COLUMN `difficulty` ENUM('.self::NEW_LEVELS.') NULL');
        }

        // 4. Migrate any teacher capability JSON that still uses the old
        //    difficulty.* keys. Chunked to keep memory low if many teachers.
        DB::table('users')
            ->whereNotNull('capabilities')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $u) {
                    $caps = json_decode($u->capabilities, true);
                    if (! is_array($caps)) {
                        continue;
                    }
                    $changed = false;
                    $next = [];
                    foreach ($caps as $key => $value) {
                        $newKey = $this->renameCapabilityKey($key);
                        if ($newKey !== $key) {
                            $changed = true;
                        }
                        // If the renamed key already exists (collision shouldn't
                        // happen but be defensive), prefer truthy.
                        $next[$newKey] = isset($next[$newKey])
                            ? ($next[$newKey] || $value)
                            : $value;
                    }
                    if ($changed) {
                        DB::table('users')
                            ->where('id', $u->id)
                            ->update(['capabilities' => json_encode($next)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Inverse of up(). Lossy for the two NEW levels (apply/create) — both
        // collapse to 'medium' under the legacy taxonomy.
        $inverse = [
            'remember' => 'easy',
            'understand' => 'medium',
            'apply' => 'medium',
            'analyze' => 'hard',
            'evaluate' => 'hots',
            'create' => 'hots',
        ];

        foreach (self::COLUMN_MAP as $table) {
            DB::statement('ALTER TABLE `'.$table.'` MODIFY COLUMN `difficulty` ENUM('.self::BRIDGE_LEVELS.') NULL');
        }
        foreach (self::COLUMN_MAP as $table) {
            foreach ($inverse as $new => $old) {
                DB::table($table)->where('difficulty', $new)->update(['difficulty' => $old]);
            }
        }
        foreach (self::COLUMN_MAP as $table) {
            DB::statement('ALTER TABLE `'.$table.'` MODIFY COLUMN `difficulty` ENUM(\'easy\',\'medium\',\'hard\',\'hots\',\'olympiad\') NULL');
        }
    }

    private function renameCapabilityKey(string $key): string
    {
        // Only difficulty.* sub-keys change; everything else (ai.generate,
        // exam.config.duration, type/media keys, scope keys) stays.
        if (! str_contains($key, '.difficulty.')) {
            return $key;
        }
        foreach (self::VALUE_MAP as $old => $new) {
            $suffix = '.difficulty.'.$old;
            if (str_ends_with($key, $suffix)) {
                return substr($key, 0, -strlen($suffix)).'.difficulty.'.$new;
            }
        }

        return $key;
    }
};
