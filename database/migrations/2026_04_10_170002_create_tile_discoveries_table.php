<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tile_discoveries', function (Blueprint $table) {
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('tile_id')->constrained('tiles')->cascadeOnDelete();
            $table->timestamp('discovered_at')->useCurrent();

            $table->primary(['player_id', 'tile_id']);
            $table->index(['player_id', 'discovered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tile_discoveries');
    }
};
