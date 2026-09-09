<?php

namespace Tests\Feature;

use App\Models\Registration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillRegistrationSessionsTest extends TestCase
{
    private string $testStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->testStorage = sys_get_temp_dir().'/aha-session-backfill-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($this->testStorage);

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->integer('total_session')->nullable();
            $table->date('session_started_at')->nullable();
            $table->date('session_expired_at')->nullable();
            $table->timestamps();
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->integer('session_count')->nullable();
        });
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->integer('registration_id');
            $table->integer('program_id');
            $table->integer('learning_period_months')->nullable();
        });
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->integer('registration_id');
            $table->integer('therapy_session_status_id');
            $table->boolean('uses_session')->nullable();
            $table->date('therapy_date');
            $table->text('notes');
            $table->timestamps();
        });
        DB::table('registrations')->insert([
            'id' => 1, 'created_at' => '2025-01-15 11:30:00', 'updated_at' => '2025-02-01 12:00:00',
        ]);
        DB::table('programs')->insert(['id' => 1, 'session_count' => 12]);
        DB::table('registration_programs')->insert([
            'registration_id' => 1, 'program_id' => 1, 'learning_period_months' => 6,
        ]);
        foreach ([1 => false, 2 => false, 3 => true] as $status => $usesSession) {
            DB::table('therapy_sessions')->insert([
                'registration_id' => 1, 'therapy_session_status_id' => $status,
                'uses_session' => $usesSession, 'therapy_date' => '2025-02-01',
                'notes' => 'Keep historical notes', 'created_at' => '2025-01-20 12:00:00',
                'updated_at' => '2025-02-01 12:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Only remove the unique directory this test created under the system temp directory.
        if (isset($this->testStorage) && str_starts_with($this->testStorage, sys_get_temp_dir().'/aha-session-backfill-')) {
            File::deleteDirectory($this->testStorage);
        }
        parent::tearDown();
    }

    private function snapshot(): array
    {
        return [
            DB::table('registrations')->orderBy('id')->get()->toJson(),
            DB::table('therapy_sessions')->orderBy('id')->get()->toJson(),
        ];
    }

    public function test_preview_does_not_change_database_or_create_backup(): void
    {
        $before = $this->snapshot();
        $this->artisan('aha:backfill-registration-sessions')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertDirectoryDoesNotExist($this->testStorage.'/app/private/session-backfill');
    }

    public function test_apply_backfills_total_dates_and_usage_without_changing_history(): void
    {
        $history = DB::table('therapy_sessions')->orderBy('id')->get()->map(function ($row) {
            unset($row->uses_session);

            return (array) $row;
        })->all();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('registrations', [
            'id' => 1, 'total_session' => 72, 'session_started_at' => '2025-01-15',
            'session_expired_at' => '2026-01-15', 'updated_at' => '2025-02-01 12:00:00',
        ]);
        $this->assertSame([0, 1, 0], DB::table('therapy_sessions')->orderBy('id')->pluck('uses_session')->all());
        $after = DB::table('therapy_sessions')->orderBy('id')->get()->map(function ($row) {
            unset($row->uses_session);

            return (array) $row;
        })->all();
        $this->assertSame($history, $after);
        $files = File::files($this->testStorage.'/app/private/session-backfill');
        $this->assertCount(1, $files);
        $backup = json_decode(File::get($files[0]->getPathname()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($backup['changes']['registrations'][0]['before']['total_session']);
        $this->assertSame(72, $backup['changes']['registrations'][0]['after']['total_session']);
        $this->assertCount(2, $backup['changes']['therapy_sessions']);
        $this->travelTo(now()->setDate(2026, 1, 15)->endOfDay());
        $this->assertSame(71, Registration::findOrFail(1)->session_summary['remaining_session']);
        $this->travelTo(now()->addDay()->startOfDay());
        $summary = Registration::findOrFail(1)->session_summary;
        $this->assertSame(0, $summary['remaining_session']);
        $this->assertSame(1, $summary['used_session']);
        $this->assertSame(72, $summary['total_session']);
    }

    public function test_rerun_is_idempotent_and_does_not_create_another_backup(): void
    {
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertSuccessful();
        $before = $this->snapshot();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertCount(1, File::files($this->testStorage.'/app/private/session-backfill'));
    }

    public function test_existing_values_including_zero_are_preserved_and_partial_dates_are_filled(): void
    {
        DB::table('registrations')->where('id', 1)->update([
            'total_session' => 0, 'session_started_at' => '2025-02-10',
        ]);
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('registrations', [
            'id' => 1, 'total_session' => 0, 'session_started_at' => '2025-02-10',
            'session_expired_at' => '2026-02-10',
        ]);
    }

    public function test_multiple_programs_are_summed(): void
    {
        DB::table('programs')->insert(['id' => 2, 'session_count' => 8]);
        DB::table('registration_programs')->insert([
            'registration_id' => 1, 'program_id' => 2, 'learning_period_months' => 3,
        ]);
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('registrations', ['id' => 1, 'total_session' => 96]);
    }

    public function test_missing_program_duration_or_created_date_aborts_every_change(): void
    {
        DB::table('registrations')->insert(['id' => 2]);
        $before = $this->snapshot();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());

        DB::table('registration_programs')->insert(['registration_id' => 2, 'program_id' => 1]);
        $before = $this->snapshot();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());

        DB::table('registration_programs')->where('registration_id', 2)->update(['learning_period_months' => 1]);
        $before = $this->snapshot();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_write_failure_rolls_back_entitlement_and_usage(): void
    {
        DB::unprepared("CREATE TRIGGER reject_backfill BEFORE UPDATE ON therapy_sessions BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $before = $this->snapshot();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_leap_day_matches_new_registration_entitlement(): void
    {
        DB::table('registrations')->where('id', 1)->update(['created_at' => '2024-02-29 12:00:00']);
        $expected = Carbon::parse('2024-02-29')->addYear()->toDateString();
        $this->artisan('aha:backfill-registration-sessions', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('registrations', ['id' => 1, 'session_expired_at' => $expected]);
    }
}
