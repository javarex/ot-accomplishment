<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table): void {
            $table->string('quantity_mode', 20)->default('custom');
        });

        DB::table('accomplishment_reports')->select('id')->orderBy('id')->chunkById(500, function ($reports): void {
            foreach ($reports as $report) {
                $entries = DB::table('accomplishment_entries')->where('accomplishment_report_id', $report->id);
                if ($entries->exists() && ! (clone $entries)->where('quantity_mode', '!=', 'time')->exists()) {
                    DB::table('accomplishment_reports')->where('id', $report->id)->update(['quantity_mode' => 'time']);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table): void {
            $table->dropColumn('quantity_mode');
        });
    }
};
