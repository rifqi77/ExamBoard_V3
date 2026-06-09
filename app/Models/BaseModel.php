<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every domain model: app-generated string UUID primary keys
 * (VARCHAR(191)), non-incrementing — matching the original Prisma schema
 * where IDs are app-generated UUIDs, not DB auto-increment.
 */
abstract class BaseModel extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
