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

        // Case-insensitive uniqueness on name + tag. MariaDB rejects the
        // MySQL 8 functional-index syntax `((LOWER(name)))`, so we use a
        // generated-column + regular-index approach that works on both
        // MySQL 5.7+ and MariaDB 10.2+.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE mdns ADD COLUMN name_lower VARCHAR(50) AS (LOWER(name)) VIRTUAL');
            DB::statement('ALTER TABLE mdns ADD COLUMN tag_lower VARCHAR(8) AS (LOWER(tag)) VIRTUAL');
            DB::statement('CREATE UNIQUE INDEX mdns_name_lower_unique ON mdns (name_lower)');
            DB::statement('CREATE UNIQUE INDEX mdns_tag_lower_unique ON mdns (tag_lower)');
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX mdns_name_lower_unique ON mdns (LOWER(name))');
            DB::statement('CREATE UNIQUE INDEX mdns_tag_lower_unique ON mdns (LOWER(tag))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mdns');
    }
};
