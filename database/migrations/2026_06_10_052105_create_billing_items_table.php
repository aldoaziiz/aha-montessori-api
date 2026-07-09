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
        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('billing_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('program_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('description');

            $table->decimal('price', 15, 2);

            $table->unsignedInteger('quantity')
                ->default(1);

            $table->decimal('subtotal', 15, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_items');
    }
};
