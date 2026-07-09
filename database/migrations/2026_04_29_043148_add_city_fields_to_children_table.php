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
        Schema::table('children', function (Blueprint $table) {
            $table->unsignedBigInteger('birthplace_id')->nullable();
            $table->unsignedBigInteger('hometown_id')->nullable();

            $table->foreign('birthplace_id')
                ->references('id')->on('cities')
                ->onDelete('set null');

            $table->foreign('hometown_id')
                ->references('id')->on('cities')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            //
        });
    }
};
