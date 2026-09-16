<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ActivityController;
use App\Http\Controllers\API\StaffController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffUserRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->timestamps();
        });

        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->text('address')->nullable();
            $table->foreignId('staff_role_id');
            $table->foreignId('status_id');
            $table->timestamps();
        });

        $now = now();

        $this->app['db']->table('statuses')->insert([
            'id' => 1,
            'name' => 'Active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->app['db']->table('staff_roles')->insert([
            [
                'id' => 1,
                'name' => 'Staff',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'name' => 'Admin',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Route::post('/test/staff', [StaffController::class, 'store']);
        Route::post('/test/activity', [ActivityController::class, 'store']);
    }

    public function test_non_admin_staff_gets_teacher_login_role(): void
    {
        $this->postJson('/test/staff', [
            'name' => 'Teacher Test',
            'email' => 'teacher@example.com',
            'phone' => '081234567890',
            'staff_role_id' => 1,
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'teacher@example.com',
            'role' => 'teacher',
        ]);
    }

    public function test_admin_staff_keeps_admin_login_role(): void
    {
        $this->postJson('/test/staff', [
            'name' => 'Admin Test',
            'email' => 'admin-test@example.com',
            'phone' => '081234567891',
            'staff_role_id' => 2,
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'admin-test@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_legacy_staff_role_cannot_manage_activities(): void
    {
        $user = User::create([
            'name' => 'Legacy Staff',
            'email' => 'legacy-staff@example.com',
            'password' => 'password',
            'role' => 'staff',
        ]);

        $this->actingAs($user)
            ->postJson('/test/activity')
            ->assertForbidden();
    }

    public function test_teacher_role_can_reach_activity_creation_validation(): void
    {
        $user = User::create([
            'name' => 'Teacher',
            'email' => 'activity-teacher@example.com',
            'password' => 'password',
            'role' => 'teacher',
        ]);

        $this->actingAs($user)
            ->postJson('/test/activity')
            ->assertUnprocessable();
    }
}
