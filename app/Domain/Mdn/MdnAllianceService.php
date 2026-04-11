<?php

namespace App\Domain\Mdn;

use App\Domain\Exceptions\MdnException;
use App\Events\MdnEvent;
use App\Models\Mdn;
use App\Models\MdnAlliance;
use App\Models\MdnMembership;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Declarative alliance management. Alliances are cosmetic by default —
 * config flag `mdn.formal_alliances_prevent_attacks` is false, so allies
 * can still raid each other. Exists so UI has something to hang off of
 * and Phase III "MDN warfare" bonuses can key off declared relationships.
 */
class MdnAllianceService
{
    public function declare(int $leaderPlayerId, int $otherMdnId): MdnAlliance
    {
        return DB::transaction(function () use ($leaderPlayerId, $otherMdnId) {
            /** @var Player $leader */
            $leader = Player::query()->findOrFail($leaderPlayerId);

            if ($leader->mdn_id === null) {
                throw MdnException::notAMember();
            }

            if ($leader->mdn_id === $otherMdnId) {
                throw MdnException::allianceWithSelf();
            }

            $membership = MdnMembership::query()
                ->where('mdn_id', $leader->mdn_id)
                ->where('player_id', $leader->id)
                ->first();
            if (! $membership || $membership->role !== MdnService::ROLE_LEADER) {
                throw MdnException::notLeader();
            }

            // Target MDN must exist.
            Mdn::query()->findOrFail($otherMdnId);

            [$a, $b] = [
                min((int) $leader->mdn_id, (int) $otherMdnId),
                max((int) $leader->mdn_id, (int) $otherMdnId),
            ];

            $existing = MdnAlliance::query()
                ->where('mdn_a_id', $a)
                ->where('mdn_b_id', $b)
                ->first();
            if ($existing) {
                throw MdnException::allianceExists();
            }

            return MdnAlliance::create([
                'mdn_a_id' => $a,
                'mdn_b_id' => $b,
                'declared_at' => now(),
            ]);
        });
    }

    public function revoke(int $leaderPlayerId, int $allianceId): void
    {
        DB::transaction(function () use ($leaderPlayerId, $allianceId) {
            /** @var Player $leader */
            $leader = Player::query()->findOrFail($leaderPlayerId);

            if ($leader->mdn_id === null) {
                throw MdnException::notAMember();
            }

            $membership = MdnMembership::query()
                ->where('mdn_id', $leader->mdn_id)
                ->where('player_id', $leader->id)
                ->first();
            if (! $membership || $membership->role !== MdnService::ROLE_LEADER) {
                throw MdnException::notLeader();
            }

            /** @var MdnAlliance|null $alliance */
            $alliance = MdnAlliance::query()->find($allianceId);
            if (! $alliance) {
                throw MdnException::allianceNotFound();
            }

            if ((int) $alliance->mdn_a_id !== (int) $leader->mdn_id
                && (int) $alliance->mdn_b_id !== (int) $leader->mdn_id
            ) {
                throw MdnException::allianceNotFound();
            }

            $alliance->delete();
        });

        MdnEvent::dispatch(
            (int) (Player::find($leaderPlayerId)?->user_id ?? 0),
            'alliance_revoked',
            'Alliance revoked',
            ['alliance_id' => $allianceId],
        );
    }
}
