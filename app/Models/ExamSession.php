<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamSession extends BaseModel
{
    protected $table = 'exam_sessions';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'time_used_seconds' => 'integer',
            'anti_cheat_events' => 'array',
            'started_at' => 'datetime',
            'last_saved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ExamAccessToken::class, 'token_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(AnswerDraft::class, 'session_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(ExamSubmission::class, 'session_id');
    }
}
