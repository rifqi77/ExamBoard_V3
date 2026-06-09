<?php

namespace App\Support;

use RuntimeException;

/**
 * Exam scoring engine, faithful port of the original scoring.ts —
 * including PARTIAL CREDIT for multi_select ((correct-wrong)/totalCorrect,
 * floored) and numeric (tolerance bands), essay pending handling, and the
 * per-topic rollup. This decides grades; keep behaviour identical.
 */
class Scoring
{
    private const NUMERIC_EPSILON = 1e-6;

    /**
     * @param  array<int,array{id:string,topic:string,points:int|float|string,type?:string}>  $questions
     * @param  array<string,mixed>  $keysByQuestionId   questionId => correctAnswer
     * @param  array<string,mixed>  $submittedAnswers   questionId => answer value
     * @param  array<string,float|int>  $manualScores   questionId => manual essay grade
     * @return array{finalScore:float,possibleScore:float,percentScore:float,pendingEssayCount:int,topicBreakdown:array,itemResults:array}
     */
    public static function scoreExam(
        array $questions,
        array $keysByQuestionId,
        array $submittedAnswers,
        array $manualScores = []
    ): array {
        $earned = 0.0;
        $possible = 0.0;
        $pendingEssayCount = 0;
        $topicMap = []; // topic => [earned, possible, correct, total]
        $itemResults = [];

        foreach ($questions as $question) {
            $qid = $question['id'];
            if (! array_key_exists($qid, $keysByQuestionId)) {
                throw new RuntimeException("Missing answer key for question {$qid}");
            }
            $correct = $keysByQuestionId[$qid];
            $points = (float) $question['points'];
            $type = $question['type'] ?? null;
            $userAnswer = $submittedAnswers[$qid] ?? null;
            $isEssay = $type === 'essay';

            $awarded = 0.0;
            $isCorrect = false;
            $requiresGrading = false;

            if ($isEssay) {
                $manual = $manualScores[$qid] ?? null;
                if (is_int($manual) || is_float($manual)) {
                    $awarded = max(0.0, min($points, (float) $manual));
                    $isCorrect = $points > 0 && $awarded >= $points;
                } else {
                    $requiresGrading = true;
                    $pendingEssayCount++;
                }
            } elseif ($type === 'numeric') {
                $ratio = self::numericCreditRatio($userAnswer, $correct);
                $awarded = round($points * $ratio, 2);
                $isCorrect = $ratio >= 1;
            } elseif ($type === 'multi_select') {
                $ratio = self::multiSelectCreditRatio($userAnswer, $correct);
                $awarded = round($points * $ratio, 2);
                $isCorrect = $ratio >= 1;
            } else {
                $isCorrect = self::sameAnswer($userAnswer, $correct, $type);
                $awarded = $isCorrect ? $points : 0.0;
            }

            $earned += $awarded;
            $possible += $points;

            $topic = $question['topic'];
            if (! isset($topicMap[$topic])) {
                $topicMap[$topic] = ['earned' => 0.0, 'possible' => 0.0, 'correct' => 0, 'total' => 0];
            }
            $topicMap[$topic]['earned'] += $awarded;
            $topicMap[$topic]['possible'] += $points;
            $topicMap[$topic]['correct'] += $isCorrect ? 1 : 0;
            $topicMap[$topic]['total'] += 1;

            $itemResults[] = [
                'questionId' => $qid,
                'topic' => $topic,
                'awarded' => $awarded,
                'possible' => $points,
                'isCorrect' => $isCorrect,
                'requiresGrading' => $requiresGrading,
            ];
        }

        $percentScore = $possible == 0 ? 0.0 : round(($earned / $possible) * 100, 2);

        $topicBreakdown = [];
        foreach ($topicMap as $topic => $v) {
            $topicBreakdown[] = [
                'topic' => $topic,
                'earned' => round($v['earned'], 2),
                'possible' => round($v['possible'], 2),
                'percent' => $v['possible'] == 0 ? 0.0 : round(($v['earned'] / $v['possible']) * 100, 2),
                'correct' => $v['correct'],
                'total' => $v['total'],
            ];
        }

        return [
            'finalScore' => round($earned, 2),
            'possibleScore' => round($possible, 2),
            'percentScore' => $percentScore,
            'pendingEssayCount' => $pendingEssayCount,
            'topicBreakdown' => $topicBreakdown,
            'itemResults' => $itemResults,
        ];
    }

    private static function toNumber(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v)) {
            $t = trim($v);
            return is_numeric($t) ? (float) $t : NAN;
        }

        return NAN;
    }

    public static function numericCreditRatio(mixed $userAnswer, mixed $correctAnswer): float
    {
        $u = self::toNumber($userAnswer);
        $c = self::toNumber($correctAnswer);
        if (! is_finite($u) || ! is_finite($c)) {
            return 0.0;
        }
        $diff = abs($u - $c);
        if ($diff <= self::NUMERIC_EPSILON) {
            return 1.0;
        }
        if ($c == 0.0) {
            if ($diff <= 0.001) return 0.8;
            if ($diff <= 0.01) return 0.5;
            if ($diff <= 0.1) return 0.2;
            return 0.0;
        }
        $relErr = $diff / abs($c);
        if ($relErr <= 0.01) return 0.8;
        if ($relErr <= 0.05) return 0.5;
        if ($relErr <= 0.1) return 0.2;

        return 0.0;
    }

    public static function multiSelectCreditRatio(mixed $userAnswer, mixed $correctAnswer): float
    {
        $normaliseSet = function (mixed $value): array {
            if (! is_array($value)) {
                if (is_string($value) && trim($value) !== '') {
                    return [strtoupper(trim($value)) => true];
                }
                return [];
            }
            $set = [];
            foreach ($value as $v) {
                $s = strtoupper(trim((string) $v));
                if ($s !== '') {
                    $set[$s] = true;
                }
            }
            return $set;
        };

        $correctSet = $normaliseSet($correctAnswer);
        $userSet = $normaliseSet($userAnswer);
        if (count($correctSet) === 0) {
            return count($userSet) === 0 ? 1.0 : 0.0;
        }
        $correctPicks = 0;
        $wrongPicks = 0;
        foreach ($userSet as $u => $_) {
            if (isset($correctSet[$u])) {
                $correctPicks++;
            } else {
                $wrongPicks++;
            }
        }
        $ratio = ($correctPicks - $wrongPicks) / count($correctSet);

        return max(0.0, min(1.0, $ratio));
    }

    private static function normalizeScalar(mixed $v): mixed
    {
        if (is_array($v)) {
            $a = array_map(fn ($x) => trim((string) $x), $v);
            sort($a, SORT_STRING);
            return $a;
        }
        if (is_string($v)) {
            return strtolower(trim($v));
        }

        return $v;
    }

    private static function sameAnswer(mixed $userAnswer, mixed $correctAnswer, ?string $type): bool
    {
        if ($userAnswer === null) {
            return false;
        }
        if ($type === 'numeric') {
            $u = self::toNumber($userAnswer);
            $c = self::toNumber($correctAnswer);
            if (! is_finite($u) || ! is_finite($c)) {
                return false;
            }
            return abs($u - $c) <= self::NUMERIC_EPSILON;
        }

        return json_encode(self::normalizeScalar($userAnswer)) === json_encode(self::normalizeScalar($correctAnswer));
    }
}
