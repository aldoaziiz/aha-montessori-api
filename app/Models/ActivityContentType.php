<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityContentType extends Model
{
    protected $fillable = [
        'name',
    ];

    public function activities()
    {
        return $this->hasMany(
            Activity::class
        );
    }
}
