<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('child_guardians', function (Blueprint $table) {
            $table->unique(['child_id', 'guardian_id']);
        });
    }

    public function down()
    {
        Schema::table('child_guardians', function (Blueprint $table) {
            $table->dropUnique(['child_id', 'guardian_id']);
        });
    }
};
