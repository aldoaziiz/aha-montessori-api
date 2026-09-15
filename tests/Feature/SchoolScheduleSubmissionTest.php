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

class SchoolScheduleSubmissionTest extends TestCase
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
            $table->unsignedBigInteger('therapist_id')->nullable();
            $table->unsignedBigInteger('therapy_session_status_id');
            $table->boolean('uses_session')->default(false);
            $table->date('therapy_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('notes')->nullable();
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
                'total_session' => 5,
                'session_started_at' => '2026-09-01',
                'session_expired_at' => '2027-09-01',
            ],
            [
                'id' => 2,
                'registration_number' => 'REG-BIMA-001',
                'registration_status' => 'active',
                'child_id' => 2,
                'program_category_id' => 1,
                'total_session' => 10,
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
                'capacity' => 1,
                'is_active' => true,
            ],
            [
                'id' => 3,
                'program_category_id' => 2,
                'session_order' => 1,
                'session_name' => 'Other Category',
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'capacity' => 10,
                'is_active' => true,
            ],
            [
                'id' => 4,
                'program_category_id' => 1,
                'session_order' => 3,
                'session_name' => 'Inactive Session',
                'start_time' => '15:00:00',
                'end_time' => '16:00:00',
                'capacity' => 10,
                'is_active' => false,
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
        ]);

        Route::post('/test-school-schedule-submissions', [SchoolScheduleController::class, 'submit']);
        Route::get('/test-school-schedule-submission-calendar', [SchoolScheduleController::class, 'calendar']);
    }

    public function test_guardian_can_submit_one_atomic_monthly_schedule(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $this->postJson('/test-school-schedule-submissions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.submission.registration_id', 1)
            ->assertJsonPath('data.submission.guardian_id', 1)
            ->assertJsonPath('data.submission.year', 2026)
            ->assertJsonPath('data.submission.month', 9)
            ->assertJsonPath('data.submission.session_count', 2)
            ->assertJsonCount(2, 'data.sessions');

        $this->assertDatabaseHas('school_schedule_submissions', [
            'registration_id' => 1,
            'guardian_id' => 1,
            'year' => 2026,
            'month' => 9,
            'session_count' => 2,
        ]);
        $this->assertDatabaseHas('therapy_sessions', [
            'registration_id' => 1,
            'therapy_session_status_id' => 1,
            'uses_session' => false,
            'therapy_date' => '2026-09-16',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
    }

    public function test_submitted_month_is_locked_and_calendar_reports_the_lock(): void
    {
        $this->actingAs($this->user(10, 'guardian'));
        $this->postJson('/test-school-schedule-submissions', $this->payload())->assertCreated();

        $this->postJson('/test-school-schedule-submissions', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        $this->assertDatabaseCount('school_schedule_submissions', 1);
        $this->assertDatabaseCount('therapy_sessions', 4);

        $this->getJson(
            '/test-school-schedule-submission-calendar?registration_id=1&year=2026&month=9'
        )->assertOk()
            ->assertJsonPath('data.is_submitted', true)
            ->assertJsonPath('data.can_schedule', false)
            ->assertJsonPath(
                'data.unavailable_reason',
                'The schedule for this month has already been submitted.'
            )
            ->assertJsonCount(0, 'data.selectable_dates')
            ->assertJsonCount(4, 'data.scheduled_sessions')
            ->assertJsonPath('data.scheduled_sessions.1.therapy_date', '2026-09-16')
            ->assertJsonPath('data.scheduled_sessions.1.status.name', 'Scheduled');
    }

    public function test_full_slot_rejects_the_entire_submission(): void
    {
        DB::table('therapy_sessions')->insert([
            'registration_id' => 2,
            'therapy_session_status_id' => 1,
            'uses_session' => false,
            'therapy_date' => '2026-09-17',
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
        ]);
        $this->actingAs($this->user(10, 'guardian'));

        $this->postJson('/test-school-schedule-submissions', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.1.session_time_id');

        $this->assertDatabaseCount('school_schedule_submissions', 0);
        $this->assertDatabaseMissing('therapy_sessions', [
            'registration_id' => 1,
            'therapy_date' => '2026-09-16',
        ]);
    }

    public function test_submission_cannot_exceed_remaining_unscheduled_entitlement(): void
    {
        DB::table('registrations')->where('id', 1)->update(['total_session' => 3]);
        $this->actingAs($this->user(10, 'guardian'));

        $this->postJson('/test-school-schedule-submissions', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions');

        $this->assertDatabaseCount('school_schedule_submissions', 0);
    }

    public function test_submission_rejects_wrong_category_inactive_and_existing_dates(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $wrongCategory = $this->payload();
        $wrongCategory['sessions'][0]['session_time_id'] = 3;
        $this->postJson('/test-school-schedule-submissions', $wrongCategory)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.session_time_id');

        $inactive = $this->payload();
        $inactive['sessions'][0]['session_time_id'] = 4;
        $this->postJson('/test-school-schedule-submissions', $inactive)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.session_time_id');

        $existingDate = $this->payload();
        $existingDate['sessions'][0]['therapy_date'] = '2026-09-20';
        $this->postJson('/test-school-schedule-submissions', $existingDate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.therapy_date');

        $this->assertDatabaseCount('school_schedule_submissions', 0);
    }

    public function test_submission_enforces_ownership_month_and_registration_state(): void
    {
        $this->actingAs($this->user(10, 'guardian'));

        $otherRegistration = $this->payload();
        $otherRegistration['registration_id'] = 2;
        $this->postJson('/test-school-schedule-submissions', $otherRegistration)
            ->assertNotFound();

        $wrongMonth = $this->payload();
        $wrongMonth['sessions'][0]['therapy_date'] = '2026-10-16';
        $this->postJson('/test-school-schedule-submissions', $wrongMonth)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.therapy_date');

        $pastDate = $this->payload();
        $pastDate['sessions'][0]['therapy_date'] = '2026-09-14';
        $this->postJson('/test-school-schedule-submissions', $pastDate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sessions.0.therapy_date');

        DB::table('registrations')->where('id', 1)->update([
            'registration_status' => 'inactive',
        ]);
        $this->postJson('/test-school-schedule-submissions', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('registration_id');

        $this->assertDatabaseCount('school_schedule_submissions', 0);
    }

    public function test_expiration_date_is_valid_for_submission(): void
    {
        DB::table('registrations')->where('id', 1)->update([
            'session_expired_at' => '2026-09-16',
        ]);
        $payload = $this->payload();
        $payload['sessions'] = [$payload['sessions'][0]];
        $this->actingAs($this->user(10, 'guardian'));

        $this->postJson('/test-school-schedule-submissions', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('therapy_sessions', [
            'registration_id' => 1,
            'therapy_date' => '2026-09-16',
        ]);
    }

    public function test_non_guardian_cannot_submit_a_school_schedule(): void
    {
        $this->actingAs($this->user(30, 'admin'));

        $this->postJson('/test-school-schedule-submissions', $this->payload())
            ->assertForbidden();
    }

    private function payload(): array
    {
        return [
            'registration_id' => 1,
            'year' => 2026,
            'month' => 9,
            'sessions' => [
                [
                    'therapy_date' => '2026-09-16',
                    'session_time_id' => 1,
                ],
                [
                    'therapy_date' => '2026-09-17',
                    'session_time_id' => 2,
                ],
            ],
        ];
    }

    private function user(int $id, string $role): User
    {
        $user = new User(['role' => $role]);
        $user->id = $id;
        $user->exists = true;

        return $user;
    }
}
