<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('base_tile_id')->constrained('tiles');
            $table->foreignId('current_tile_id')->constrained('tiles');

            // Currencies
            $table->decimal('akzar_cash', 12, 2)->default(0);
            $table->unsignedInteger('oil_barrels')->default(0);
            $table->unsignedInteger('intel')->default(0);

            // Moves / energy
            $table->integer('moves_current')->default(0);
            $table->timestamp('moves_updated_at')->nullable();
            $table->unsignedInteger('sponsor_moves_used_this_cycle')->default(0);

            // Stats — starting defaults mirror stats.starting config
            $table->unsignedTinyInteger('strength')->default(1);
            $table->unsignedTinyInteger('fortification')->default(0);
            $table->unsignedTinyInteger('stealth')->default(0);
            $table->unsignedTinyInteger('security')->default(0);
            $table->unsignedTinyInteger('drill_tier')->default(1);

            // MDN membership — FK added in a later migration once mdns exists.
            $table->unsignedBigInteger('mdn_id')->nullable();
            $table->timestamp('mdn_joined_at')->nullable();
            $table->timestamp('mdn_left_at')->nullable();

            // Lifecycle timestamps
            $table->timestamp('immunity_expires_at')->nullable();
            $table->timestamp('last_bankruptcy_at')->nullable();

            $table->timestamps();

            $table->index('mdn_id');
            $table->index('base_tile_id');
            $table->index('current_tile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
