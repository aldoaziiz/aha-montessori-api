<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    protected $fillable = [
        'id_number',
        'name',
        'nickname',
        'birth_date',
        'gender',
        'address',
        'status_id',
        'birthplace_id',
        'hometown_id',
        'school_id',
        'school_class_id',
        'school_education_id',
    ];

    protected static function booted()
    {
        static::creating(function ($child) {
            if (! $child->status_id) {
                $child->status_id = 1;
            }
        });
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function birthplace()
    {
        return $this->belongsTo(City::class, 'birthplace_id');
    }

    public function hometown()
    {
        return $this->belongsTo(City::class, 'hometown_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schoolEducation()
    {
        return $this->belongsTo(SchoolEducation::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'child_guardians')
            ->using(ChildGuardian::class)
            ->withPivot('guardian_role_id')
            ->withTimestamps();
    }
}
