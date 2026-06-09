<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankQuestion extends BaseModel
{
    protected $table = 'bank_questions';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'options' => 'array',
            'correct_answer' => 'array',
            'points' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
