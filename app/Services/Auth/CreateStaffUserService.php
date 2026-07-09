<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateStaffUserService
{
    public function execute(
        string $name,
        string $email,
        ?string $phone,
        string $role
    ) {

        return User::create([

            'name' => $name,

            'email' => $email,

            'password' => Hash::make(
                $phone ?? 'password'
            ),

            'role' => $role,

        ]);
    }
}
