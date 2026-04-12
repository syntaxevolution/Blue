<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('expires_at');
            $table->decimal('fee_amount', 12, 2);
            $table->timestamps();

            $table->index(['player_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_sessions');
    }
};
