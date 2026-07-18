<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('room_id')
                ->nullable()
                ->after('program_id')
                ->constrained('rooms')
                ->nullOnDelete();
        });

        Schema::table('children', function (Blueprint $table) {
            $table->foreignId('room_id')
                ->nullable()
                ->after('school_education_id')
                ->constrained('rooms')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });

        Schema::table('children', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }
};
