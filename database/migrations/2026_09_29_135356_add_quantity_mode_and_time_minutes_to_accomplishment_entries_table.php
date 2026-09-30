<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accomplishment_entries', function (Blueprint $table) {
            $table->string('quantity_mode', 20)->default('custom');
            $table->unsignedInteger('time_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accomplishment_entries', function (Blueprint $table) {
            $table->dropColumn(['quantity_mode', 'time_minutes']);
        });
    }
};
