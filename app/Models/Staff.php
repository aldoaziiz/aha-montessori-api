<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'staff_role_id',
        'status_id',
        'user_id',
    ];

    public function staffRole()
    {
        return $this->belongsTo(StaffRole::class);
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function therapySessions()
    {
        return $this->hasMany(TherapySession::class, 'therapist_id');
    }

    public function user()
    {
        return $this->belongsTo(
            User::class
        );
    }
}
