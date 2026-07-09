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
        Schema::create('billings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registration_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('invoice_number')->unique();

            $table->string('invoice_token')
                ->unique();

            $table->foreignId('payment_status_id')
                ->constrained();

            $table->decimal('total_amount', 15, 2)
                ->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};
