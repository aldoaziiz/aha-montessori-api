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

class SchoolScheduleCalendarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('child_guardians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('guardian_id');
            $table->unsignedBigInteger('guardian_role_id')->nullable();
            $table->timestamps();
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
        Schema::create('program_category_session_times', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_category_id');
            $table->unsignedInteger('session_order');
            $table->string('session_name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('therapy_session_status_id');
            $table->boolean('uses_session')->default(false);
            $table->date('therapy_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });
        Schema::create('school_schedule_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('guardian_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('session_count');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['registration_id', 'year', 'month']);
        });

        DB::table('guardians')->insert([
            ['id' => 1, 'user_id' => 10],
            ['id' => 2, 'user_id' => 20],
        ]);
        DB::table('children')->insert([
            ['id' => 1, 'name' => 'Alya'],
            ['id' => 2, 'name' => 'Bima'],
        ]);
        DB::table('child_guardians')->insert([
            ['child_id' => 1, 'guardian_id' => 1],
            ['child_id' => 2, 'guardian_id' => 2],
        ]);
        DB::table('registrations')->insert([
            [
                'id' => 1,
                'registration_number' => 'REG-ALYA-001',
                'registration_status' => 'active',
                'child_id' => 1,
                'program_category_id' => 1,
                'total_session' => 6,
                'session_started_at' => '2026-09-01',
                'session_expired_at' => '2027-09-01',
            ],
            [
                'id' => 2,
                'registration_number' => 'REG-BIMA-001',
                'registration_status' => 'active',
                'child_id' => 2,
                'program_category_id' => 1,
                'total_session' => 8,
                'session_started_at' => '2026-09-01',
                'session_expired_at' => '2027-09-01',
            ],
        ]);
        DB::table('program_category_session_times')->insert([
            [
                'id' => 1,
                'program_category_id' => 1,
                'session_order' => 1,
                'session_name' => 'Toddler Morning',
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
                'capacity' => 2,
                'is_active' => true,
            ],
            [
                'id' => 2,
                'program_category_id' => 1,
                'session_order' => 2,
                'session_name' => 'Toddler Afternoon',
                'start_time' => '13:00:00',
                'end_time' => '14:00:00',
                'capacity' => 2,
                'is_active' => true,
            ],
            [
                'id' => 3,
                'program_category_id' => 1,
                'session_order' => 3,
                'session_name' => 'Inactive Session',
                'start_time' => '15:00:00',
                'end_time' => '16:00:00',
                'capacity' => 10,
                'is_active' => false,
            ],
            [
                'id' => 4,
                'program_category_id' => 2,
                'session_order' => 1,
                'session_name' => 'Other Category',
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'capacity' => 10,
                'is_active' => true,
            ],
        ]);
        DB::table('therapy_sessions')->insert([
            [
                'registration_id' => 1,
                'therapy_session_status_id' => 2,
                'uses_session' => true,
                'therapy_date' => '2026-09-10',
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
            ],
            [
                'registration_id' => 1,
                'therapy_session_status_id' => 1,
                'uses_session' => false,
                'therapy_date' => '2026-09-20',
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
            ],
            [
                'registration_id' => 2,
                'therapy_session_status_id' => 1,
                'uses_session' => false,
                'therapy_date' => '2026-09-16',
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
            ],
            [
                'registration_id' => 2,
                'therapy_session_status_id' => 1,
                'uses_session' => false,
                'therapy_date' => '2026-09-16',
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
            ],
            [
                'registration_id' => 2,
                'therapy_session_status_id' => 1,
                'uses_session' => false,
                'therapy_date' => '2026-09-16',
                'start_time' => '13:00:00',
                'end_time' => '14:00:00',
            ],
        ]);

        Route::get('/test-school-schedule-calendar', [SchoolScheduleController::class, 'calendar']);
    }

    public function test_calendar_returns_category_sessions_and_monthly_slot_occupancy(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $response = $this->getJson(
            '/test-school-schedule-calendar?registration_id=1&year=2026&month=9'
        )->assertOk()
            ->assertJsonPath('data.registration_id', 1)
            ->assertJsonPath('data.can_schedule', true)
            ->assertJsonPath('data.available_to_schedule', 4)
            ->assertJsonPath('data.dates.13.date', '2026-09-14')
            ->assertJsonPath('data.dates.13.is_selectable', false)
            ->assertJsonPath('data.dates.13.unavailable_reason', 'past_date')
            ->assertJsonPath('data.dates.15.date', '2026-09-16')
            ->assertJsonPath('data.dates.15.is_selectable', true)
            ->assertJsonCount(2, 'data.dates.15.sessions')
            ->assertJsonPath('data.dates.15.sessions.0.session_name', 'Toddler Morning')
            ->assertJsonPath('data.dates.15.sessions.0.occupied', 2)
            ->assertJsonPath('data.dates.15.sessions.0.capacity', 2)
            ->assertJsonPath('data.dates.15.sessions.0.is_full', true)
            ->assertJsonPath('data.dates.15.sessions.1.occupied', 1)
            ->assertJsonPath('data.dates.15.sessions.1.is_full', false)
            ->assertJsonCount(2, 'data.scheduled_sessions')
            ->assertJsonPath('data.scheduled_sessions.0.therapy_date', '2026-09-10')
            ->assertJsonPath('data.scheduled_sessions.0.session_name', 'Toddler Morning')
            ->assertJsonPath('data.scheduled_sessions.0.status.name', 'Completed')
            ->assertJsonPath('data.scheduled_sessions.1.therapy_date', '2026-09-20')
            ->assertJsonPath('data.scheduled_sessions.1.status.name', 'Scheduled');

        $this->assertContains('2026-09-16', $response->json('data.selectable_dates'));
    }

    public function test_guardian_cannot_read_another_child_registration_calendar(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $this->getJson('/test-school-schedule-calendar?registration_id=2&year=2026&month=9')
            ->assertNotFound();
    }

    public function test_calendar_rejects_past_months(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $this->getJson('/test-school-schedule-calendar?registration_id=1&year=2026&month=8')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_inactive_registration_has_no_selectable_dates(): void
    {
        DB::table('registrations')->where('id', 1)->update([
            'registration_status' => 'inactive',
        ]);
        $this->actingAs($this->user(10, 'guardian'));

        $this->getJson('/test-school-schedule-calendar?registration_id=1&year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.can_schedule', false)
            ->assertJsonPath('data.unavailable_reason', 'This registration is not active.')
            ->assertJsonCount(0, 'data.selectable_dates')
            ->assertJsonPath('data.dates.15.unavailable_reason', 'registration_unavailable');
    }

    public function test_expiration_date_is_selectable_but_the_next_date_is_not(): void
    {
        DB::table('registrations')->where('id', 1)->update([
            'session_expired_at' => '2026-09-16',
        ]);
        $this->actingAs($this->user(10, 'guardian'));

        $this->getJson('/test-school-schedule-calendar?registration_id=1&year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.dates.15.date', '2026-09-16')
            ->assertJsonPath('data.dates.15.is_selectable', true)
            ->assertJsonPath('data.dates.16.date', '2026-09-17')
            ->assertJsonPath('data.dates.16.is_selectable', false)
            ->assertJsonPath('data.dates.16.unavailable_reason', 'outside_validity');
    }

    public function test_existing_child_session_disables_its_date(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $this->getJson('/test-school-schedule-calendar?registration_id=1&year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('data.dates.19.date', '2026-09-20')
            ->assertJsonPath('data.dates.19.is_selectable', false)
            ->assertJsonPath('data.dates.19.unavailable_reason', 'already_scheduled');
    }

    private function user(int $id, string $role): User
    {
        $user = new User(['role' => $role]);
        $user->id = $id;
        $user->exists = true;

        return $user;
    }
}
