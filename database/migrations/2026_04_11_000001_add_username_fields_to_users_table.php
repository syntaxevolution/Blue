<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a previous run may have added the column before
        // failing on the index step (MariaDB rejects MySQL 8 functional
        // index syntax). Re-running must not double-add.
        if (! Schema::hasColumn('users', 'name_claimed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('name_claimed_at')->nullable()->after('name');
            });
        }

        // Case-insensitive uniqueness on users.name. We store the name
        // as typed (case preserved) but reject any registration whose
        // lowercase form collides with an existing row.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Laravel reports both MySQL and MariaDB as 'mysql'. MySQL 8+
            // supports `(LOWER(name))` functional indexes directly, but
            // MariaDB does not — it needs a STORED/VIRTUAL generated column
            // with a regular index on top. The generated-column approach
            // works on both, so use it uniformly to keep the schema portable.
            if (! Schema::hasColumn('users', 'name_lower')) {
                DB::statement('ALTER TABLE users ADD COLUMN name_lower VARCHAR(255) AS (LOWER(name)) VIRTUAL');
            }
            if (! $this->mysqlIndexExists('users', 'users_name_lower_unique')) {
                DB::statement('CREATE UNIQUE INDEX users_name_lower_unique ON users (name_lower)');
            }
        } elseif ($driver === 'sqlite') {
            // SQLite (used by tests) supports expression indexes directly.
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_name_lower_unique ON users (LOWER(name))');
        } elseif ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_name_lower_unique ON users (LOWER(name))');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            if ($this->mysqlIndexExists('users', 'users_name_lower_unique')) {
                DB::statement('ALTER TABLE users DROP INDEX users_name_lower_unique');
            }
            if (Schema::hasColumn('users', 'name_lower')) {
                DB::statement('ALTER TABLE users DROP COLUMN name_lower');
            }
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS users_name_lower_unique');
        }

        if (Schema::hasColumn('users', 'name_claimed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('name_claimed_at');
            });
        }
    }

    private function mysqlIndexExists(string $table, string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index],
        );
        return $rows !== [];
    }
};
