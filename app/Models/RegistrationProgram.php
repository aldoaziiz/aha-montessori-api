<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationProgram extends Model
{
    protected $fillable = [
        'registration_id',
        'program_id',
        'price',
    ];

    public function registration()
    {
        return $this->belongsTo(
            Registration::class
        );
    }

    public function program()
    {
        return $this->belongsTo(
            Program::class
        );
    }
}
