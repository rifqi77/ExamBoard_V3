<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAccessToken extends BaseModel
{
    protected $table = 'exam_access_tokens';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'active' => 'boolean',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function studentClass(): BelongsTo
    {
        return $this->belongsTo(StudentClass::class, 'class_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(ExamTokenRedemption::class, 'token_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'token_id');
    }
}
