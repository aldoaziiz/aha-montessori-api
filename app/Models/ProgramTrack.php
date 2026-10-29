<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramTrack extends Model
{
    protected $fillable = [
        'program_category_id',
        'subject_name',
        'delivery_mode',
        'price_per_session',
        'is_active',
    ];

    protected $casts = [
        'price_per_session' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ProgramCategory::class, 'program_category_id');
    }

    public function classes()
    {
        return $this->hasMany(ProgramTrackClass::class);
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }
}
