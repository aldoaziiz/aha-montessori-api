<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $fillable = [
        'therapy_session_id',
        'caption',
        'video',
    ];

    // ======================
    // RELATIONS
    // ======================

    public function therapySession()
    {
        return $this->belongsTo(
            TherapySession::class
        );
    }

    public function photos()
    {
        return $this->hasMany(
            ActivityPhoto::class
        );
    }
}
