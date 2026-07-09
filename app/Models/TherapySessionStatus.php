<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TherapySessionStatus extends Model
{
    protected $fillable = [
        'name',
    ];

    // ======================
    // RELATIONS
    // ======================

    public function therapySessions()
    {
        return $this->hasMany(TherapySession::class);
    }
}
