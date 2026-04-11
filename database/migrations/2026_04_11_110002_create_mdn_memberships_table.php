<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdn_memberships', function (Blueprint $table) {
            $table->foreignId('mdn_id')->constrained('mdns')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // leader | officer | member
            $table->timestamp('joined_at');

            $table->primary(['mdn_id', 'player_id']);
            // A player can only be in one MDN at a time.
            $table->unique('player_id');
            $table->index(['mdn_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdn_memberships');
    }
};
