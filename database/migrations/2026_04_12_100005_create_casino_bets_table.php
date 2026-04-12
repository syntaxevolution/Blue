<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casino_round_id')->constrained('casino_rounds')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('bet_type', 32);
            $table->decimal('amount', 12, 2);
            $table->decimal('payout', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->storedAs('payout - amount');
            $table->timestamps();

            $table->index(['player_id', 'created_at']);
            $table->index('casino_round_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_bets');
    }
};
