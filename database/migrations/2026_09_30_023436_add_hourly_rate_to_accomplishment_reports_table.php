<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable();
        });

        $rates = DB::table('accomplishment_entries')
            ->where('quantity_mode', 'time')
            ->select('accomplishment_report_id')
            ->selectRaw('MIN(hourly_rate) AS hourly_rate')
            ->groupBy('accomplishment_report_id')
            ->havingRaw('COUNT(DISTINCT hourly_rate) = 1 AND COUNT(hourly_rate) = COUNT(*)')
            ->orderBy('accomplishment_report_id');

        foreach ($rates->cursor() as $rate) {
            DB::table('accomplishment_reports')->where('id', $rate->accomplishment_report_id)
                ->update(['hourly_rate' => $rate->hourly_rate]);
        }
    }

    public function down(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }
};
