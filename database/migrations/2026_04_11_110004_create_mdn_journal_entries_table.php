<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdn_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mdn_id')->constrained('mdns')->cascadeOnDelete();
            $table->foreignId('author_player_id')->constrained('players');
            $table->foreignId('tile_id')->nullable()->constrained('tiles')->nullOnDelete();
            $table->text('body');
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
            $table->timestamps();

            $table->index(['mdn_id', 'helpful_count']);
            $table->index(['mdn_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdn_journal_entries');
    }
};
