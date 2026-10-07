<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, group: string}>
     */
    private array $permissions = [
        'kams.patrimoine.view' => ['name' => 'View office assets', 'group' => 'kams.patrimoine'],
        'kams.patrimoine.create' => ['name' => 'Create office assets', 'group' => 'kams.patrimoine'],
        'kams.patrimoine.update' => ['name' => 'Update office assets', 'group' => 'kams.patrimoine'],
        'kams.patrimoine.delete' => ['name' => 'Delete office assets', 'group' => 'kams.patrimoine'],
    ];

    public function up(): void
    {
        Schema::create('kams_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->default('other');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_value', 14, 2)->default(0);
            $table->date('acquired_on')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'office_id', 'status']);
            $table->index(['office_id', 'category']);
        });

        foreach ($this->permissions as $slug => $meta) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => $meta['name'],
                    'group' => $meta['group'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $viewId = DB::table('permissions')->where('slug', 'kams.patrimoine.view')->value('id');
        $createId = DB::table('permissions')->where('slug', 'kams.patrimoine.create')->value('id');
        $updateId = DB::table('permissions')->where('slug', 'kams.patrimoine.update')->value('id');
        $deleteId = DB::table('permissions')->where('slug', 'kams.patrimoine.delete')->value('id');
        $sourceViewId = DB::table('permissions')->where('slug', 'kams.clients.view')->value('id');
        $sourceCreateId = DB::table('permissions')->where('slug', 'kams.clients.create')->value('id');
        $sourceUpdateId = DB::table('permissions')->where('slug', 'kams.clients.update')->value('id');
        $sourceDeleteId = DB::table('permissions')->where('slug', 'kams.clients.delete')->value('id');

        $grant = static function (?int $sourceId, ?int $targetId): void {
            if (! $sourceId || ! $targetId) {
                return;
            }
            foreach (DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id') as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $targetId],
                    []
                );
            }
        };

        $grant($sourceViewId, $viewId);
        $grant($sourceCreateId, $createId);
        $grant($sourceUpdateId, $updateId);
        $grant($sourceDeleteId, $deleteId);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('slug', array_keys($this->permissions))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        Schema::dropIfExists('kams_assets');
    }
};
