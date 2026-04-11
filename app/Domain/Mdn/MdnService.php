<?php

namespace App\Domain\Mdn;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotAttackException;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\MdnException;
use App\Events\MdnEvent;
use App\Models\Mdn;
use App\Models\MdnMembership;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Membership lifecycle service for Mutual Defense Networks.
 *
 * Handles create / join / leave / kick / promote / disband plus the
 * same-MDN blocking + 24h hop cooldown enforcement that AttackService
 * and SpyService delegate to. All mutations run inside a DB transaction
 * with row-level locks; MDN events are fired after commit so a broadcast
 * failure can never roll back a membership change.
 *
 * Config-driven constraints:
 *   - mdn.max_members       (default 50)
 *   - mdn.join_leave_cooldown_hours (default 24)
 *   - mdn.name_max_length / tag_max_length / motto_max_length
 *   - mdn.creation_cost_cash
 *   - mdn.same_mdn_attacks_blocked (flips combat enforcement on/off)
 */
class MdnService
{
    public const ROLE_LEADER = 'leader';
    public const ROLE_OFFICER = 'officer';
    public const ROLE_MEMBER = 'member';

    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    public function create(int $leaderPlayerId, string $name, string $tag, ?string $motto = null): Mdn
    {
        $name = trim($name);
        $tag = trim($tag);
        $motto = $motto !== null ? trim($motto) : null;

        $nameMax = (int) $this->config->get('mdn.name_max_length', 50);
        $tagMax = (int) $this->config->get('mdn.tag_max_length', 6);
        $mottoMax = (int) $this->config->get('mdn.motto_max_length', 200);
        $cost = (float) $this->config->get('mdn.creation_cost_cash', 0);
        $memberCap = (int) $this->config->get('mdn.max_members', 50);

        if ($name === '' || mb_strlen($name) > $nameMax) {
            throw MdnException::nameInvalid("length must be 1..{$nameMax}");
        }
        if (! preg_match('/^[A-Za-z0-9 \-_\'\.]+$/', $name)) {
            throw MdnException::nameInvalid('letters, digits, spaces, hyphens, underscores, apostrophes and dots only');
        }
        if ($tag === '' || mb_strlen($tag) > $tagMax) {
            throw MdnException::tagInvalid("length must be 1..{$tagMax}");
        }
        if (! preg_match('/^[A-Za-z0-9]+$/', $tag)) {
            throw MdnException::tagInvalid('alphanumeric only');
        }
        if ($motto !== null && mb_strlen($motto) > $mottoMax) {
            throw MdnException::nameInvalid("motto must be <= {$mottoMax} chars");
        }

        return DB::transaction(function () use ($leaderPlayerId, $name, $tag, $motto, $cost, $memberCap) {
            /** @var Player $leader */
            $leader = Player::query()->lockForUpdate()->findOrFail($leaderPlayerId);

            if ($leader->mdn_id !== null) {
                throw MdnException::alreadyInMdn();
            }

            if ((float) $leader->akzar_cash < $cost) {
                throw MdnException::insufficientCash($cost, (float) $leader->akzar_cash);
            }

            // Case-insensitive uniqueness is enforced by the functional
            // indexes on mdns; the pre-check gives a nicer error message.
            if (Mdn::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                throw MdnException::nameTaken($name);
            }
            if (Mdn::query()->whereRaw('LOWER(tag) = ?', [mb_strtolower($tag)])->exists()) {
                throw MdnException::tagTaken($tag);
            }

            $mdn = Mdn::create([
                'name' => $name,
                'tag' => $tag,
                'leader_player_id' => $leader->id,
                'member_count' => 1,
                'motto' => $motto,
            ]);

            MdnMembership::create([
                'mdn_id' => $mdn->id,
                'player_id' => $leader->id,
                'role' => self::ROLE_LEADER,
                'joined_at' => now(),
            ]);

            $leader->update([
                'akzar_cash' => (float) $leader->akzar_cash - $cost,
                'mdn_id' => $mdn->id,
                'mdn_joined_at' => now(),
                'mdn_left_at' => null,
            ]);

            return $mdn;
        });
    }

    public function join(int $playerId, int $mdnId): void
    {
        $memberCap = (int) $this->config->get('mdn.max_members', 50);

        DB::transaction(function () use ($playerId, $mdnId, $memberCap) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);
            /** @var Mdn $mdn */
            $mdn = Mdn::query()->lockForUpdate()->findOrFail($mdnId);

            if ($player->mdn_id !== null) {
                throw MdnException::alreadyInMdn();
            }
            if ($mdn->member_count >= $memberCap) {
                throw MdnException::atCapacity($memberCap);
            }

            MdnMembership::create([
                'mdn_id' => $mdn->id,
                'player_id' => $player->id,
                'role' => self::ROLE_MEMBER,
                'joined_at' => now(),
            ]);

            $player->update([
                'mdn_id' => $mdn->id,
                'mdn_joined_at' => now(),
                'mdn_left_at' => null,
            ]);

            $mdn->increment('member_count');
        });

        MdnEvent::dispatch(
            $this->userIdFor($playerId),
            'member_joined',
            'Joined MDN',
            ['mdn_id' => $mdnId],
        );
    }

    /**
     * Leave the MDN the player currently belongs to. If they're the
     * leader and they're the last member, the MDN is disbanded.
     * If they're the leader but others remain, leadership automatically
     * transfers to the next-oldest officer, or next-oldest member if no
     * officers exist.
     */
    public function leave(int $playerId): void
    {
        $mdnId = null;

        DB::transaction(function () use ($playerId, &$mdnId) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            if ($player->mdn_id === null) {
                throw MdnException::notAMember();
            }

            /** @var Mdn $mdn */
            $mdn = Mdn::query()->lockForUpdate()->findOrFail($player->mdn_id);
            $mdnId = $mdn->id;

            $this->removeMember($mdn, $player);
        });

        MdnEvent::dispatch(
            $this->userIdFor($playerId),
            'member_left',
            'Left MDN',
            ['mdn_id' => $mdnId],
        );
    }

    public function kick(int $leaderPlayerId, int $targetPlayerId): void
    {
        if ($leaderPlayerId === $targetPlayerId) {
            throw MdnException::cannotActOnSelf();
        }

        $mdnId = null;

        DB::transaction(function () use ($leaderPlayerId, $targetPlayerId, &$mdnId) {
            /** @var Player $leader */
            $leader = Player::query()->lockForUpdate()->findOrFail($leaderPlayerId);
            /** @var Player $target */
            $target = Player::query()->lockForUpdate()->findOrFail($targetPlayerId);

            if ($leader->mdn_id === null || $leader->mdn_id !== $target->mdn_id) {
                throw MdnException::targetNotMember();
            }

            $leaderMembership = MdnMembership::query()
                ->where('mdn_id', $leader->mdn_id)
                ->where('player_id', $leader->id)
                ->first();

            if (! $leaderMembership || $leaderMembership->role !== self::ROLE_LEADER) {
                throw MdnException::notLeader();
            }

            /** @var Mdn $mdn */
            $mdn = Mdn::query()->lockForUpdate()->findOrFail($leader->mdn_id);
            $mdnId = $mdn->id;

            $this->removeMember($mdn, $target);
        });

        MdnEvent::dispatch(
            $this->userIdFor($targetPlayerId),
            'member_kicked',
            'Removed from MDN',
            ['mdn_id' => $mdnId],
        );
    }

    public function promote(int $leaderPlayerId, int $targetPlayerId, string $role): void
    {
        $valid = [self::ROLE_OFFICER, self::ROLE_MEMBER];
        if (! in_array($role, $valid, true)) {
            throw MdnException::invalidRole($role);
        }
        if ($leaderPlayerId === $targetPlayerId) {
            throw MdnException::cannotActOnSelf();
        }

        DB::transaction(function () use ($leaderPlayerId, $targetPlayerId, $role) {
            /** @var Player $leader */
            $leader = Player::query()->lockForUpdate()->findOrFail($leaderPlayerId);
            /** @var Player $target */
            $target = Player::query()->lockForUpdate()->findOrFail($targetPlayerId);

            if ($leader->mdn_id === null || $leader->mdn_id !== $target->mdn_id) {
                throw MdnException::targetNotMember();
            }

            $leaderMembership = MdnMembership::query()
                ->where('mdn_id', $leader->mdn_id)
                ->where('player_id', $leader->id)
                ->first();
            if (! $leaderMembership || $leaderMembership->role !== self::ROLE_LEADER) {
                throw MdnException::notLeader();
            }

            MdnMembership::query()
                ->where('mdn_id', $leader->mdn_id)
                ->where('player_id', $target->id)
                ->update(['role' => $role]);
        });

        MdnEvent::dispatch(
            $this->userIdFor($targetPlayerId),
            'promoted',
            'MDN role changed',
            ['new_role' => $role],
        );
    }

    public function disband(int $leaderPlayerId): void
    {
        $affectedUserIds = [];
        $mdnId = null;

        DB::transaction(function () use ($leaderPlayerId, &$affectedUserIds, &$mdnId) {
            /** @var Player $leader */
            $leader = Player::query()->lockForUpdate()->findOrFail($leaderPlayerId);

            if ($leader->mdn_id === null) {
                throw MdnException::notAMember();
            }

            $leaderMembership = MdnMembership::query()
                ->where('mdn_id', $leader->mdn_id)
                ->where('player_id', $leader->id)
                ->first();
            if (! $leaderMembership || $leaderMembership->role !== self::ROLE_LEADER) {
                throw MdnException::notLeader();
            }

            /** @var Mdn $mdn */
            $mdn = Mdn::query()->lockForUpdate()->findOrFail($leader->mdn_id);
            $mdnId = $mdn->id;

            // Collect user IDs for the post-commit broadcast.
            $affectedUserIds = Player::query()
                ->where('mdn_id', $mdn->id)
                ->pluck('user_id')
                ->all();

            // Null every member's mdn pointer.
            Player::query()
                ->where('mdn_id', $mdn->id)
                ->update([
                    'mdn_id' => null,
                    'mdn_left_at' => now(),
                ]);

            // Drop memberships, alliances, journal (cascade via FK).
            MdnMembership::query()->where('mdn_id', $mdn->id)->delete();
            \App\Models\MdnAlliance::query()
                ->where('mdn_a_id', $mdn->id)
                ->orWhere('mdn_b_id', $mdn->id)
                ->delete();
            \App\Models\MdnJournalEntry::query()->where('mdn_id', $mdn->id)->delete();

            $mdn->delete();
        });

        foreach ($affectedUserIds as $uid) {
            MdnEvent::dispatch(
                (int) $uid,
                'disbanded',
                'MDN disbanded',
                ['mdn_id' => $mdnId],
            );
        }
    }

    /**
     * Shared combat gate. Called from AttackService and SpyService.
     * Throws the appropriate typed exception so the caller doesn't
     * have to care which action triggered it.
     */
    public function assertCanAttackOrSpy(Player $attacker, Player $target, string $action): void
    {
        $sameMdnBlocked = (bool) $this->config->get('mdn.same_mdn_attacks_blocked', true);

        if ($sameMdnBlocked
            && $attacker->mdn_id !== null
            && $target->mdn_id !== null
            && (int) $attacker->mdn_id === (int) $target->mdn_id
        ) {
            throw $action === 'spy'
                ? CannotSpyException::sameMdn()
                : CannotAttackException::sameMdn();
        }

        $cooldownHours = (int) $this->config->get('mdn.join_leave_cooldown_hours', 24);
        if ($cooldownHours <= 0) {
            return;
        }

        $mostRecent = null;
        if ($attacker->mdn_joined_at !== null) {
            $mostRecent = $attacker->mdn_joined_at;
        }
        if ($attacker->mdn_left_at !== null
            && ($mostRecent === null || $attacker->mdn_left_at->gt($mostRecent))
        ) {
            $mostRecent = $attacker->mdn_left_at;
        }

        if ($mostRecent === null) {
            return;
        }

        $unlockAt = $mostRecent->copy()->addHours($cooldownHours);
        if ($unlockAt->isFuture()) {
            $hoursLeft = (int) ceil(now()->diffInMinutes($unlockAt, absolute: true) / 60);
            $hoursLeft = max(1, $hoursLeft);
            throw $action === 'spy'
                ? CannotSpyException::mdnHopCooldown($hoursLeft)
                : CannotAttackException::mdnHopCooldown($hoursLeft);
        }
    }

    /**
     * Shared removal path for leave/kick. If the departing member is
     * the last leader and others remain, leadership transfers to the
     * oldest officer (or oldest member if no officers). If they are
     * the sole member the MDN is deleted outright.
     */
    private function removeMember(Mdn $mdn, Player $player): void
    {
        $membership = MdnMembership::query()
            ->where('mdn_id', $mdn->id)
            ->where('player_id', $player->id)
            ->first();

        if (! $membership) {
            throw MdnException::notAMember();
        }

        $wasLeader = $membership->role === self::ROLE_LEADER;

        $membership->delete();

        $player->update([
            'mdn_id' => null,
            'mdn_left_at' => now(),
        ]);

        $mdn->decrement('member_count');
        $mdn->refresh();

        if ($mdn->member_count <= 0) {
            \App\Models\MdnAlliance::query()
                ->where('mdn_a_id', $mdn->id)
                ->orWhere('mdn_b_id', $mdn->id)
                ->delete();
            \App\Models\MdnJournalEntry::query()->where('mdn_id', $mdn->id)->delete();
            $mdn->delete();
            return;
        }

        if ($wasLeader) {
            $successor = MdnMembership::query()
                ->where('mdn_id', $mdn->id)
                ->orderByRaw("CASE role WHEN 'officer' THEN 0 ELSE 1 END")
                ->orderBy('joined_at')
                ->first();

            if ($successor) {
                MdnMembership::query()
                    ->where('mdn_id', $mdn->id)
                    ->where('player_id', $successor->player_id)
                    ->update(['role' => self::ROLE_LEADER]);

                $mdn->update(['leader_player_id' => $successor->player_id]);
            }
        }
    }

    private function userIdFor(int $playerId): int
    {
        $player = Player::query()->find($playerId);
        return (int) ($player?->user_id ?? 0);
    }
}
