<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('child_guardians', function (Blueprint $table) {
            $table->dropForeign(['role_id']);

            $table->renameColumn('role_id', 'guardian_role_id');
        });

        Schema::table('child_guardians', function (Blueprint $table) {
            $table->foreign('guardian_role_id')
                ->references('id')
                ->on('guardian_roles')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('child_guardians', function (Blueprint $table) {
            //
        });
    }
};
