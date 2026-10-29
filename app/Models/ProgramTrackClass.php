<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramTrackClass extends Model
{
    protected $fillable = [
        'program_track_id',
        'class_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function track()
    {
        return $this->belongsTo(ProgramTrack::class, 'program_track_id');
    }

    public function sessionTimes()
    {
        return $this->hasMany(ProgramCategorySessionTime::class, 'program_track_class_id')
            ->orderBy('session_order');
    }
}
