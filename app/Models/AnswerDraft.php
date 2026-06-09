<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerDraft extends BaseModel
{
    protected $table = 'answer_drafts';

    const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }
}
