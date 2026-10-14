<?php

namespace Tests\Feature;

use App\Http\Controllers\API\BillingController;
use App\Http\Middleware\CheckDailySession;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingDiscountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutMiddleware(CheckDailySession::class);

        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number');
            $table->unsignedBigInteger('child_id')->nullable();
            $table->timestamps();
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('session_count')->default(0);
            $table->timestamps();
        });
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('program_id');
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('learning_period_months')->nullable();
            $table->timestamps();
        });
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->string('invoice_number')->unique();
            $table->string('invoice_token')->unique();
            $table->unsignedBigInteger('payment_status_id');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id');
            $table->unsignedBigInteger('program_id')->nullable();
            $table->string('description');
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Route::get(
            '/test/registrations/{id}/billing-preview',
            [BillingController::class, 'previewGeneration']
        );
        Route::post(
            '/test/registrations/{id}/generate-billing',
            [BillingController::class, 'generateBilling']
        );

        DB::table('children')->insert(['id' => 1, 'name' => 'Test Child']);
        DB::table('registrations')->insert([
            'id' => 1,
            'registration_number' => 'REG-001',
            'child_id' => 1,
        ]);
        DB::table('programs')->insert([
            ['id' => 1, 'name' => 'Toddler Program', 'session_count' => 8],
            ['id' => 2, 'name' => 'Registration Fee', 'session_count' => 0],
        ]);
        DB::table('registration_programs')->insert([
            [
                'id' => 11,
                'registration_id' => 1,
                'program_id' => 1,
                'price' => 1000,
                'learning_period_months' => 6,
            ],
            [
                'id' => 12,
                'registration_id' => 1,
                'program_id' => 2,
                'price' => 500,
                'learning_period_months' => null,
            ],
        ]);
    }

    public function test_preview_returns_registration_items_without_creating_billing(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->getJson('/test/registrations/1/billing-preview')
            ->assertOk()
            ->assertJsonPath('data.child_name', 'Test Child')
            ->assertJsonPath('data.items.0.registration_program_id', 11)
            ->assertJsonPath('data.items.0.quantity', 6)
            ->assertJsonPath('data.items.0.subtotal', 6000)
            ->assertJsonPath('data.items.1.quantity', 1);

        $this->assertDatabaseCount('billings', 0);
    }

    public function test_generated_billing_snapshots_percentage_and_amount_per_item(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->postJson('/test/registrations/1/generate-billing', [
            'discounts' => [
                ['registration_program_id' => 11, 'discount_percentage' => 10],
                ['registration_program_id' => 12, 'discount_percentage' => 50],
            ],
        ])->assertOk()
            ->assertJsonPath('data.total_amount', 5650)
            ->assertJsonPath('data.items.0.subtotal', 6000)
            ->assertJsonPath('data.items.0.discount_percentage', '10.00')
            ->assertJsonPath('data.items.0.discount_amount', '600.00')
            ->assertJsonPath('data.items.1.discount_amount', '250.00');

        $this->assertDatabaseHas('billing_items', [
            'program_id' => 1,
            'subtotal' => 6000,
            'discount_percentage' => 10,
            'discount_amount' => 600,
        ]);
        $this->assertDatabaseHas('billings', ['total_amount' => 5650]);
    }

    public function test_missing_discount_values_preserve_the_original_price(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->postJson('/test/registrations/1/generate-billing', [])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 6500)
            ->assertJsonPath('data.items.0.discount_percentage', '0.00')
            ->assertJsonPath('data.items.0.discount_amount', '0.00');
    }

    public function test_discount_percentages_must_be_between_zero_and_one_hundred(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->postJson('/test/registrations/1/generate-billing', [
            'discounts' => [
                ['registration_program_id' => 11, 'discount_percentage' => 100.01],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('discounts.0.discount_percentage');

        $this->assertDatabaseCount('billings', 0);
    }

    public function test_discount_cannot_be_applied_to_another_registrations_item(): void
    {
        $this->actingAs(new User(['role' => 'admin']));

        $this->postJson('/test/registrations/1/generate-billing', [
            'discounts' => [
                ['registration_program_id' => 999, 'discount_percentage' => 10],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('discounts');

        $this->assertDatabaseCount('billings', 0);
    }

    public function test_non_admin_cannot_preview_or_generate_a_billing(): void
    {
        $this->actingAs(new User(['role' => 'teacher']));

        $this->getJson('/test/registrations/1/billing-preview')->assertForbidden();
        $this->postJson('/test/registrations/1/generate-billing', [])->assertForbidden();
        $this->assertDatabaseCount('billings', 0);
    }
}
