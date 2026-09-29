<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table) {
            $table->index(['report_year', 'report_month', 'id'], 'reports_period_browse_index');
            $table->index(['user_id', 'updated_at'], 'reports_owner_recent_index');
        });
    }

    public function down(): void
    {
        Schema::table('accomplishment_reports', function (Blueprint $table) {
            $table->dropIndex('reports_period_browse_index');
            $table->dropIndex('reports_owner_recent_index');
        });
    }
};
