<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ProgramController;
use App\Models\Program;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProgramUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['payers', 'clinics', 'program_categories', 'statuses'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
        }

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('session_count');
            $table->foreignId('payer_id');
            $table->foreignId('clinic_id')->nullable();
            $table->foreignId('program_category_id')->nullable();
            $table->foreignId('status_id')->nullable();
            $table->timestamps();
        });

        DB::table('payers')->insert(['id' => 1, 'name' => 'Parent']);
        Program::create(['name' => 'Test Program', 'payer_id' => 1, 'session_count' => 4]);

        Route::put('/test-programs/{id}', [ProgramController::class, 'update']);
        Route::get('/test-programs/{id}', [ProgramController::class, 'show']);
    }

    public function test_session_count_is_saved_and_returned_when_reopened(): void
    {
        foreach (['8', '0'] as $count) {
            $this->putJson('/test-programs/1', ['payer_id' => 1, 'session_count' => $count])
                ->assertOk();

            $this->assertDatabaseHas('programs', ['id' => 1, 'session_count' => (int) $count]);
            $this->getJson('/test-programs/1')->assertOk()
                ->assertJsonPath('session_count', (int) $count);
        }
    }

    public function test_invalid_session_counts_are_rejected_without_changing_saved_value(): void
    {
        foreach ([-1, 1.5, 'abc', null] as $count) {
            $this->putJson('/test-programs/1', ['payer_id' => 1, 'session_count' => $count])
                ->assertUnprocessable()->assertJsonValidationErrors('session_count');

            $this->assertDatabaseHas('programs', ['id' => 1, 'session_count' => 4]);
        }
    }

    public function test_omitting_session_count_preserves_saved_value(): void
    {
        $this->putJson('/test-programs/1', ['payer_id' => 1, 'name' => 'Updated Program'])
            ->assertOk();

        $this->assertDatabaseHas('programs', [
            'id' => 1, 'name' => 'Updated Program', 'session_count' => 4,
        ]);
    }
}
