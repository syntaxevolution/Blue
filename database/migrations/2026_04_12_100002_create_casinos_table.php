<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casinos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tile_id')->unique()->constrained('tiles')->cascadeOnDelete();
            $table->string('name', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casinos');
    }
};
