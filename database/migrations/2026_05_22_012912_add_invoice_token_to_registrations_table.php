<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'registrations',
            function (
                Blueprint $table
            ) {

                $table->string(
                    'invoice_token'
                )
                    ->nullable()
                    ->unique()
                    ->after('payment_receipt');

            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'registrations',
            function (
                Blueprint $table
            ) {

                $table->dropColumn(
                    'invoice_token'
                );

            }
        );
    }
};
