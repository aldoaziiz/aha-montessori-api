<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDailySession;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicRegistrationSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutMiddleware(CheckDailySession::class);
    }

    public function test_public_status_defaults_to_closed_when_settings_are_not_configured(): void
    {
        $this->getJson('/api/public-registration/status')
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_closed_public_registration_rejects_submission_before_creating_records(): void
    {
        $this->createSettingsTable();

        $this->postJson('/api/public-registrations', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Public registration is currently closed.');

        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_admin_can_activate_and_deactivate_public_registration(): void
    {
        $this->createSettingsTable();
        $this->actingAs(new User(['role' => 'admin']));

        $this->putJson('/api/settings/public-registration', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.is_active', true);

        $this->getJson('/api/public-registration/status')
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $this->putJson('/api/settings/public-registration', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('app_settings', [
            'setting_key' => 'public_registration_enabled',
            'setting_value' => '0',
        ]);
    }

    public function test_non_admin_cannot_change_public_registration_status(): void
    {
        $this->createSettingsTable();
        $this->actingAs(new User(['role' => 'teacher']));

        $this->putJson('/api/settings/public-registration', ['is_active' => true])
            ->assertForbidden();

        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_admin_cannot_change_status_until_settings_table_is_configured(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->putJson('/api/settings/public-registration', ['is_active' => true])
            ->assertStatus(503);
    }

    private function createSettingsTable(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->text('setting_value');
            $table->string('setting_group', 100)->default('general');
            $table->string('value_type', 30)->default('string');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }
}
