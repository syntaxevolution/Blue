<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tile_id')->unique()->constrained('tiles')->cascadeOnDelete();
            $table->enum('post_type', ['strength', 'stealth', 'fort', 'tech', 'general', 'auction']);
            $table->string('name', 100);
            $table->timestamps();

            $table->index('post_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
