<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DevResetSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement(
            'SET FOREIGN_KEY_CHECKS=0;'
        );

        // ======================
        // ACTIVITY
        // ======================

        DB::table('activity_photos')
            ->truncate();

        DB::table('activities')
            ->truncate();

        // ======================
        // THERAPY
        // ======================

        DB::table('therapy_sessions')
            ->truncate();

        // ======================
        // REGISTRATION
        // ======================

        DB::table('child_guardians')
            ->truncate();

        DB::table('registrations')
            ->truncate();

        DB::table('children')
            ->truncate();

        DB::table('guardians')
            ->truncate();

        // ======================
        // STAFF
        // ======================

        DB::table('staff')
            ->truncate();

        // ======================
        // AUTH
        // ======================

        DB::table('personal_access_tokens')
            ->truncate();

        DB::table('users')
            ->truncate();

        DB::statement(
            'SET FOREIGN_KEY_CHECKS=1;'
        );

        // ======================
        // DEFAULT ADMIN
        // ======================

        DB::table('users')->insert([

            'name' => 'Admin',

            'email' => 'admin@test.com',

            'password' => Hash::make(
                'password'
            ),

            'role' => 'admin',

            'created_at' => now(),

            'updated_at' => now(),

        ]);
    }
}
