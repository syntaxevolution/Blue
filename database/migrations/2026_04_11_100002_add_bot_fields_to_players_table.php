<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Difficulty tier — nullable: only bot rows have a value.
            $table->string('bot_difficulty', 16)->nullable()->after('broken_item_key');
            $table->timestamp('bot_last_tick_at')->nullable()->after('bot_difficulty');
            $table->integer('bot_moves_budget')->default(0)->after('bot_last_tick_at');

            $table->index('bot_difficulty');
            $table->index('bot_last_tick_at');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex(['bot_difficulty']);
            $table->dropIndex(['bot_last_tick_at']);
            $table->dropColumn(['bot_difficulty', 'bot_last_tick_at', 'bot_moves_budget']);
        });
    }
};
