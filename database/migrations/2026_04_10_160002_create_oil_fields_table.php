<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oil_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tile_id')->unique()->constrained('tiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('drill_grid_rows')->default(5);
            $table->unsignedTinyInteger('drill_grid_cols')->default(5);
            $table->timestamp('last_regen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oil_fields');
    }
};
