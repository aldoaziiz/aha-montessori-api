<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {

            $table->foreignId('clinic_id')
                ->nullable()
                ->after('payer_id')
                ->constrained()
                ->nullOnDelete();

        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {

            $table->dropForeign(['clinic_id']);

            $table->dropColumn('clinic_id');

        });
    }
};
