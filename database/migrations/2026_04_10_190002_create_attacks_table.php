<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacker_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('defender_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('defender_base_tile_id')->constrained('tiles');
            $table->foreignId('relied_on_spy_id')->nullable()->constrained('spy_attempts')->nullOnDelete();
            $table->enum('outcome', ['success', 'failure', 'bankrupt_target', 'decoy']);
            $table->decimal('cash_stolen', 12, 2)->default(0);
            $table->boolean('attacker_escape')->default(false);
            $table->unsignedBigInteger('rng_seed')->nullable();
            $table->string('rng_output', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attacker_player_id', 'defender_player_id', 'created_at']);
            $table->index(['defender_player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attacks');
    }
};
