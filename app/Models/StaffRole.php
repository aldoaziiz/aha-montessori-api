<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffRole extends Model
{
    public function staff()
    {
        return $this->hasMany(Staff::class, 'staff_role_id');
    }
}
