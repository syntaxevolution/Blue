<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a lifecycle status to player_items rows so tech items can be
 * marked broken (pending repair/abandon) without losing the ownership
 * record.
 *
 *   status    — 'active' | 'broken' (default 'active')
 *   broken_at — timestamp the item entered the broken state
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_items', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('quantity');
            $table->timestamp('broken_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('player_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'broken_at']);
        });
    }
};
