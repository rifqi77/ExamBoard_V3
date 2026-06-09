<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamTokenRedemption extends BaseModel
{
    protected $table = 'exam_token_redemptions';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ExamAccessToken::class, 'token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
