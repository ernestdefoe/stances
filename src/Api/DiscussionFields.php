<?php

namespace ErnestDefoe\Stances\Api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Settings;
use ErnestDefoe\Stances\Sides;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * On the discussion page only (none of this runs for lists): the featured block, which block
 * makes the badges and its labels (for the "Show replies from" filter), and whether the viewer
 * may choose either. On lists, only with the admin's icon setting on and for a member: whether the
 * discussion holds an open block they haven't voted on, one query for the whole page.
 */
class DiscussionFields
{
    /** @var WeakMap<ServerRequestInterface, array{ids: array<int, true>, open: array<int, true>|null}>|null */
    private static ?WeakMap $needs = null;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Sides $sides,
        private readonly Settings $settings,
    ) {
    }

    /** @return array<int, Schema\Attribute> */
    public function __invoke(): array
    {
        $showing = fn (Discussion $d, Context $context) => $context->showing(DiscussionResource::class);

        return [
            Schema\Arr::make('stanceFeatured')
                ->visible($showing)
                ->nullable()
                ->get(function (Discussion $discussion, Context $context) {
                    $featuredId = $discussion->getAttribute('stance_featured_id');
                    if (! $featuredId) {
                        return null;
                    }
                    $actor = $context->getActor();
                    $block = Block::query()->visibleTo($actor)->find($featuredId);
                    if (! $block) {
                        return null;
                    }
                    $block->setRelation('discussion', $discussion);

                    return $this->presenter->one($block, $actor);
                }),
            Schema\Arr::make('stanceBadge')
                ->visible($showing)
                ->nullable()
                ->get(function (Discussion $discussion) {
                    $block = $this->sides->badgeBlocks([(int) $discussion->id])[(int) $discussion->id] ?? null;

                    return $block ? ['id' => $block->id, 'labels' => $block->labels, 'picked' => (int) $discussion->getAttribute('stance_badge_id') === $block->id] : null;
                }),
            Schema\Boolean::make('stanceCanSteer')
                ->visible($showing)
                ->get(fn (Discussion $discussion, Context $context) => Access::canSteer($context->getActor(), $discussion)),
            Schema\Boolean::make('stanceCanCreate')
                ->visible($showing)
                ->get(fn (Discussion $discussion, Context $context) => ! $context->getActor()->isGuest() && Access::can($context->getActor(), Settings::CREATE, $discussion)),
            Schema\Boolean::make('stancesNeedVote')
                ->visible(fn (Discussion $d, Context $context) => ! $context->showing(DiscussionResource::class)
                    && ! $context->getActor()->isGuest()
                    && $this->settings->flag(Settings::LIST_ICON, false))
                ->get(fn (Discussion $discussion, Context $context) => $this->needsVote($discussion, $context)),
        ];
    }

    private function needsVote(Discussion $discussion, Context $context): \Closure
    {
        self::$needs ??= new WeakMap();
        $request = $context->request;
        $state = self::$needs[$request] ?? ['ids' => [], 'open' => null];
        $state['ids'][(int) $discussion->id] = true;
        self::$needs[$request] = $state;

        return function () use ($discussion, $request, $context) {
            $state = self::$needs[$request];
            if ($state['open'] === null) {
                $actor = $context->getActor();
                $now = Carbon::now();
                $state['open'] = Block::query()->visibleTo($actor)
                    ->whereIn('stance_blocks.discussion_id', array_keys($state['ids']))
                    ->whereNull('stance_blocks.closed_at')
                    ->where(fn ($q) => $q->whereNull('stance_blocks.closes_at')->orWhere('stance_blocks.closes_at', '>', $now))
                    ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('stance_votes')
                        ->whereColumn('stance_votes.block_id', 'stance_blocks.id')
                        ->where('stance_votes.user_id', $actor->id))
                    ->distinct()
                    ->pluck('stance_blocks.discussion_id')
                    ->mapWithKeys(fn ($id) => [(int) $id => true])
                    ->all();
                self::$needs[$request] = $state;
            }

            return isset($state['open'][(int) $discussion->id]);
        };
    }
}
