<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends BaseModel
{
    protected $table = 'users';

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'active' => 'boolean',
            'token_version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function credential(): HasOne
    {
        return $this->hasOne(UserCredential::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    public function createdExams(): HasMany
    {
        return $this->hasMany(Exam::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ExamSubmission::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'user_id');
    }

    public function createdClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class, 'created_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }
}
