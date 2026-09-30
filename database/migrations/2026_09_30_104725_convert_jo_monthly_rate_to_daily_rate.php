<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table) {
            $table->renameColumn('monthly_rate', 'daily_rate');
        });

        DB::table('accomplishment_reports')->whereNotNull('daily_rate')
            ->update(['daily_rate' => DB::raw('ROUND(daily_rate / 22, 2)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table) {
            $table->renameColumn('daily_rate', 'monthly_rate');
        });

        DB::table('accomplishment_reports')->whereNotNull('monthly_rate')
            ->update(['monthly_rate' => DB::raw('ROUND(monthly_rate * 22, 2)')]);
    }
};
