<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oil_field_id')->constrained('oil_fields')->cascadeOnDelete();
            $table->unsignedTinyInteger('grid_x');
            $table->unsignedTinyInteger('grid_y');
            $table->enum('quality', ['dry', 'trickle', 'standard', 'gusher'])->default('dry');
            $table->timestamp('drilled_at')->nullable();
            $table->timestamps();

            $table->unique(['oil_field_id', 'grid_x', 'grid_y']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_points');
    }
};
