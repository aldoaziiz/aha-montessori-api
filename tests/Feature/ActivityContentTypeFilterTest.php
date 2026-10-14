<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ActivityController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityContentTypeFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('activity_content_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('program_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('program_category_session_times', function (Blueprint $table) {
            $table->id();
            $table->string('session_name')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();
        });
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nickname')->nullable();
            $table->timestamps();
        });
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_content_type_id');
            $table->unsignedBigInteger('program_category_id')->nullable();
            $table->unsignedBigInteger('program_category_session_time_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->date('therapy_date')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_children', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('child_id');
            $table->timestamps();
        });
        Schema::create('activity_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id');
            $table->string('media_type');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('activity_content_types')->insert([
            ['id' => 1, 'name' => 'OBSERVATION', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'ACTIVITY', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('activities')->insert([
            [
                'id' => 1,
                'activity_content_type_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'activity_content_type_id' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Route::get('/test/activities', [ActivityController::class, 'index']);
    }

    public function test_activity_feed_can_be_filtered_by_content_type(): void
    {
        $this->actingAs(new User(['role' => 'admin']))
            ->getJson('/test/activities?activity_content_type_id=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 2)
            ->assertJsonPath('data.0.content_type.id', 2);

        $this->actingAs(new User(['role' => 'admin']))
            ->getJson('/test/activities')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_activity_feed_rejects_unknown_content_type_filter(): void
    {
        $this->actingAs(new User(['role' => 'admin']))
            ->getJson('/test/activities?activity_content_type_id=999')
            ->assertUnprocessable();
    }
}
