<?php

namespace Tests\Feature;

use App\Http\Controllers\API\TherapySessionController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolSessionsListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['children', 'program_categories', 'therapy_session_statuses', 'staff_roles'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
        }
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_role_id')->nullable();
        });
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('program_category_id')->nullable();
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_category_id')->nullable();
        });
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('program_id');
            $table->integer('price')->default(0);
            $table->integer('learning_period_months')->nullable();
        });
        Schema::create('program_category_session_times', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('program_category_id');
            $table->string('session_name');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(false);
        });
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('therapist_id')->nullable();
            $table->unsignedBigInteger('therapy_session_status_id')->default(1);
            $table->boolean('uses_session')->default(false);
            $table->boolean('allow_late_activity')->default(false);
            $table->date('therapy_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });

        DB::table('children')->insert(['id' => 1, 'name' => 'Test Child']);
        DB::table('staff')->insert(['id' => 99]);
        DB::table('therapy_session_statuses')->insert(['id' => 1, 'name' => 'Scheduled']);
        foreach ([1 => 'TODDLER', 2 => 'KINDER'] as $id => $name) {
            DB::table('program_categories')->insert(compact('id', 'name'));
            DB::table('programs')->insert(['id' => $id, 'program_category_id' => $id]);
            DB::table('program_category_session_times')->insert([
                'program_category_id' => $id, 'session_name' => "SESSION 1 $name",
                'start_time' => '08:00:00', 'end_time' => '09:30:00',
            ]);
        }
        // Explicit category wins; legacy category is only inferred when unambiguous.
        foreach ([1 => 1, 2 => null, 3 => null] as $id => $categoryId) {
            DB::table('registrations')->insert([
                'id' => $id, 'child_id' => 1, 'program_category_id' => $categoryId,
            ]);
            DB::table('therapy_sessions')->insert([
                'id' => $id, 'registration_id' => $id, 'therapist_id' => $id === 2 ? 99 : null,
                'therapy_date' => '2026-10-02', 'start_time' => '08:00:00', 'end_time' => '09:30:00',
            ]);
        }
        DB::table('registration_programs')->insert([
            ['registration_id' => 1, 'program_id' => 2],
            ['registration_id' => 2, 'program_id' => 2],
            ['registration_id' => 3, 'program_id' => 1],
            ['registration_id' => 3, 'program_id' => 2],
        ]);

        Route::get('/test-school-sessions', [TherapySessionController::class, 'index']);
        foreach (['store', 'generate', 'bulkValidate', 'bulkStore'] as $action) {
            Route::post("/test-school-sessions/$action", [TherapySessionController::class, $action]);
        }
        Route::put('/test-school-sessions/{id}', [TherapySessionController::class, 'update']);
        Route::delete('/test-school-sessions/{id}', [TherapySessionController::class, 'destroy']);
        Route::put('/test-school-sessions/{id}/late', [TherapySessionController::class, 'allowLateActivity']);
        Route::patch('/test-school-sessions/{therapySession}/status', [TherapySessionController::class, 'updateStatus'])
            ->middleware(SubstituteBindings::class);
        Route::patch('/test-school-sessions/{therapySession}/alpha', [TherapySessionController::class, 'markAlpha'])
            ->middleware(SubstituteBindings::class);
    }

    public function test_teacher_without_staff_profile_can_view_all_sessions_with_correct_labels(): void
    {
        $this->actingAs(new User(['role' => 'teacher']));
        $this->getJson('/test-school-sessions')->assertOk()->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.program_category.name', 'TODDLER')
            ->assertJsonPath('data.0.session_name', 'SESSION 1 TODDLER')
            ->assertJsonPath('data.1.program_category.name', 'KINDER')
            ->assertJsonPath('data.1.session_name', 'SESSION 1 KINDER')
            ->assertJsonPath('data.2.program_category', null)
            ->assertJsonPath('data.2.session_name', null);
    }

    public function test_category_filter_uses_same_resolution_as_labels_and_pagination(): void
    {
        foreach (['admin', 'teacher'] as $role) {
            $this->actingAs(new User(['role' => $role]));
            foreach ([1, 2] as $id) {
                $this->getJson("/test-school-sessions?program_category_id=$id&per_page=1")
                    ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
            }
            $this->getJson('/test-school-sessions?program_category_id=999')->assertUnprocessable();
            $this->getJson('/test-school-sessions?program_category_id=1&therapy_date=2026-10-03')
                ->assertOk()->assertJsonPath('total', 0);
        }
    }

    public function test_guardian_cannot_view_all_sessions(): void
    {
        $this->actingAs(new User(['role' => 'guardian']));
        $this->getJson('/test-school-sessions')->assertForbidden();
    }

    public function test_teacher_cannot_modify_sessions_through_any_write_endpoint(): void
    {
        $this->actingAs(new User(['role' => 'teacher']));
        foreach (['store', 'generate', 'bulkValidate', 'bulkStore'] as $action) {
            $this->postJson("/test-school-sessions/$action", [])->assertForbidden();
        }
        $this->putJson('/test-school-sessions/1', [])->assertForbidden();
        $this->deleteJson('/test-school-sessions/1')->assertForbidden();
        $this->putJson('/test-school-sessions/1/late')->assertForbidden();
        $this->patchJson('/test-school-sessions/1/status', ['therapy_session_status_id' => 2])->assertForbidden();
        $this->patchJson('/test-school-sessions/1/alpha')->assertForbidden();
        $this->assertDatabaseCount('therapy_sessions', 3);
        $this->assertDatabaseHas('therapy_sessions', ['id' => 1, 'therapy_session_status_id' => 1, 'allow_late_activity' => false]);
    }

    public function test_admin_can_still_update_attendance(): void
    {
        DB::table('therapy_session_statuses')->insert(['id' => 2, 'name' => 'Completed']);
        $this->actingAs(new User(['role' => 'admin']));
        $this->patchJson('/test-school-sessions/1/status', ['therapy_session_status_id' => 2])->assertOk();
        $this->assertDatabaseHas('therapy_sessions', ['id' => 1, 'therapy_session_status_id' => 2, 'uses_session' => true]);
    }
}
