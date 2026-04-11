<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Umbrella exception for MDN lifecycle errors — raised from
 * App\Domain\Mdn\MdnService, MdnAllianceService, and MdnJournalService.
 * Controllers catch this and surface the message to the user.
 */
class MdnException extends RuntimeException
{
    public static function nameTaken(string $name): self
    {
        return new self("An MDN named '{$name}' already exists");
    }

    public static function tagTaken(string $tag): self
    {
        return new self("An MDN with tag '{$tag}' already exists");
    }

    public static function nameInvalid(string $reason): self
    {
        return new self("MDN name is invalid: {$reason}");
    }

    public static function tagInvalid(string $reason): self
    {
        return new self("MDN tag is invalid: {$reason}");
    }

    public static function insufficientCash(float $need, float $have): self
    {
        return new self(sprintf(
            'Creating an MDN costs A%.2f but you only have A%.2f',
            $need,
            $have,
        ));
    }

    public static function alreadyInMdn(): self
    {
        return new self('You already belong to an MDN — leave it first before joining another');
    }

    public static function notAMember(): self
    {
        return new self('You are not a member of that MDN');
    }

    public static function notLeader(): self
    {
        return new self('Only the MDN leader can perform this action');
    }

    public static function atCapacity(int $cap): self
    {
        return new self("MDN is at capacity ({$cap} members)");
    }

    public static function targetNotMember(): self
    {
        return new self('Target player is not a member of this MDN');
    }

    public static function cannotActOnSelf(): self
    {
        return new self('Cannot perform this action on yourself');
    }

    public static function invalidRole(string $role): self
    {
        return new self("Invalid MDN role '{$role}' (expected leader|officer|member)");
    }

    public static function allianceExists(): self
    {
        return new self('Those two MDNs are already allied');
    }

    public static function allianceWithSelf(): self
    {
        return new self('An MDN cannot ally with itself');
    }

    public static function allianceNotFound(): self
    {
        return new self('Alliance not found');
    }

    public static function journalDisabled(): self
    {
        return new self('The shared journal is disabled in this world');
    }

    public static function journalFull(int $max): self
    {
        return new self("Journal is full ({$max} entries). Remove older entries first");
    }

    public static function invalidVote(string $vote): self
    {
        return new self("Invalid vote '{$vote}' (expected helpful|unhelpful)");
    }

    public static function entryNotInMdn(): self
    {
        return new self('That journal entry does not belong to your MDN');
    }
}
