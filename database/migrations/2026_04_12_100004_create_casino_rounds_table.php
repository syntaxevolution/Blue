<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('casino_table_id')->nullable()->index();
            $table->enum('game_type', ['slots', 'roulette', 'blackjack', 'holdem']);
            $table->enum('currency', ['akzar_cash', 'oil_barrels']);
            $table->unsignedInteger('round_number')->default(0);
            $table->json('state_snapshot')->nullable();
            $table->string('rng_seed', 255);
            $table->json('result_summary')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['casino_table_id', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_rounds');
    }
};
