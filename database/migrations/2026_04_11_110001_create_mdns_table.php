<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('tag', 8);
            $table->foreignId('leader_player_id')->constrained('players');
            $table->unsignedSmallInteger('member_count')->default(0);
            $table->string('motto', 200)->nullable();
            $table->timestamps();

            $table->index('member_count');
        });

        // Case-insensitive uniqueness on name + tag, mirroring the
        // users.name_lower_unique pattern in 2026_04_11_000001.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('CREATE UNIQUE INDEX mdns_name_lower_unique ON mdns ((LOWER(name)))');
            DB::statement('CREATE UNIQUE INDEX mdns_tag_lower_unique ON mdns ((LOWER(tag)))');
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX mdns_name_lower_unique ON mdns (LOWER(name))');
            DB::statement('CREATE UNIQUE INDEX mdns_tag_lower_unique ON mdns (LOWER(tag))');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE mdns DROP INDEX mdns_name_lower_unique');
            DB::statement('ALTER TABLE mdns DROP INDEX mdns_tag_lower_unique');
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS mdns_name_lower_unique');
            DB::statement('DROP INDEX IF EXISTS mdns_tag_lower_unique');
        }

        Schema::dropIfExists('mdns');
    }
};
