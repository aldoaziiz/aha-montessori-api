<?php

namespace Tests\Feature;

use App\Http\Controllers\API\PublicRegistrationController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicRegistrationCategoryTest extends TestCase
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
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('program_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('session_count');
            $table->unsignedBigInteger('program_category_id')->nullable();
            $table->timestamps();
        });
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->string('id_number');
            $table->string('name');
            $table->date('birth_date');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('program_category_id')->nullable();
            $table->timestamps();
        });
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('id_number')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('address')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->timestamps();
        });
        Schema::create('child_guardians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('guardian_id');
            $table->unsignedBigInteger('guardian_role_id');
            $table->timestamps();
        });
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number');
            $table->string('registration_status')->default('active');
            $table->unsignedBigInteger('child_id');
            $table->text('complaint')->nullable();
            $table->unsignedBigInteger('program_id')->nullable();
            $table->unsignedBigInteger('program_category_id')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedInteger('total_session')->nullable();
            $table->date('session_started_at')->nullable();
            $table->date('session_expired_at')->nullable();
            $table->timestamps();
        });
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('program_id');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('learning_period_months')->nullable();
            $table->timestamps();
        });

        DB::table('program_categories')->insert([
            ['id' => 1, 'name' => 'Toddler'],
            ['id' => 2, 'name' => 'Kinder'],
        ]);
        DB::table('programs')->insert([
            'id' => 3,
            'name' => 'Toddler 2x/week',
            'price' => 800000,
            'session_count' => 8,
            'program_category_id' => 1,
        ]);

        Route::post(
            '/test-public-registrations',
            [PublicRegistrationController::class, 'store']
        );
    }

    public function test_public_registration_saves_category_on_registration_and_child(): void
    {
        $this->postJson('/test-public-registrations', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.program_category_id', 1)
            ->assertJsonPath('data.total_session', 48);

        $this->assertDatabaseHas('registrations', [
            'child_id' => 1,
            'program_id' => 3,
            'program_category_id' => 1,
            'total_session' => 48,
        ]);
        $this->assertDatabaseHas('children', [
            'id' => 1,
            'program_category_id' => 1,
        ]);
    }

    public function test_public_registration_rejects_program_from_another_category(): void
    {
        $payload = $this->payload();
        $payload['registration']['program_category_id'] = 2;

        $this->postJson('/test-public-registrations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('registration.program_ids');

        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('children', 0);
        $this->assertDatabaseCount('users', 0);
    }

    private function payload(): array
    {
        return [
            'child' => [
                'name' => 'Zavian Azzaki Aditya',
                'id_number' => '3174000000000001',
                'birth_date' => '2022-11-28',
            ],
            'guardian' => [
                'name' => 'Prameswara Dwi Cahyana',
                'email' => 'prameswara@example.com',
                'phone' => '081234567890',
                'guardian_role_id' => 1,
            ],
            'registration' => [
                'program_category_id' => 1,
                'program_ids' => [3],
                'program_duration_months' => 6,
                'payer_id' => 1,
            ],
        ];
    }
}
