<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuardianRole extends Model
{
    protected $table = 'guardian_roles';

    protected $fillable = [
        'name',
    ];

    public function guardians()
    {
        return $this->hasMany(Guardian::class);
    }
}
