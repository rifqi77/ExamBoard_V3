<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends BaseModel
{
    protected $table = 'exams';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'seb_required' => 'boolean',
            'type_distribution' => 'array',
            'difficulty_distribution' => 'array',
            'media_targets' => 'array',
            'duration_minutes' => 'integer',
            'passing_grade' => 'integer',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id')->orderBy('position');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ExamSubmission::class, 'exam_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'exam_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ExamAccessToken::class, 'exam_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
