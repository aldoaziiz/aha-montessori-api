<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_status_id')->default(1);

            $table->foreign('payment_status_id')
                ->references('id')
                ->on('payment_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['payment_status_id']);
            $table->dropColumn('payment_status_id');
        });
    }
};
