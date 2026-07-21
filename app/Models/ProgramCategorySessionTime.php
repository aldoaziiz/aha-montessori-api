<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramCategorySessionTime extends Model
{
    protected $table = 'program_category_session_times';

    protected $fillable = [
        'program_category_id',
        'session_order',
        'session_name',
        'start_time',
        'end_time',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function programCategory()
    {
        return $this->belongsTo(ProgramCategory::class);
    }
}
