<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TherapySession extends Model
{
    public const STATUS_SCHEDULED = 1;

    public const STATUS_COMPLETED = 2;

    public const STATUS_ALPHA = 3;

    protected $fillable = [
        'registration_id',
        'therapist_id',
        'therapy_session_status_id',
        'uses_session',
        'room_id',
        'therapy_date',
        'start_time',
        'end_time',
        'notes',
        'allow_late_activity',
    ];

    protected $casts = [
        'uses_session' => 'boolean',
    ];

    public static function usesSessionForStatus(int $statusId): bool
    {
        return $statusId === self::STATUS_COMPLETED;
    }

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
}
