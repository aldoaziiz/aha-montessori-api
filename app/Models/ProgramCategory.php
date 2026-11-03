<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramCategory extends Model
{
    use HasFactory;

    public const SCHEDULE_FIXED_SLOTS = 'fixed_slots';

    public const SCHEDULE_EXTERNAL = 'external';

    protected $fillable = [
        'name',
        'supports_tracks',
        'schedule_mode',
    ];

    protected $casts = [
        'supports_tracks' => 'boolean',
    ];

    public function getScheduleModeAttribute($value): string
    {
        return $value ?: self::SCHEDULE_FIXED_SLOTS;
    }

    public function programs()
    {
        return $this->hasMany(
            Program::class
        );
    }

    public function sessionTimes()
    {
        return $this->hasMany(
            ProgramCategorySessionTime::class,
            'program_category_id'
        )
            ->orderBy('session_order');
    }

    public function activities()
    {
        return $this->hasMany(
            Activity::class
        );
    }

    public function tracks()
    {
        return $this->hasMany(ProgramTrack::class);
    }
}
