<?php

namespace App\Domain\Mdn;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\MdnException;
use App\Models\MdnJournalEntry;
use App\Models\MdnJournalVote;
use App\Models\Player;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared journal with ratings-sortable most-helpful-first display,
 * per gameplay-ultraplan §12.1. Entries belong to an MDN; votes are
 * per-player-per-entry with helpful/unhelpful tallies denormalized on
 * the entry row for cheap sorting.
 */
class MdnJournalService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    public function addEntry(int $playerId, ?int $tileId, string $body): MdnJournalEntry
    {
        if (! (bool) $this->config->get('mdn.journal.enabled', true)) {
            throw MdnException::journalDisabled();
        }

        $bodyMax = (int) $this->config->get('mdn.journal.body_max_length', 1000);
        $entryCap = (int) $this->config->get('mdn.journal.max_entries_per_mdn', 500);

        $body = trim($body);
        if ($body === '') {
            throw MdnException::bodyInvalid('cannot be empty');
        }
        if (mb_strlen($body) > $bodyMax) {
            throw MdnException::bodyInvalid("must be <= {$bodyMax} chars");
        }

        return DB::transaction(function () use ($playerId, $tileId, $body, $entryCap) {
            /** @var Player $player */
            $player = Player::query()->findOrFail($playerId);
            if ($player->mdn_id === null) {
                throw MdnException::notAMember();
            }

            $current = MdnJournalEntry::query()
                ->where('mdn_id', $player->mdn_id)
                ->count();
            if ($current >= $entryCap) {
                throw MdnException::journalFull($entryCap);
            }

            return MdnJournalEntry::create([
                'mdn_id' => $player->mdn_id,
                'author_player_id' => $player->id,
                'tile_id' => $tileId,
                'body' => $body,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
            ]);
        });
    }

    public function vote(int $playerId, int $entryId, string $vote): void
    {
        if (! in_array($vote, ['helpful', 'unhelpful'], true)) {
            throw MdnException::invalidVote($vote);
        }

        DB::transaction(function () use ($playerId, $entryId, $vote) {
            /** @var Player $player */
            $player = Player::query()->findOrFail($playerId);
            if ($player->mdn_id === null) {
                throw MdnException::notAMember();
            }

            /** @var MdnJournalEntry $entry */
            $entry = MdnJournalEntry::query()->lockForUpdate()->findOrFail($entryId);
            if ((int) $entry->mdn_id !== (int) $player->mdn_id) {
                throw MdnException::entryNotInMdn();
            }

            /** @var MdnJournalVote|null $existing */
            $existing = MdnJournalVote::query()
                ->where('entry_id', $entry->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->vote === $vote) {
                    return; // idempotent
                }
                // Swap: decrement old bucket, increment new.
                if ($existing->vote === 'helpful') {
                    $entry->decrement('helpful_count');
                    $entry->increment('unhelpful_count');
                } else {
                    $entry->decrement('unhelpful_count');
                    $entry->increment('helpful_count');
                }
                $existing->update(['vote' => $vote]);
                return;
            }

            MdnJournalVote::create([
                'entry_id' => $entry->id,
                'player_id' => $player->id,
                'vote' => $vote,
            ]);

            if ($vote === 'helpful') {
                $entry->increment('helpful_count');
            } else {
                $entry->increment('unhelpful_count');
            }
        });
    }

    /** @return Collection<int, MdnJournalEntry> */
    public function list(int $mdnId, string $sort = 'helpful'): Collection
    {
        $q = MdnJournalEntry::query()->where('mdn_id', $mdnId);

        if ($sort === 'recent') {
            $q->orderByDesc('created_at');
        } else {
            $q->orderByDesc('helpful_count')->orderByDesc('created_at');
        }

        return $q->limit(200)->get();
    }
}
