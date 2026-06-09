<?php

namespace App\Support;

use RuntimeException;

/**
 * Per-teacher capability registry, port of the original capabilities.ts.
 * Defaults are ALL false. Missing key = disabled. Every entry is boolean
 * (number kind reserved but unused; positive numbers treated as enabled).
 */
class Capabilities
{
    /** @var string[] */
    public const GROUPS = ['ai', 'ai_param', 'exam_config', 'exam_param'];

    public const GROUP_LABELS = [
        'ai' => 'AI features',
        'ai_param' => 'AI generation parameters',
        'exam_config' => 'Exam configuration',
        'exam_param' => 'Exam parameters (manual editor)',
    ];

    public const SUBGROUP_LABELS = [
        'scope' => 'Scope',
        'difficulty' => 'Difficulty',
        'type' => 'Question type',
        'media' => 'Media',
    ];

    public const NUMBER_MAX = 200;

    /** @var string[] declaration order used by fillCapabilities() */
    public const KEYS = [
        'ai.generate',
        'ai.param.language',
        'ai.param.subject',
        // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
        'ai.param.difficulty.remember',
        'ai.param.difficulty.understand',
        'ai.param.difficulty.apply',
        'ai.param.difficulty.analyze',
        'ai.param.difficulty.evaluate',
        'ai.param.difficulty.create',
        'ai.param.difficulty.olympiad',
        'ai.param.type.single',
        'ai.param.type.multi',
        'ai.param.type.short_text',
        'ai.param.type.numeric',
        'ai.param.type.essay',
        'ai.param.media.image',
        'ai.param.media.table',
        'curriculum.manage',
        'exam.config.duration',
        'exam.config.passingGrade',
        'exam.config.mode',
        'exam.config.shuffleQuestions',
        'exam.config.shuffleOptions',
        'exam.config.language',
        'exam.config.seb',
        'exam.param.type.single',
        'exam.param.type.multi',
        'exam.param.type.short_text',
        'exam.param.type.numeric',
        'exam.param.type.essay',
        // Bloom's revised taxonomy + olympiad (replaces legacy easy/medium/hard/hots).
        'exam.param.difficulty.remember',
        'exam.param.difficulty.understand',
        'exam.param.difficulty.apply',
        'exam.param.difficulty.analyze',
        'exam.param.difficulty.evaluate',
        'exam.param.difficulty.create',
        'exam.param.difficulty.olympiad',
        'exam.param.media.image',
        'exam.param.media.table',
    ];

    /**
     * Registry in DECLARATION order (drives groupedCapabilities()).
     * Each: [key, group, subgroup|null, label, description|null].
     *
     * @return array<int,array{key:string,group:string,subgroup:?string,label:string,description:?string,entryKind:string}>
     */
    public static function registry(): array
    {
        $e = fn (string $key, string $group, ?string $subgroup, string $label, ?string $description = null): array => [
            'key' => $key,
            'group' => $group,
            'subgroup' => $subgroup,
            'label' => $label,
            'description' => $description,
            'entryKind' => 'boolean',
        ];

        return [
            $e('ai.generate', 'ai', null, 'Generate exams with AI',
                "Lets this teacher open the AI-generate page and build a prompt. Per-parameter caps below decide how big a request they can make."),
            $e('ai.param.language', 'ai_param', 'scope', 'Language',
                "Off = the Language input in the AI generate form is locked to the system default and the teacher can't change it."),
            $e('ai.param.subject', 'ai_param', 'scope', 'Subject',
                "Off = the Subject input is locked. (Teachers whose account has a subject see it locked to that subject regardless of this gate.)"),
            $e('ai.param.difficulty.remember', 'ai_param', 'difficulty', 'Remember',
                "Bloom's level 1 — recall facts, terms, basic concepts."),
            $e('ai.param.difficulty.understand', 'ai_param', 'difficulty', 'Understand',
                "Bloom's level 2 — explain ideas or concepts; paraphrase, compare."),
            $e('ai.param.difficulty.apply', 'ai_param', 'difficulty', 'Apply',
                "Bloom's level 3 — use information in a new situation; solve standard problems."),
            $e('ai.param.difficulty.analyze', 'ai_param', 'difficulty', 'Analyze',
                "Bloom's level 4 — draw connections among ideas; decompose, compare, contrast."),
            $e('ai.param.difficulty.evaluate', 'ai_param', 'difficulty', 'Evaluate',
                "Bloom's level 5 — justify a stand or decision; critique, judge."),
            $e('ai.param.difficulty.create', 'ai_param', 'difficulty', 'Create',
                "Bloom's level 6 — produce new or original work; design, construct."),
            $e('ai.param.difficulty.olympiad', 'ai_param', 'difficulty', 'Olympiad',
                'Beyond standard taxonomy — contest-level, non-obvious technique required.'),
            $e('ai.param.type.single', 'ai_param', 'type', 'Single choice'),
            $e('ai.param.type.multi', 'ai_param', 'type', 'Multiple select'),
            $e('ai.param.type.short_text', 'ai_param', 'type', 'Short text'),
            $e('ai.param.type.numeric', 'ai_param', 'type', 'Numeric'),
            $e('ai.param.type.essay', 'ai_param', 'type', 'Essay'),
            $e('ai.param.media.image', 'ai_param', 'media', 'Images',
                "Off = the 'Include image suggestions' checkbox is locked off for this teacher."),
            $e('ai.param.media.table', 'ai_param', 'media', 'Tables'),
            $e('curriculum.manage', 'ai', null, 'Curriculum (LO catalog)',
                "Upload an Excel of Topic / Subtopic / Learning Objective rows, manage them, and use them as content scope in AI Generate."),
            $e('exam.config.duration', 'exam_config', null, 'Edit exam duration'),
            $e('exam.config.passingGrade', 'exam_config', null, 'Edit passing grade'),
            $e('exam.config.mode', 'exam_config', null, 'Switch exam mode (Strict / Try Out)'),
            $e('exam.config.shuffleQuestions', 'exam_config', null, 'Shuffle questions toggle'),
            $e('exam.config.shuffleOptions', 'exam_config', null, 'Shuffle options toggle'),
            $e('exam.config.language', 'exam_config', null, 'Edit exam language',
                "Gates the Language input in the manual exam editor. The AI generate form has its own separate gate (AI generation parameters → Scope → Language)."),
            $e('exam.config.seb', 'exam_config', null, 'Enable Safe Exam Browser requirement',
                "If off, the teacher cannot require students to take the exam in SEB."),
            $e('exam.param.difficulty.remember', 'exam_param', 'difficulty', 'Remember',
                "Bloom's level 1 — recall facts and terms."),
            $e('exam.param.difficulty.understand', 'exam_param', 'difficulty', 'Understand',
                "Bloom's level 2 — explain or paraphrase."),
            $e('exam.param.difficulty.apply', 'exam_param', 'difficulty', 'Apply',
                "Bloom's level 3 — use in a new situation."),
            $e('exam.param.difficulty.analyze', 'exam_param', 'difficulty', 'Analyze',
                "Bloom's level 4 — decompose, compare."),
            $e('exam.param.difficulty.evaluate', 'exam_param', 'difficulty', 'Evaluate',
                "Bloom's level 5 — justify, critique."),
            $e('exam.param.difficulty.create', 'exam_param', 'difficulty', 'Create',
                "Bloom's level 6 — produce, design, construct."),
            $e('exam.param.difficulty.olympiad', 'exam_param', 'difficulty', 'Olympiad', 'Contest-level beyond Bloom\'s.'),
            $e('exam.param.type.single', 'exam_param', 'type', 'Single choice'),
            $e('exam.param.type.multi', 'exam_param', 'type', 'Multiple select'),
            $e('exam.param.type.short_text', 'exam_param', 'type', 'Short text'),
            $e('exam.param.type.numeric', 'exam_param', 'type', 'Numeric'),
            $e('exam.param.type.essay', 'exam_param', 'type', 'Structured / essay'),
            $e('exam.param.media.image', 'exam_param', 'media', 'Images'),
            $e('exam.param.media.table', 'exam_param', 'media', 'Tables'),
        ];
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** @return array{key:string,group:string,subgroup:?string,label:string,description:?string,entryKind:string} */
    public static function entry(string $key): array
    {
        foreach (self::registry() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }
        throw new RuntimeException("Missing capability registry entry for \"{$key}\".");
    }

    public static function has(?array $caps, string $key): bool
    {
        if (! $caps) {
            return false;
        }
        $value = $caps[$key] ?? null;
        if ($value === true) {
            return true;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        return false;
    }

    public static function limit(?array $caps, string $key): int
    {
        if (! $caps) {
            return 0;
        }
        $value = $caps[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return max(0, (int) floor($value));
        }
        if ($value === true) {
            return 1;
        }

        return 0;
    }

    /** Fully-populated map: every key => bool (no sparse holes). */
    public static function fill(?array $caps): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = self::has($caps, $key);
        }

        return $out;
    }

    /** @return array<int,array{group:string,label:string,entries:array}> */
    public static function grouped(): array
    {
        $byGroup = [];
        foreach (self::registry() as $entry) {
            $byGroup[$entry['group']][] = $entry;
        }
        $out = [];
        foreach (self::GROUPS as $group) {
            $out[] = [
                'group' => $group,
                'label' => self::GROUP_LABELS[$group],
                'entries' => $byGroup[$group] ?? [],
            ];
        }

        return $out;
    }
}
