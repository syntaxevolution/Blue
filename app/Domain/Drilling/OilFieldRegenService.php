<?php

namespace App\Domain\Drilling;

use App\Domain\Config\GameConfigResolver;
use App\Models\DrillPoint;
use App\Models\OilField;
use Illuminate\Support\Facades\DB;

/**
 * Lazy oil-field regeneration, mirroring MoveRegenService's lazy pattern.
 *
 * A field only starts its refill countdown once it is *fully depleted* —
 * i.e., DrillService has marked the last undrilled cell as drilled and
 * set `oil_fields.depleted_at = now()`. `drilling.field_refill_hours`
 * after that timestamp, the next read of the field (drill attempt or
 * map state build) will reset every DrillPoint's `drilled_at` back to
 * null and clear `depleted_at`, making the whole 5×5 grid usable again.
 *
 * Quality is *preserved* across regen: the same cells that were gushers
 * before are gushers after. This keeps individual fields with a stable
 * personality so players can remember which tiles are worth returning to.
 *
 * Zero scheduled jobs, no drift: if nobody visits a field for a week,
 * the refill still happens correctly on the next visit.
 */
class OilFieldRegenService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    /**
     * Bring an OilField up to date. Idempotent: calling twice in a row
     * is equivalent to calling once. If the field is not depleted, or
     * the refill window hasn't elapsed yet, this is a no-op.
     *
     * Callers should invoke this BEFORE reading drill points or taking
     * any action on the field, inside whatever transaction/lock they
     * already hold for the player.
     */
    public function reconcile(OilField $field): OilField
    {
        if ($field->depleted_at === null) {
            return $field;
        }

        $refillHours = (int) $this->config->get('drilling.field_refill_hours', 6);
        if ($refillHours <= 0) {
            return $field;
        }

        $refillAt = $field->depleted_at->copy()->addHours($refillHours);
        if ($refillAt->isFuture()) {
            return $field;
        }

        // Refill window has elapsed. Take a row lock on the field so two
        // concurrent reconciles don't double-apply the reset, then walk
        // every drill point and clear `drilled_at`. Quality is left as-is.
        return DB::transaction(function () use ($field) {
            /** @var OilField|null $locked */
            $locked = OilField::query()
                ->whereKey($field->id)
                ->lockForUpdate()
                ->first();

            // If another process regenerated between our read and the
            // lock acquisition, depleted_at is already cleared and we
            // have nothing to do.
            if ($locked === null || $locked->depleted_at === null) {
                return $locked ?? $field;
            }

            DrillPoint::query()
                ->where('oil_field_id', $locked->id)
                ->update(['drilled_at' => null]);

            $locked->update([
                'depleted_at' => null,
                'last_regen_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Call after DrillService marks a cell as drilled. If this drill was
     * the one that exhausted the field (no remaining undrilled cells),
     * stamp `depleted_at` so the refill countdown starts.
     *
     * Must be called inside the same transaction as the drill.
     */
    public function markIfDepleted(OilField $field): void
    {
        if ($field->depleted_at !== null) {
            return;
        }

        $remaining = DrillPoint::query()
            ->where('oil_field_id', $field->id)
            ->whereNull('drilled_at')
            ->count();

        if ($remaining === 0) {
            $field->update(['depleted_at' => now()]);
        }
    }

    /**
     * When the player-facing UI wants to show "field refills at HH:MM",
     * resolve the wall-clock time from the current depletion state.
     * Returns null if the field isn't depleted or regen is disabled.
     */
    public function refillAt(OilField $field): ?\Illuminate\Support\Carbon
    {
        if ($field->depleted_at === null) {
            return null;
        }

        $refillHours = (int) $this->config->get('drilling.field_refill_hours', 6);
        if ($refillHours <= 0) {
            return null;
        }

        return $field->depleted_at->copy()->addHours($refillHours);
    }
}
