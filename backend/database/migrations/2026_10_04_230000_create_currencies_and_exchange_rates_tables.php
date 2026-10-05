<?php

use App\Support\CurrencyCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->string('code', 3)->unique();
                $table->string('name');
                $table->string('name_fr');
                $table->string('symbol', 16);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->string('base_code', 3);
                $table->string('quote_code', 3);
                $table->decimal('mid_rate', 18, 8);
                $table->timestamp('fetched_at')->nullable();
                $table->string('source', 40)->nullable();
                $table->timestamps();
                $table->unique(['base_code', 'quote_code']);
            });
        }

        if (DB::table('currencies')->exists()) {
            return;
        }

        $order = 0;
        foreach (['USD', 'CDF', 'EUR', 'TZS'] as $code) {
            $meta = CurrencyCatalog::find($code);
            DB::table('currencies')->insert([
                'code' => $code,
                'name' => $meta['name'],
                'name_fr' => $meta['name_fr'],
                'symbol' => $meta['symbol'],
                'is_default' => $code === 'USD',
                'is_active' => true,
                'sort_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
