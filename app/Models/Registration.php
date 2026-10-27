<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Registration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'registration_number',
        'registration_status',
        'child_id',
        'clinic_id',
        'complaint',
        'program_id',
        'payer_id',
        'room_id',
        'program_category_id',
        'total_session',
    ];

    protected $casts = [
        'total_session' => 'integer',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function programs()
    {
        return $this->belongsToMany(
            Program::class,
            'registration_programs'
        )->withPivot(
            'price',
            'learning_period_months'
        );
    }

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }

    public function paymentStatus()
    {
        return $this->belongsTo(PaymentStatus::class);
    }

    public function therapySessions()
    {
        return $this->hasMany(TherapySession::class);
    }

    public function schoolScheduleSubmissions()
    {
        return $this->hasMany(SchoolScheduleSubmission::class);
    }

    public function registrationPrograms()
    {
        return $this->hasMany(
            RegistrationProgram::class
        );
    }

    public function billing()
    {
        return $this->hasOne(Billing::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function programCategory()
    {
        return $this->belongsTo(
            ProgramCategory::class
        );
    }

    public function calculateTotalSession(): int
    {
        $this->loadMissing('programs');

        return (int) $this->programs->sum(function ($program) {
            return (int) $program->session_count
                * (int) $program->pivot->learning_period_months;
        });
    }

    public function syncSessionEntitlement(): void
    {
        $this->forceFill([
            'total_session' => $this->calculateTotalSession(),
        ])->save();
    }

    public function getSessionStartedAtAttribute(): ?Carbon
    {
        return $this->child?->session_started_at;
    }

    public function getSessionExpiredAtAttribute(): ?Carbon
    {
        return $this->child?->session_expired_at;
    }

    public function getUsedSessionCount(): int
    {
        if ($this->relationLoaded('therapySessions')) {
            return $this->therapySessions->where('uses_session', true)->count();
        }

        return $this->therapySessions()
            ->where('uses_session', true)
            ->count();
    }

    public function getScheduledSessionCount(): int
    {
        if ($this->relationLoaded('therapySessions')) {
            return $this->therapySessions
                ->where('therapy_session_status_id', TherapySession::STATUS_SCHEDULED)
                ->count();
        }

        return $this->therapySessions()
            ->where('therapy_session_status_id', TherapySession::STATUS_SCHEDULED)
            ->count();
    }

    public function getCompletedSessionCount(): int
    {
        if ($this->relationLoaded('therapySessions')) {
            return $this->therapySessions
                ->where('therapy_session_status_id', TherapySession::STATUS_COMPLETED)
                ->count();
        }

        return $this->therapySessions()
            ->where('therapy_session_status_id', TherapySession::STATUS_COMPLETED)
            ->count();
    }

    public function isSessionExpired(): bool
    {
        if (! $this->session_expired_at) {
            return false;
        }

        return now()->startOfDay()->gt($this->session_expired_at);
    }

    public function canScheduleSessions(): bool
    {
        return $this->registration_status === self::STATUS_ACTIVE;
    }

    public function getRegistrationStatusLabelAttribute(): string
    {
        return match ($this->registration_status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_CLOSED => 'Closed',
            default => 'Active',
        };
    }

    public function getRemainingSessionCount(): int
    {
        return max(((int) $this->total_session) - $this->getUsedSessionCount(), 0);
    }

    public function getSessionSummaryAttribute(): array
    {
        $usedSession = $this->getUsedSessionCount();
        $isExpired = $this->isSessionExpired();
        $scheduledSession = $this->getScheduledSessionCount();
        $completedSession = $this->getCompletedSessionCount();
        $createdSession = $scheduledSession + $completedSession;

        return [
            'total_session' => (int) $this->total_session,
            'used_session' => $usedSession,
            'remaining_session' => max(((int) $this->total_session) - $usedSession, 0),
            'created_session' => $createdSession,
            'scheduled_session' => $scheduledSession,
            'completed_session' => $completedSession,
            'available_to_schedule' => max(((int) $this->total_session) - $createdSession, 0),
            'session_started_at' => $this->session_started_at,
            'session_expired_at' => $this->session_expired_at,
            'is_session_expired' => $isExpired,
        ];
    }
}
