<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
