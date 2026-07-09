<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedBigInteger('status_id')->default(1);

            $table->timestamps();

            // FK
            $table->foreign('status_id')
                ->references('id')
                ->on('statuses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
