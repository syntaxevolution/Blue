<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spy_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spy_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('target_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('target_base_tile_id')->constrained('tiles');
            $table->boolean('success')->default(false);
            $table->boolean('detected')->default(false);
            $table->unsignedBigInteger('rng_seed')->nullable();
            $table->string('rng_output', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['spy_player_id', 'target_player_id', 'created_at']);
            $table->index(['target_player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spy_attempts');
    }
};
