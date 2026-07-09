<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payer extends Model
{
    protected $fillable = ['name'];

    public function programs()
    {
        return $this->hasMany(Program::class);
    }
}
