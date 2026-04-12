<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casino_table_id')->constrained('casino_tables')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('message', 500);
            // datetime avoids MariaDB's legacy TIMESTAMP NOT NULL default.
            $table->dateTime('created_at');

            $table->index(['casino_table_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_chat_messages');
    }
};
