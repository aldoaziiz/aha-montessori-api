<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ChildGuardian extends Pivot
{
    protected $table = 'child_guardians';

    public function role()
    {
        return $this->belongsTo(GuardianRole::class, 'guardian_role_id');
    }

    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'child_guardians')
            ->using(ChildGuardian::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function guardianRole()
    {
        return $this->belongsTo(GuardianRole::class);
    }
}
