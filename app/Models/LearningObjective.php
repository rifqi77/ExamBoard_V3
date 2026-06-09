<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningObjective extends BaseModel
{
    protected $table = 'learning_objectives';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
