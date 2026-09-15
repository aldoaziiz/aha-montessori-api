<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolScheduleSubmission extends Model
{
    protected $fillable = [
        'registration_id',
        'guardian_id',
        'year',
        'month',
        'session_count',
        'submitted_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'session_count' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
}
