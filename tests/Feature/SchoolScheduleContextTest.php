<?php

namespace Tests\Feature;

use App\Http\Controllers\API\SchoolScheduleController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolScheduleContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nickname')->nullable();
        });
        Schema::create('child_guardians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('guardian_id');
            $table->unsignedBigInteger('guardian_role_id')->nullable();
            $table->timestamps();
        });
        Schema::create('program_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_category_id')->nullable();
        });
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number');
            $table->string('registration_status')->default('active');
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('program_category_id')->nullable();
            $table->unsignedInteger('total_session')->nullable();
            $table->date('session_started_at')->nullable();
            $table->date('session_expired_at')->nullable();
            $table->timestamps();
        });
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->boolean('uses_session')->default(false);
        });
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('program_id');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('learning_period_months')->nullable();
        });

        DB::table('guardians')->insert([
            ['id' => 1, 'user_id' => 10],
            ['id' => 2, 'user_id' => 20],
        ]);
        DB::table('children')->insert([
            ['id' => 1, 'name' => 'Alya', 'nickname' => 'Alya'],
            ['id' => 2, 'name' => 'Bima', 'nickname' => 'Bima'],
        ]);
        DB::table('child_guardians')->insert([
            ['child_id' => 1, 'guardian_id' => 1],
            ['child_id' => 2, 'guardian_id' => 2],
        ]);
        DB::table('program_categories')->insert([
            'id' => 1,
            'name' => 'Toddler',
        ]);
        DB::table('programs')->insert([
            'id' => 1,
            'program_category_id' => 1,
        ]);
        DB::table('registrations')->insert([
            [
                'id' => 1,
                'registration_number' => 'REG-ALYA-001',
                'registration_status' => 'active',
                'child_id' => 1,
                'program_category_id' => null,
                'total_session' => 10,
                'session_started_at' => '2026-09-01',
                'session_expired_at' => '2027-09-01',
                'created_at' => '2026-09-01 08:00:00',
            ],
            [
                'id' => 2,
                'registration_number' => 'REG-ALYA-002',
                'registration_status' => 'inactive',
                'child_id' => 1,
                'program_category_id' => 1,
                'total_session' => 4,
                'session_started_at' => '2026-09-01',
                'session_expired_at' => '2027-09-01',
                'created_at' => '2026-09-02 08:00:00',
            ],
            [
                'id' => 3,
                'registration_number' => 'REG-BIMA-001',
                'registration_status' => 'active',
                'child_id' => 2,
                'program_category_id' => 1,
                'total_session' => 12,
                'session_started_at' => '2026-09-01',
                'session_expired_at' => '2027-09-01',
                'created_at' => '2026-09-01 08:00:00',
            ],
        ]);
        DB::table('registration_programs')->insert([
            'registration_id' => 1,
            'program_id' => 1,
            'price' => 800000,
            'learning_period_months' => 6,
        ]);
        DB::table('therapy_sessions')->insert([
            ['registration_id' => 1, 'uses_session' => true],
            ['registration_id' => 1, 'uses_session' => true],
            ['registration_id' => 1, 'uses_session' => false],
        ]);

        Route::get('/test-school-schedule-context', [SchoolScheduleController::class, 'context']);
    }

    public function test_guardian_only_receives_registrations_for_linked_children(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $this->getJson('/test-school-schedule-context')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.registration_number', 'REG-ALYA-002')
            ->assertJsonPath('data.0.registration_status', 'inactive')
            ->assertJsonPath('data.1.registration_number', 'REG-ALYA-001')
            ->assertJsonPath('data.1.child.name', 'Alya')
            ->assertJsonPath('data.1.program_category.name', 'Toddler')
            ->assertJsonPath('data.1.total_session', 10)
            ->assertJsonPath('data.1.session_summary.total_session', 10)
            ->assertJsonPath('data.1.session_summary.used_session', 2)
            ->assertJsonPath('data.1.session_summary.remaining_session', 8)
            ->assertJsonPath('meta.current_date', '2026-09-15')
            ->assertJsonMissing(['registration_number' => 'REG-BIMA-001']);
    }

    public function test_guardian_without_profile_receives_an_empty_list(): void
    {
        $this->actingAs($this->user(30, 'guardian'));

        $this->getJson('/test-school-schedule-context')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_guardian_cannot_access_guardian_schedule_context(): void
    {
        $this->actingAs($this->user(40, 'admin'));

        $this->getJson('/test-school-schedule-context')->assertForbidden();
    }

    private function user(int $id, string $role): User
    {
        $user = new User(['role' => $role]);
        $user->id = $id;
        $user->exists = true;

        return $user;
    }
}
