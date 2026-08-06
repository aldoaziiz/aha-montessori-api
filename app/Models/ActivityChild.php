<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityChild extends Model
{
    protected $fillable = [
        'activity_id',
        'child_id',
    ];

    public function activity()
    {
        return $this->belongsTo(
            Activity::class
        );
    }

    public function child()
    {
        return $this->belongsTo(
            Child::class
        );
    }
}
