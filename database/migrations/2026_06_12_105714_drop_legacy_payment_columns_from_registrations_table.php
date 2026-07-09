<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['payment_status_id']);

            $table->dropColumn([
                'payment_status_id',
                'payment_receipt',
                'invoice_token',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('payment_status_id')
                ->default(1)
                ->constrained();

            $table->string('payment_receipt')
                ->nullable();

            $table->uuid('invoice_token')
                ->nullable();
        });
    }
};
