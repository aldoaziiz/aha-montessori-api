<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Status::insert([
            [
                'code' => 1,
                'name' => 'Active',
            ],
            [
                'code' => 2,
                'name' => 'Inactive',
            ],
            [
                'code' => 3,
                'name' => 'Prospect',
            ],
            [
                'code' => 4,
                'name' => 'Interacted',
            ],
            [
                'code' => 5,
                'name' => 'Accepted',
            ],
            [
                'code' => 6,
                'name' => 'Done',
            ],
            [
                'code' => 7,
                'name' => 'Enrolled',
            ],
            [
                'code' => 8,
                'name' => 'Rejected',
            ],
        ]);
    }
}
