<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique();
        });

        DB::table('users')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                $localPart = Str::before(Str::lower($user->email), '@');
                $base = preg_split('/[.\s_+\-]+/', $localPart, flags: PREG_SPLIT_NO_EMPTY)[0] ?? 'user';
                $base = preg_replace('/[^a-z0-9]/', '', Str::ascii($base)) ?: 'user';
                $base = substr($base, 0, 200);
                $username = $base;
                $suffix = 2;
                while (DB::table('users')->where('username', $username)->exists()) {
                    $username = $base.$suffix++;
                }
                DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
