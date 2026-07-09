<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // hapus foreign lama
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');

            // tambah kolom baru
            $table->unsignedBigInteger('staff_role_id')->nullable();

            $table->foreign('staff_role_id')
                ->references('id')
                ->on('staff_roles')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['staff_role_id']);
            $table->dropColumn('staff_role_id');

            $table->unsignedBigInteger('role_id')->nullable();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->onDelete('set null');
        });
    }
};
