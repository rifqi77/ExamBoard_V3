<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamGenerationPrompt extends BaseModel
{
    protected $table = 'exam_generation_prompts';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'config_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function uploadJob(): BelongsTo
    {
        return $this->belongsTo(AdminUploadJob::class, 'upload_job_id');
    }
}
