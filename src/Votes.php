<?php

namespace ErnestDefoe\Stances;

use Carbon\Carbon;
use ErnestDefoe\Stances\Event\Voted;
use ErnestDefoe\Stances\Notification\MilestoneBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Voting, changing, taking back and resetting.
 *
 * 🚨 Race-safe: the block row is locked (SELECT … FOR UPDATE) and read again INSIDE the
 * transaction, so a vote that lands alongside a close or a reset is judged against the row as it
 * is then. Counts are recounted from stance_votes, never incremented, so they can't drift (and an
 * unsigned counter never goes below zero).
 */
class Votes
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly NotificationSyncer $notifications,
        private readonly Dispatcher $events,
    ) {
    }

    /** $choice null takes the vote back. */
    public function vote(Block $block, User $actor, ?int $choice): Block
    {
        [$locked, $before] = $this->db->transaction(function () use ($block, $actor, $choice) {
            $locked = $this->lock($block);
            $before = $locked->vote_count;

            if ($locked->post_id === null || $locked->hidden_at !== null) {
                throw new Refusal('stances_not_found', 404);
            }
            if ($locked->isClosed()) {
                throw new Refusal('stances_closed');
            }
            if ($choice !== null && ($choice < $locked->minChoice() || $choice > $locked->maxChoice())) {
                throw new Refusal('stances_bad_choice');
            }

            $vote = Vote::query()->where('block_id', $locked->id)->where('user_id', $actor->id)->first();
            if ($vote && $vote->choice === $choice) {
                return [$locked, $before];
            }
            if ($vote && ! $locked->allow_change) {
                throw new Refusal('stances_locked_vote');
            }

            if ($choice === null) {
                if (! $vote) {
                    return [$locked, $before];
                }
                if (! $locked->allow_remove) {
                    throw new Refusal('stances_locked_vote');
                }
                $vote->delete();
            } else {
                $vote ??= new Vote();
                $vote->block_id = $locked->id;
                $vote->user_id = $actor->id;
                $vote->choice = $choice;
                $vote->save();
            }

            $this->db->table('stance_vote_log')->insert([
                'block_id' => $locked->id,
                'user_id' => $actor->id,
                'choice' => $choice,
                'created_at' => Carbon::now(),
            ]);

            $this->recount($locked);

            return [$locked, $before];
        });

        $this->milestones($locked, $before);
        $this->events->dispatch(new Voted($locked, $actor));

        return $locked;
    }

    /** Wipes every vote and the history behind the badges' "changed" tags. */
    public function reset(Block $block): Block
    {
        return $this->db->transaction(function () use ($block) {
            $locked = $this->lock($block);
            Vote::query()->where('block_id', $locked->id)->delete();
            $this->db->table('stance_vote_log')->where('block_id', $locked->id)->delete();
            $this->recount($locked);

            return $locked;
        });
    }

    public function recount(Block $block): void
    {
        $counts = Vote::query()->where('block_id', $block->id)
            ->selectRaw('choice, COUNT(*) as aggregate')->groupBy('choice')->pluck('aggregate', 'choice');

        $list = [];
        for ($c = $block->minChoice(); $c <= $block->maxChoice(); $c++) {
            $list[] = (int) ($counts[$c] ?? 0);
        }

        $block->counts = $list;
        $block->vote_count = array_sum($list);
        $block->save();
    }

    private function lock(Block $block): Block
    {
        /** @var Block $locked */
        $locked = Block::query()->whereKey($block->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /** The author hears when their block passes 10, 50, 100, 500 and 1,000 votes; never twice for one. */
    private function milestones(Block $block, int $before): void
    {
        if (! $block->user_id || $block->vote_count <= $before) {
            return;
        }

        foreach (Settings::MILESTONES as $milestone) {
            if ($before < $milestone && $block->vote_count >= $milestone && ($author = $block->user)) {
                $this->notifications->sync(new MilestoneBlueprint($block, $milestone), [$author]);
            }
        }
    }
}
