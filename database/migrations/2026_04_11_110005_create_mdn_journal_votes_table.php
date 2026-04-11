<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdn_journal_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('mdn_journal_entries')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('vote', 16); // helpful | unhelpful
            $table->timestamps();

            $table->unique(['entry_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdn_journal_votes');
    }
};
