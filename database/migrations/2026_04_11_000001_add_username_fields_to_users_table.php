<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('name_claimed_at')->nullable()->after('name');
        });

        // Case-insensitive uniqueness on users.name. We store the name
        // as typed (case preserved) but reject any registration whose
        // lowercase form collides with an existing row.
        //
        // MySQL 8 supports functional indexes natively.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('CREATE UNIQUE INDEX users_name_lower_unique ON users ((LOWER(name)))');
        } elseif ($driver === 'sqlite') {
            // SQLite (used by tests) supports expression indexes directly.
            DB::statement('CREATE UNIQUE INDEX users_name_lower_unique ON users (LOWER(name))');
        } elseif ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX users_name_lower_unique ON users (LOWER(name))');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users DROP INDEX users_name_lower_unique');
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS users_name_lower_unique');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name_claimed_at');
        });
    }
};
