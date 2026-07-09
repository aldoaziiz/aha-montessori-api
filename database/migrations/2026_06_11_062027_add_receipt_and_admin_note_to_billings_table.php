<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {

            $table->string('payment_receipt')
                ->nullable()
                ->after('total_amount');

            $table->text('admin_note')
                ->nullable()
                ->after('payment_receipt');

        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {

            $table->dropColumn([
                'payment_receipt',
                'admin_note',
            ]);

        });
    }
};
