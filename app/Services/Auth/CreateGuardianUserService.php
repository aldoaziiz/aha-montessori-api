<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class CreateGuardianUserService
{
    public function execute(
        string $name,
        string $email,
        string $phone
    ): User {

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Guardian email is invalid.');
        }

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
