<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('label');
            $t->string('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('label');
            $t->string('group');
            $t->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->after('password')->constrained()->restrictOnDelete();
            $t->boolean('is_active')->default(true)->after('role_id');
            $t->boolean('must_change_password')->default(false)->after('is_active');
            $t->timestamp('last_login_at')->nullable();
            $t->unsignedSmallInteger('failed_login_count')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->json('preferences')->nullable();
        });

        Schema::create('login_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email');
            $t->string('event', 20); // success, failed, locked, logout
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn(['is_active', 'must_change_password', 'last_login_at', 'failed_login_count', 'locked_until', 'preferences']);
        });
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
