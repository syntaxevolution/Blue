<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sabotage devices planted on individual drill points.
 *
 * One row per plant. `triggered_at` is null while the trap is armed.
 * On trigger, the row is updated in place (not deleted) so the attack
 * log and rng audit trail can reconstruct what happened later.
 *
 * Active-trap uniqueness (only one armed device per drill point) is
 * enforced at the application layer inside SabotageService::place()
 * under a lockForUpdate on the drill point row. Partial unique indexes
 * aren't portable on MariaDB, so we rely on the service gate plus the
 * surrounding transaction for correctness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_point_sabotages', function (Blueprint $table) {
            $table->id();

            // Composite locator: drill_point_id is authoritative for
            // active-trap lookups; oil_field_id is denormalised so the
            // map state builder can pull "all active traps on this field"
            // for the current viewer in a single indexed query.
            $table->foreignId('drill_point_id')->constrained('drill_points')->cascadeOnDelete();
            $table->foreignId('oil_field_id')->constrained('oil_fields')->cascadeOnDelete();

            // References items_catalog.key — gremlin_coil, siphon_charge,
            // or any future deployable. Not a hard FK because the items
            // catalog uses a string PK and we want cascade semantics
            // handled by the sabotage service rather than a DB trigger.
            $table->string('device_key', 64);

            $table->foreignId('placed_by_player_id')->constrained('players')->cascadeOnDelete();
            $table->timestamp('placed_at');

            // Null while armed. Set at trigger time.
            $table->timestamp('triggered_at')->nullable();
            $table->foreignId('triggered_by_player_id')->nullable()->constrained('players')->nullOnDelete();

            // Outcome enum mirrors the return type of SabotageService::resolveTrap.
            //   drill_broken              — rig wrecked, no siphon
            //   drill_broken_and_siphoned — rig wrecked AND oil siphoned to planter
            //   siphoned_only             — tier-1 drill protected but oil still siphoned
            //   detected                  — caught by a Tripwire Ward counter
            //   fizzled                   — 48h immunity or tier-1 rig_wrecker, no effect
            //
            // The distinction between siphoned_only and drill_broken_and_siphoned
            // matters for the attack log: AttackLogService derives `rig_broken`
            // from the stored outcome, so collapsing tier-1 siphons into the
            // _and_siphoned bucket would mislabel victims as having a wrecked
            // rig in their feed.
            $table->enum('outcome', [
                'drill_broken',
                'drill_broken_and_siphoned',
                'siphoned_only',
                'detected',
                'fizzled',
            ])->nullable();

            // Barrels actually moved on a siphon trigger. Zero for every
            // other outcome. Audit trail for disputes and leaderboards.
            $table->unsignedInteger('siphoned_barrels')->default(0);

            $table->timestamps();

            // Fast "is there an active trap on this cell?" lookup.
            $table->index(['drill_point_id', 'triggered_at'], 'dps_point_active_idx');

            // Fast "all active traps on this field for the scanner view"
            // lookup in MapStateBuilder.
            $table->index(['oil_field_id', 'triggered_at'], 'dps_field_active_idx');

            $table->index('placed_by_player_id');
            $table->index('triggered_by_player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_point_sabotages');
    }
};
