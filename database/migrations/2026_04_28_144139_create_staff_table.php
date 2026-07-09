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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title')->nullable(); // gelar
            $table->string('email')->unique();   // ✅ penting
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();

            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles')->onDelete('set null');
            $table->foreign('status_id')->references('id')->on('statuses')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
