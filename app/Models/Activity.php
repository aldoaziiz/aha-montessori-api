<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'program_category_id',

        'therapy_date',

        'program_category_session_time_id',

        'staff_id',

        'description',
    ];

    public function programCategory()
    {
        return $this->belongsTo(
            ProgramCategory::class
        );
    }

    public function staff()
    {
        return $this->belongsTo(
            Staff::class
        );
    }

    public function children()
    {
        return $this->belongsToMany(
            Child::class,
            'activity_children'
        )->withTimestamps();
    }

    public function media()
    {
        return $this->hasMany(
            ActivityMedia::class
        )->orderBy('sort_order');
    }

    public function programCategorySessionTime()
    {
        return $this->belongsTo(
            ProgramCategorySessionTime::class
        );
    }
}
