<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_programs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registration_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('program_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('price', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_programs');
    }
};
