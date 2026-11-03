<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentDetail extends Model
{
    protected $fillable = [
        'registration_id',
        'appointment_date',
        'start_time',
        'end_time',
        'report_disk',
        'report_path',
        'report_original_name',
        'report_uploaded_by',
        'report_uploaded_at',
    ];

    protected $casts = [
        'appointment_date' => 'date:Y-m-d',
        'report_uploaded_at' => 'datetime',
    ];

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function reportUploader()
    {
        return $this->belongsTo(User::class, 'report_uploaded_by');
    }
}
