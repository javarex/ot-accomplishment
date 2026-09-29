<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accomplishment_entries', function (Blueprint $table) {
            $table->string('quantity')->nullable()->after('quantity_minutes');
        });

        DB::table('accomplishment_entries')->select(['id', 'quantity_minutes'])->orderBy('id')->chunkById(500, function (Collection $entries): void {
            foreach ($entries as $entry) {
                $hours = intdiv((int) $entry->quantity_minutes, 60);
                $minutes = (int) $entry->quantity_minutes % 60;
                $quantity = $hours > 0 ? $hours.'h' : '';
                if ($minutes > 0) {
                    $quantity .= ($quantity === '' ? '' : ' ').$minutes.'m';
                }

                DB::table('accomplishment_entries')->where('id', $entry->id)->update(['quantity' => $quantity ?: '0m']);
            }
        });

        Schema::table('accomplishment_entries', function (Blueprint $table) {
            $table->unsignedInteger('quantity_minutes')->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Accomplishment quantities may contain free text and cannot be converted back to minutes safely.');
    }
};
