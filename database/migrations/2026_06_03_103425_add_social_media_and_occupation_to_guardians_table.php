<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table) {

            $table->string('occupation')
                ->nullable()
                ->after('phone');

            $table->string('social_media')
                ->nullable()
                ->after('occupation');

        });
    }

    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table) {

            $table->dropColumn([
                'occupation',
                'social_media',
            ]);

        });
    }
};
