<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('hostility_log_last_viewed_at')->nullable()->after('broken_item_key');
        });

        // Backfill existing players to "now" so the fresh deploy
        // doesn't suddenly show a huge unread count for historical
        // raids they already knew about. Brand-new post-deploy players
        // stay NULL, which unreadCount() treats as "count everything"
        // — harmless for a fresh account with zero incoming rows.
        DB::table('players')->update([
            'hostility_log_last_viewed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('hostility_log_last_viewed_at');
        });
    }
};
