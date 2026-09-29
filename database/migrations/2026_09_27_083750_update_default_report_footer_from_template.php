<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_templates')
            ->where('footer_text', '3rd Floor, Executive Building Provincial, Capitol Complex, Cabidianan, Nabunturan, Davao de Oro')
            ->update(['footer_text' => "Provincial Information and Communications Technology Office, 3rd Floor, Executive Building, Provincial Capitol,\nCabidianan, Nabunturan, Davao de Oro\n✉ PICTO@davaodeoro.gov.ph"]);
    }

    public function down(): void
    {
        DB::table('report_templates')
            ->where('footer_text', "Provincial Information and Communications Technology Office, 3rd Floor, Executive Building, Provincial Capitol,\nCabidianan, Nabunturan, Davao de Oro\n✉ PICTO@davaodeoro.gov.ph")
            ->update(['footer_text' => '3rd Floor, Executive Building Provincial, Capitol Complex, Cabidianan, Nabunturan, Davao de Oro']);
    }
};
