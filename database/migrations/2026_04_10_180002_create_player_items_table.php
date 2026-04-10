<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            // One row per (player, item). Stacking is done via quantity.
            $table->unique(['player_id', 'item_key']);
            $table->index('item_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_items');
    }
};
