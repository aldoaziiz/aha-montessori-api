<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateGuardianUserService
{
    public function execute(
        string $name,
        string $email,
        string $phone
    ): User {

        // ======================
        // CHECK EXISTING USER
        // ======================

        $existingUser = User::query()
            ->where('email', $email)
            ->first();

        if ($existingUser) {
            return $existingUser;
        }

        // ======================
        // CREATE USER
        // ======================

        return User::query()
            ->create([

                'name' => $name,

                'email' => $email,

                'password' => Hash::make(
                    $phone
                ),

                'role' => 'guardian',

            ]);
    }
}
