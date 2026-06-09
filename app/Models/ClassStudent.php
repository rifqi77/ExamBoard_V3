<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassStudent extends BaseModel
{
    protected $table = 'class_students';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function studentClass(): BelongsTo
    {
        return $this->belongsTo(StudentClass::class, 'class_id');
    }
}
