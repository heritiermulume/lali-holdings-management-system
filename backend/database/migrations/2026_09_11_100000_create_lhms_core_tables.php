<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_fr');
            $table->string('iso_code', 8)->unique();
            $table->string('phone_code', 8)->nullable();
            $table->string('currency_code', 8)->default('USD');
            $table->string('currency_symbol', 8)->default('$');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_fr');
            $table->string('code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('province_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('name_fr');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 32)->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency_code', 8)->default('USD');
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->enum('type', ['headquarters', 'office', 'agency', 'branch'])->default('office');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('level')->default(50);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('group', 64);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['role_id', 'user_id']);
        });

        Schema::create('user_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('scope_type', ['group', 'country', 'city', 'company', 'office', 'module']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'scope_type', 'scope_id']);
        });

        Schema::create('system_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('name_fr');
            $table->text('description')->nullable();
            $table->text('description_fr')->nullable();
            $table->string('icon')->nullable();
            $table->string('version', 32)->default('0.1.0');
            $table->enum('status', ['active', 'in_development', 'coming_soon', 'inactive'])->default('coming_soon');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_core')->default(false);
            $table->timestamps();
        });

        Schema::create('company_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('system_module_id')->constrained('system_modules')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'system_module_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64)->default('info');
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('channel', 32)->default('in_app');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64)->default('general');
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type', 32)->default('string');
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('primary_company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('primary_office_id')->references('id')->on('offices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['primary_company_id']);
            $table->dropForeign(['primary_office_id']);
        });

        Schema::dropIfExists('tasks');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('company_module');
        Schema::dropIfExists('system_modules');
        Schema::dropIfExists('user_scopes');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('countries');
    }
};
