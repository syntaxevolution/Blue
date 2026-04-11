<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // players.mdn_id was added in the initial players migration but
        // without a FK because the mdns table didn't exist yet. Now that
        // it does, wire the constraint up.
        Schema::table('players', function (Blueprint $table) {
            $table->foreign('mdn_id')
                ->references('id')
                ->on('mdns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropForeign(['mdn_id']);
        });
    }
};
