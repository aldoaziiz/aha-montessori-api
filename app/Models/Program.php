<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    protected $table = 'programs';

    protected $fillable = [
        'name',
        'payer_id',
        'description',
        'price',
        'session_count',
        'clinic_id',
        'program_category_id',
        'program_track_id',
        'is_registration_fee',
        'order_number',
        'status_id',
    ];

    protected $casts = [
        'is_registration_fee' => 'boolean',
    ];

    public function clinic()
    {
        return $this->belongsTo(
            Clinic::class
        );
    }

    public function category()
    {
        return $this->belongsTo(
            ProgramCategory::class,
            'program_category_id'
        );
    }

    public function track()
    {
        return $this->belongsTo(ProgramTrack::class, 'program_track_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function registrationPrograms()
    {
        return $this->hasMany(
            RegistrationProgram::class
        );
    }

    public function registrations()
    {
        return $this->belongsToMany(
            Registration::class,
            'registration_programs'
        )->withPivot('price');
    }

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }
}
