<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tiles MODIFY COLUMN type ENUM('base','oil_field','post','wasteland','landmark','auction','ruin','casino') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tiles MODIFY COLUMN type ENUM('base','oil_field','post','wasteland','landmark','auction','ruin') NOT NULL");
    }
};
