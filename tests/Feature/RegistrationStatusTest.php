<?php

namespace Tests\Feature;

use App\Http\Controllers\API\RegistrationController;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number')->nullable();
            $table->string('registration_status')->default(Registration::STATUS_ACTIVE);
            $table->timestamps();
        });

        Registration::create(['registration_number' => 'REG-001']);

        Route::patch(
            '/test-registrations/{registration}/status',
            [RegistrationController::class, 'updateStatus']
        )->middleware(SubstituteBindings::class);
    }

    public function test_new_registrations_default_to_active(): void
    {
        $registration = Registration::firstOrFail();

        $this->assertSame(Registration::STATUS_ACTIVE, $registration->registration_status);
        $this->assertSame('Active', $registration->registration_status_label);
        $this->assertTrue($registration->canScheduleSessions());
    }

    public function test_admin_can_change_registration_status_manually(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        foreach ([Registration::STATUS_INACTIVE, Registration::STATUS_CLOSED, Registration::STATUS_ACTIVE] as $status) {
            $this->patchJson('/test-registrations/1/status', [
                'registration_status' => $status,
            ])->assertOk()
                ->assertJsonPath('data.registration_status', $status)
                ->assertJsonPath('data.registration_status_label', ucfirst($status));

            $this->assertDatabaseHas('registrations', [
                'id' => 1,
                'registration_status' => $status,
            ]);
        }
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->patchJson('/test-registrations/1/status', [
            'registration_status' => 'expired',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('registration_status');

        $this->assertDatabaseHas('registrations', [
            'id' => 1,
            'registration_status' => Registration::STATUS_ACTIVE,
        ]);
    }

    public function test_non_admin_cannot_change_registration_status(): void
    {
        $this->actingAs(new User(['role' => 'guardian']));

        $this->patchJson('/test-registrations/1/status', [
            'registration_status' => Registration::STATUS_CLOSED,
        ])->assertForbidden();

        $this->assertDatabaseHas('registrations', [
            'id' => 1,
            'registration_status' => Registration::STATUS_ACTIVE,
        ]);
    }
}
