<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdn_alliances', function (Blueprint $table) {
            $table->id();
            // Always stored with mdn_a_id < mdn_b_id so the unique constraint
            // catches duplicates regardless of declaration direction.
            $table->foreignId('mdn_a_id')->constrained('mdns')->cascadeOnDelete();
            $table->foreignId('mdn_b_id')->constrained('mdns')->cascadeOnDelete();
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->unique(['mdn_a_id', 'mdn_b_id']);
            $table->index('mdn_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdn_alliances');
    }
};
