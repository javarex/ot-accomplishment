<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        $catalog = [
            'view_ot_computation' => 'View OT computation and hourly rate',
            'manage_access' => 'Manage users, roles, and permissions',
        ];
        foreach ($catalog as $key => $label) {
            DB::table('permissions')->insert(['key' => $key, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
        }
        $staffId = DB::table('roles')->insertGetId(['name' => 'Staff', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($staffId): void {
            DB::table('role_user')->insert($users->map(fn ($user): array => ['role_id' => $staffId, 'user_id' => $user->id])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
