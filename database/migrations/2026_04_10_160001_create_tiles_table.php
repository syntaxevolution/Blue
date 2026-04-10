<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiles', function (Blueprint $table) {
            $table->id();
            $table->integer('x');
            $table->integer('y');
            $table->enum('type', ['base', 'oil_field', 'post', 'wasteland', 'landmark', 'auction', 'ruin']);
            $table->string('subtype', 64)->nullable();
            $table->string('flavor_text', 255)->nullable();
            $table->unsignedBigInteger('seed');
            $table->timestamps();

            $table->unique(['x', 'y']);
            $table->index(['x', 'y']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiles');
    }
};
