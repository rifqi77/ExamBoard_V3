<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSubmission extends BaseModel
{
    protected $table = 'exam_submissions';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'final_score' => 'float',
            'possible_score' => 'float',
            'percent_score' => 'float',
            'passed' => 'boolean',
            'attempt' => 'integer',
            'passing_grade' => 'integer',
            'pending_essay_count' => 'integer',
            'topic_breakdown' => 'array',
            'answers_snapshot' => 'array',
            'manual_scores' => 'array',
            'anti_cheat_events' => 'array',
            'review_items' => 'array',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }
}
