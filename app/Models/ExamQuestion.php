<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamQuestion extends BaseModel
{
    protected $table = 'exam_questions';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'options' => 'array',
            'correct_answer' => 'array',
            'explanation_media' => 'array',
            'points' => 'float',
            'position' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ExamMedia::class, 'question_id')->orderBy('sort_order');
    }
}
