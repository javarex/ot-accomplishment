<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('position');
            $table->string('signatory_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'signatory_type', 'is_active']);
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('province')->default('Province of Davao de Oro');
            $table->string('office_name')->default('Provincial Information and Communications Technology Office');
            $table->text('office_address');
            $table->string('left_logo_path')->nullable();
            $table->string('right_logo_path')->nullable();
            $table->string('report_title')->default('OVERTIME ACCOMPLISHMENT REPORT');
            $table->text('certification_statement');
            $table->text('footer_text');
            $table->timestamps();
        });

        Schema::create('accomplishment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('report_month');
            $table->unsignedSmallInteger('report_year');
            $table->foreignId('prepared_by_id')->nullable()->constrained('signatories')->nullOnDelete();
            $table->foreignId('certified_by_id')->nullable()->constrained('signatories')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('signatories')->nullOnDelete();
            $table->string('prepared_name')->nullable();
            $table->string('prepared_position')->nullable();
            $table->string('certified_name')->nullable();
            $table->string('certified_position')->nullable();
            $table->string('approved_name')->nullable();
            $table->string('approved_position')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'report_year', 'report_month']);
        });

        Schema::create('dtr_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accomplishment_report_id')->constrained()->cascadeOnDelete();
            $table->string('employee_name');
            $table->string('employee_id')->nullable();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_hash', 64);
            $table->string('import_status')->default('review');
            $table->json('parsed_data')->nullable();
            $table->timestamps();
            $table->unique(['accomplishment_report_id', 'file_hash']);
        });

        Schema::create('dtr_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dtr_import_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('am_in', 5)->nullable();
            $table->string('am_out', 5)->nullable();
            $table->string('pm_in', 5)->nullable();
            $table->string('pm_out', 5)->nullable();
            $table->unsignedInteger('overtime_minutes')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('selected_for_import')->default(false);
            $table->timestamps();
            $table->unique(['dtr_import_id', 'work_date']);
        });

        Schema::create('accomplishment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accomplishment_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dtr_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('accomplishment_date');
            $table->unsignedInteger('quantity_minutes');
            $table->text('task_accomplished')->nullable();
            $table->text('original_task_accomplished')->nullable();
            $table->boolean('ai_enhanced')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['accomplishment_report_id', 'accomplishment_date'], 'report_entry_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accomplishment_entries');
        Schema::dropIfExists('dtr_entries');
        Schema::dropIfExists('dtr_imports');
        Schema::dropIfExists('accomplishment_reports');
        Schema::dropIfExists('report_templates');
        Schema::dropIfExists('signatories');
    }
};
