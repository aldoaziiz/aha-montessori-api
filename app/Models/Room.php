<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status_id',
    ];

    // ======================
    // RELATIONS
    // ======================

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function therapySessions()
    {
        return $this->hasMany(TherapySession::class);
    }
}
