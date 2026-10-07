<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('slug', 'like', 'kams.campaigns.%')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('kams_campaigns');
    }

    public function down(): void
    {
        if (! Schema::hasTable('kams_campaigns')) {
            Schema::create('kams_campaigns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('channel', 64)->default('store');
                $table->enum('status', ['planned', 'active', 'completed', 'paused'])->default('planned');
                $table->decimal('budget', 14, 2)->default(0);
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        foreach ([
            ['slug' => 'kams.campaigns.view', 'name' => 'View kams campaigns'],
            ['slug' => 'kams.campaigns.create', 'name' => 'Create kams campaigns'],
            ['slug' => 'kams.campaigns.update', 'name' => 'Update kams campaigns'],
            ['slug' => 'kams.campaigns.delete', 'name' => 'Delete kams campaigns'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'group' => 'kams.campaigns',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
