<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items_catalog', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->enum('post_type', ['strength', 'stealth', 'fort', 'tech', 'general', 'auction']);
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Prices — any non-zero column is charged
            $table->unsignedInteger('price_barrels')->default(0);
            $table->decimal('price_cash', 10, 2)->default(0);
            $table->unsignedInteger('price_intel')->default(0);

            // Effects applied on purchase. Recognized keys:
            //   stat_add: {strength: int, fortification: int, stealth: int, security: int}
            //   set_drill_tier: int
            $table->json('effects')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index('post_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items_catalog');
    }
};
