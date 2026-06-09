<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppConfigAi extends Model
{
    protected $table = 'app_config_ai';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'ai_keys' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    /** The single config row id (singleton). */
    public const SINGLETON_ID = 'ai';

    public static function singleton(): self
    {
        $row = static::find(self::SINGLETON_ID);
        if (! $row) {
            static::create(['id' => self::SINGLETON_ID]);
            // Reload so the DB column defaults (provider/model/temperature)
            // are present in memory on the very first access.
            $row = static::findOrFail(self::SINGLETON_ID);
        }

        return $row;
    }
}
