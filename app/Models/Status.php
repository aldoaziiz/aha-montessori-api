<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function children()
    {
        return $this->hasMany(Child::class);
    }

    public function guardians()
    {
        return $this->hasMany(Guardian::class);
    }
}
