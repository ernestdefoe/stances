<?php

namespace ErnestDefoe\Stances\Listener;

use ErnestDefoe\Stances\Event\Voted;
use ErnestDefoe\Stances\NeedsVote;

/**
 * Keeps the cached "needs your vote" counts honest. Posts and discussions hidden, restored,
 * deleted, locked, unlocked or moved to other tags change which blocks members can vote in, so
 * every count is worked out again; a member's own vote only changes theirs.
 */
class NeedsVoteChanged
{
    public function __construct(private readonly NeedsVote $needs)
    {
    }

    public function handle(object $event): void
    {
        $this->needs->changed();
    }

    public function voted(Voted $event): void
    {
        $this->needs->forget($event->actor);
    }
}
