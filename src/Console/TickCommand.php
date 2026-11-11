<?php

namespace ErnestDefoe\Stances\Console;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Notification\ClosedBlueprint;
use ErnestDefoe\Stances\Settings;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Console\Command;

/**
 * Every five minutes: blocks that closed (by date or by hand) since the last run. With the admin's
 * "voting closed" alert on, their voters are told, 200 at a time; either way each block is marked,
 * so switching the alert on later never alerts about old blocks.
 */
class TickCommand extends Command
{
    protected $signature = 'stances:tick';

    protected $description = 'Mark closed Stances blocks and, if switched on, tell their voters.';

    public function handle(Settings $settings, NotificationSyncer $notifications): int
    {
        $now = Carbon::now();
        $alert = $settings->flag(Settings::CLOSED_ALERT, false);

        Block::query()
            ->whereNull('closed_notified_at')
            ->whereNotNull('post_id')
            ->where(fn ($q) => $q->whereNotNull('closed_at')->orWhere('closes_at', '<=', $now))
            ->orderBy('id')
            ->chunkById(50, function ($blocks) use ($alert, $notifications, $now) {
                foreach ($blocks as $block) {
                    if ($alert && $block->hidden_at === null && $block->vote_count > 0) {
                        User::query()
                            ->whereIn('id', fn ($q) => $q->select('user_id')->from('stance_votes')->where('block_id', $block->id))
                            ->chunkById(200, fn ($users) => $notifications->sync(new ClosedBlueprint($block), $users->all()));
                    }
                    $block->closed_notified_at = $now;
                    $block->save();
                }
            });

        return 0;
    }
}
