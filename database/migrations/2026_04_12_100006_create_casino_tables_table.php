<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_tables', function (Blueprint $table) {
            $table->id();
            $table->enum('game_type', ['roulette', 'blackjack', 'holdem']);
            $table->enum('currency', ['akzar_cash', 'oil_barrels']);
            $table->string('label', 64);
            $table->decimal('min_bet', 12, 2);
            $table->decimal('max_bet', 12, 2);
            $table->tinyInteger('seats')->unsigned()->default(6);
            $table->enum('status', ['waiting', 'active', 'paused', 'closed'])->default('waiting');
            $table->json('state_json')->nullable();
            $table->unsignedInteger('round_number')->default(0);
            $table->timestamp('round_started_at')->nullable();
            $table->timestamp('round_expires_at')->nullable();
            $table->timestamps();

            $table->index(['game_type', 'currency', 'status']);
        });

        Schema::create('casino_table_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casino_table_id')->constrained('casino_tables')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->tinyInteger('seat_number')->unsigned();
            $table->decimal('stack', 12, 2)->default(0);
            $table->enum('status', ['active', 'folded', 'sitting_out', 'left'])->default('active');
            // datetime avoids MariaDB's legacy TIMESTAMP NOT NULL default.
            $table->dateTime('joined_at');
            $table->dateTime('last_action_at')->nullable();
            $table->timestamps();

            $table->unique(['casino_table_id', 'seat_number']);
            $table->unique(['casino_table_id', 'player_id']);
            $table->index('player_id');
        });

        // Now add the FK from casino_rounds to casino_tables
        Schema::table('casino_rounds', function (Blueprint $table) {
            $table->foreign('casino_table_id')->references('id')->on('casino_tables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('casino_rounds', function (Blueprint $table) {
            $table->dropForeign(['casino_table_id']);
        });

        Schema::dropIfExists('casino_table_players');
        Schema::dropIfExists('casino_tables');
    }
};
