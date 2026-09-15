<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('system_module_id')
                ->nullable()
                ->after('city_id')
                ->constrained('system_modules')
                ->nullOnDelete();
            $table->unique('system_module_id');
        });

        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_fr');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['city_id', 'name']);
        });

        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commune_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_fr');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['commune_id', 'name']);
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->foreignId('province_id')->nullable()->after('country_id')->constrained()->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
            $table->foreignId('neighborhood_id')->nullable()->after('commune_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('neighborhood_id');
            $table->dropConstrainedForeignId('commune_id');
            $table->dropConstrainedForeignId('province_id');
            $table->dropConstrainedForeignId('country_id');
        });

        Schema::dropIfExists('neighborhoods');
        Schema::dropIfExists('communes');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['system_module_id']);
            $table->dropConstrainedForeignId('system_module_id');
        });
    }
};
