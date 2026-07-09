<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TherapySession extends Model
{
    protected $fillable = [
        'registration_id',
        'therapist_id',
        'therapy_session_status_id',
        'room_id',
        'therapy_date',
        'start_time',
        'end_time',
        'notes',
        'allow_late_activity',
    ];

    // ======================
    // RELATIONS
    // ======================

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function therapist()
    {
        return $this->belongsTo(Staff::class, 'therapist_id');
    }

    public function therapySessionStatus()
    {
        return $this->belongsTo(TherapySessionStatus::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function activity()
    {
        return $this->hasOne(
            Activity::class
        );
    }
}
