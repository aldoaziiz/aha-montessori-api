<?php

namespace Tests\Feature;

use App\Http\Controllers\API\TherapySessionController;
use App\Models\Registration;
use App\Models\TherapySession;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GenerateSessionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-01 10:00:00'));
        // Standalone in-memory schema: no migrations or application database changes.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('total_session')->nullable();
            $table->date('session_started_at')->nullable();
            $table->date('session_expired_at')->nullable();
            $table->timestamps();
        });
        Schema::create('program_category_session_times', function (Blueprint $table) {
            $table->id();
            $table->string('session_name');
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('capacity');
        });
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('therapist_id')->nullable();
            $table->integer('therapy_session_status_id');
            $table->boolean('uses_session')->default(false);
            $table->date('therapy_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('program_id');
            $table->integer('price')->nullable();
            $table->integer('learning_period_months')->nullable();
        });
        Schema::create('therapy_session_statuses', function (Blueprint $table) {
            $table->id();
        });
        DB::table('registrations')->insert([
            'id' => 1, 'total_session' => 5,
            'session_started_at' => '2026-09-01', 'session_expired_at' => '2027-09-01',
        ]);
        DB::table('program_category_session_times')->insert([
            'id' => 1, 'session_name' => 'Session 1', 'start_time' => '08:00:00',
            'end_time' => '09:30:00', 'capacity' => 10,
        ]);
        $this->actingAs(new User(['role' => 'admin']));
        Route::post('/test-generate', [TherapySessionController::class, 'generate']);
        Route::post('/test-single', [TherapySessionController::class, 'store']);
        Route::post('/test-bulk-validate', [TherapySessionController::class, 'bulkValidate']);
        Route::post('/test-bulk', [TherapySessionController::class, 'bulkStore']);
        Route::put('/test-session/{id}', [TherapySessionController::class, 'update']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'registration_id' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-10-31',
            'schedule_configs' => [['day' => 2, 'session_time_id' => 1]],
        ], $overrides);
    }

    private function seedSession(string $date, int $status = 1, int $registrationId = 1): void
    {
        TherapySession::create([
            'registration_id' => $registrationId,
            'therapy_date' => $date,
            'therapy_session_status_id' => $status,
            'uses_session' => $status === 2,
            'start_time' => '08:00:00', 'end_time' => '09:30:00',
        ]);
    }

    public function test_append_subtracts_used_and_scheduled_and_skips_existing_dates(): void
    {
        $this->seedSession('2026-09-01', 2);
        $this->seedSession('2026-09-08');
        $this->seedSession('2026-09-15', 3);
        $this->postJson('/test-generate', $this->payload())
            ->assertOk()->assertJsonPath('target_sessions', 3)->assertJsonPath('generated_sessions', 3);
        $this->assertSame(6, TherapySession::count());
        $this->assertSame(6, TherapySession::distinct()->count('therapy_date'));
        $this->assertSame(1, TherapySession::where('uses_session', true)->count());
        $this->postJson('/test-generate', $this->payload())->assertStatus(422);
        $this->assertSame(6, TherapySession::count());
    }

    public function test_end_date_is_inclusive_and_can_stop_before_target(): void
    {
        $this->postJson('/test-generate', $this->payload(['end_date' => '2026-09-08']))
            ->assertOk()->assertJsonPath('generated_sessions', 2)->assertJsonPath('target_sessions', 5);
        $this->assertSame('2026-09-08', TherapySession::max('therapy_date'));
    }

    public function test_invalid_date_range_and_duplicate_weekdays_are_rejected(): void
    {
        $this->postJson('/test-generate', $this->payload(['end_date' => '2026-08-31']))
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/test-generate', $this->payload(['schedule_configs' => [
            ['day' => 2, 'session_time_id' => 1], ['day' => 2, 'session_time_id' => 1],
        ]]))->assertUnprocessable();
        $this->assertSame(0, TherapySession::count());
    }

    public function test_full_slot_rejects_entire_batch(): void
    {
        DB::table('program_category_session_times')->update(['capacity' => 1]);
        $this->seedSession('2026-09-08', 1, 2);
        $this->postJson('/test-generate', $this->payload(['end_date' => '2026-09-08']))
            ->assertUnprocessable()->assertJsonCount(1, 'conflicts');
        $this->assertSame(0, TherapySession::where('registration_id', 1)->count());
    }

    public function test_manual_single_rejects_when_scheduled_would_exceed_total(): void
    {
        DB::table('registrations')->update(['total_session' => 1]);
        $this->seedSession('2026-09-01');

        $this->postJson('/test-single', [
            'registration_id' => 1,
            'session_time_id' => 1,
            'therapy_date' => '2026-09-08',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Scheduling these sessions would exceed the registration session total of 1.');

        $this->assertSame(1, TherapySession::where('registration_id', 1)->count());
    }

    public function test_manual_bulk_rejects_when_scheduled_would_exceed_total(): void
    {
        DB::table('registrations')->update(['total_session' => 2]);
        $this->seedSession('2026-09-01');
        $bulk = [
            'registration_id' => 1,
            'sessions' => [
                ['therapy_date' => '2026-09-08', 'session_time_id' => 1],
                ['therapy_date' => '2026-09-15', 'session_time_id' => 1],
            ],
        ];

        $this->postJson('/test-bulk-validate', $bulk)
            ->assertUnprocessable()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Scheduling these sessions would exceed the registration session total of 2.');
        $this->postJson('/test-bulk', $bulk)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Scheduling these sessions would exceed the registration session total of 2.');

        $this->assertSame(1, TherapySession::where('registration_id', 1)->count());
    }

    public function test_missing_entitlement_and_no_matching_dates_do_not_insert(): void
    {
        $this->postJson('/test-generate', $this->payload(['start_date' => '2026-09-02', 'end_date' => '2026-09-02']))
            ->assertUnprocessable();
        DB::table('registrations')->update(['total_session' => null]);
        $this->postJson('/test-generate', $this->payload())->assertUnprocessable();
        $this->assertSame(0, TherapySession::count());
    }

    public function test_generate_rejects_dates_outside_validity_and_missing_dates(): void
    {
        foreach ([
            ['start_date' => '2026-08-31'],
            ['end_date' => '2027-09-02'],
        ] as $overrides) {
            $this->postJson('/test-generate', $this->payload($overrides))->assertUnprocessable();
        }
        DB::table('registrations')->update(['session_started_at' => null]);
        $this->postJson('/test-generate', $this->payload())->assertUnprocessable();
        $this->assertSame(0, TherapySession::count());
    }

    public function test_last_day_is_valid_and_expiry_preserves_used_sessions(): void
    {
        $this->seedSession('2026-09-01', 2);
        $this->travelTo(Carbon::parse('2027-09-01 23:59:00'));
        $this->postJson('/test-generate', $this->payload([
            'start_date' => '2027-09-01', 'end_date' => '2027-09-01',
            'schedule_configs' => [['day' => 3, 'session_time_id' => 1]],
        ]))->assertOk()->assertJsonPath('generated_sessions', 1);
        $this->travelTo(Carbon::parse('2027-09-02 00:00:00'));
        $summary = Registration::findOrFail(1)->session_summary;
        $this->assertTrue($summary['is_session_expired']);
        $this->assertSame(5, $summary['total_session']);
        $this->assertSame(1, $summary['used_session']);
        $this->assertSame(0, $summary['remaining_session']);
        $this->postJson('/test-generate', $this->payload())->assertUnprocessable();
        $this->assertSame(2, TherapySession::count());
    }

    public function test_manual_bulk_and_reschedule_reject_out_of_range_dates(): void
    {
        $this->seedSession('2026-09-01');
        foreach (['2026-08-31', '2027-09-02'] as $date) {
            $single = [
                'registration_id' => 1, 'session_time_id' => 1,
                'therapy_date' => $date, 'start_time' => '08:00:00', 'end_time' => '09:30:00',
            ];
            $this->postJson('/test-single', $single)->assertUnprocessable();
            $this->putJson('/test-session/1', $single)->assertUnprocessable();
            $bulk = ['registration_id' => 1, 'sessions' => [
                ['therapy_date' => '2026-09-08', 'session_time_id' => 1],
                ['therapy_date' => $date, 'session_time_id' => 1],
            ]];
            $this->postJson('/test-bulk-validate', $bulk)->assertUnprocessable()
                ->assertJsonValidationErrors('sessions.1.therapy_date');
            $this->postJson('/test-bulk', $bulk)->assertUnprocessable();
        }
        $this->assertSame(1, TherapySession::count());
        $this->assertSame('2026-09-01', TherapySession::first()->therapy_date);
    }

    public function test_expired_registration_blocks_scheduling_but_allows_notes(): void
    {
        $this->seedSession('2026-09-01');
        $this->travelTo(Carbon::parse('2027-09-02'));
        $single = [
            'registration_id' => 1, 'session_time_id' => 1,
            'therapy_date' => '2026-09-08', 'start_time' => '08:00:00', 'end_time' => '09:30:00',
        ];
        $this->postJson('/test-single', $single)->assertUnprocessable();
        $this->putJson('/test-session/1', $single)->assertUnprocessable();
        $bulk = ['registration_id' => 1, 'sessions' => [
            ['therapy_date' => '2026-09-08', 'session_time_id' => 1],
        ]];
        $this->postJson('/test-bulk-validate', $bulk)->assertUnprocessable();
        $this->postJson('/test-bulk', $bulk)->assertUnprocessable();
        $single['therapy_date'] = '2026-09-01';
        $single['notes'] = 'Historical note';
        $this->putJson('/test-session/1', $single)->assertOk();
        $this->assertSame('Historical note', TherapySession::first()->notes);
        $this->assertSame(1, TherapySession::count());
    }

    public function test_insert_failure_rolls_back_entire_batch(): void
    {
        $inserts = 0;
        TherapySession::creating(function () use (&$inserts) {
            if (++$inserts === 2) {
                throw new \RuntimeException('Simulated insert failure');
            }
        });
        try {
            $this->postJson('/test-generate', $this->payload())->assertStatus(500);
            $this->assertSame(0, TherapySession::count());
        } finally {
            TherapySession::flushEventListeners();
        }
    }
}
