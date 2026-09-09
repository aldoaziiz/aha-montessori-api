<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Registration extends Model
{
    protected $fillable = [
        'registration_number',
        'child_id',
        'clinic_id',
        'complaint',
        'program_id',
        'payer_id',
        'room_id',
        'program_category_id',
        'total_session',
        'session_started_at',
        'session_expired_at',
    ];

    protected $casts = [
        'total_session' => 'integer',
        'session_started_at' => 'date',
        'session_expired_at' => 'date',
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
        $startedAt = $this->created_at
            ? Carbon::parse($this->created_at)->startOfDay()
            : now()->startOfDay();

        $this->forceFill([
            'total_session' => $this->calculateTotalSession(),
            'session_started_at' => $startedAt->toDateString(),
            'session_expired_at' => $startedAt->copy()->addYear()->toDateString(),
        ])->save();
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

    public function isSessionExpired(): bool
    {
        if (! $this->session_expired_at) {
            return false;
        }

        return now()->startOfDay()->gt($this->session_expired_at);
    }

    public function getRemainingSessionCount(): int
    {
        if ($this->isSessionExpired()) {
            return 0;
        }

        return max(((int) $this->total_session) - $this->getUsedSessionCount(), 0);
    }

    public function getSessionSummaryAttribute(): array
    {
        $usedSession = $this->getUsedSessionCount();
        $isExpired = $this->isSessionExpired();

        return [
            'total_session' => (int) $this->total_session,
            'used_session' => $usedSession,
            'remaining_session' => $isExpired
                ? 0
                : max(((int) $this->total_session) - $usedSession, 0),
            'session_started_at' => $this->session_started_at,
            'session_expired_at' => $this->session_expired_at,
            'is_session_expired' => $isExpired,
        ];
    }
}
