<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityPhoto extends Model
{
    protected $fillable = [
        'activity_id',
        'photo',
    ];

    // ======================
    // RELATIONS
    // ======================

    public function activity()
    {
        return $this->belongsTo(
            Activity::class
        );
    }
}
