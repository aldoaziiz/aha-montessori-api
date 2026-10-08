<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AppSetting extends Model
{
    protected $fillable = [
        'setting_key',
        'setting_value',
        'setting_group',
        'value_type',
        'description',
    ];

    public static function value(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('app_settings')) {
            return $default;
        }

        return static::query()
            ->where('setting_key', $key)
            ->value('setting_value') ?? $default;
    }

    public static function enabled(string $key, bool $default = false): bool
    {
        return filter_var(
            static::value($key, $default ? '1' : '0'),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
