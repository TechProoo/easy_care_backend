<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->timestamps(6);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('username', 64)->nullable();
            $table->string('staff_code', 64)->nullable()->unique();
            $table->string('first_name', 64)->nullable();
            $table->string('last_name', 64)->nullable();
            $table->string('middle_name', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::table('users')->select(['id', 'name'])->orderBy('id')->chunkById(100, function (Collection $users): void {
            foreach ($users as $user) {
                $parts = preg_split('/\s+/u', trim($user->name), 2);

                DB::table('users')->where('id', $user->id)->update([
                    'username' => 'legacy_'.$user->id,
                    'first_name' => mb_substr($parts[0] ?: 'Staff', 0, 64),
                    'last_name' => mb_substr($parts[1] ?? '', 0, 64),
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 64)->nullable(false)->unique()->change();
            $table->string('first_name', 64)->nullable(false)->change();
            $table->string('last_name', 64)->nullable(false)->change();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_positive_version CHECK (version > 0)');
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->timestamps(6);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->timestamps(6);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
            $table->timestamps(6);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Assign email addresses to staff before rolling back to the starter user schema.');
        }

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users DROP CHECK users_positive_version');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
            $table->dropForeign(['department_id']);
            $table->dropUnique(['username']);
            $table->dropUnique(['staff_code']);
            $table->dropColumn([
                'username', 'staff_code', 'first_name', 'last_name', 'middle_name',
                'is_active', 'last_login_at', 'version', 'department_id',
            ]);
        });

        Schema::dropIfExists('departments');
    }
};
