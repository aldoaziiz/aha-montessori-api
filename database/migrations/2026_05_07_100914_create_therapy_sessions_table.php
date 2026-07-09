<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();

            // RELATIONS
            $table->foreignId('registration_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('therapist_id')
                ->constrained('staff')
                ->cascadeOnDelete();

            $table->foreignId('room_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // SCHEDULE
            $table->date('therapy_date');
            $table->time('start_time');
            $table->time('end_time');

            // NOTES
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapy_sessions');
    }
};
