<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Markers;
use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Sides;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Collection;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * The two post fields, `stanceBlocks` and `stanceSide`, for every post in a response at once.
 *
 * Each getter queues its post and returns a closure; Flarum resolves closures after every other
 * field, so the first one loads everything queued in a fixed number of queries: blocks only when
 * some post holds a marker (none otherwise), badges in three. State is keyed by the request, never
 * kept on a model or shared between viewers. Static, so it holds however often the class is
 * resolved; the WeakMap lets each request's state go with the request.
 */
class PostBatch
{
    /** @var WeakMap<ServerRequestInterface, array{posts: array<int, Post>, blocks: array<int, list<array<string, mixed>>>|null, sides: array<int, array<string, mixed>|null>|null}> */
    private static ?WeakMap $state = null;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Sides $sides,
    ) {
        self::$state ??= new WeakMap();
    }

    public function blocks(Post $post, ServerRequestInterface $request): \Closure
    {
        $this->queue($post, $request);

        return function () use ($post, $request) {
            $state = self::$state[$request];
            if ($state['blocks'] === null) {
                $state['blocks'] = $this->loadBlocks($state['posts'], $request);
                self::$state[$request] = $state;
            }

            return $state['blocks'][$post->id] ?? [];
        };
    }

    public function side(Post $post, ServerRequestInterface $request): \Closure
    {
        $this->queue($post, $request);

        return function () use ($post, $request) {
            $state = self::$state[$request];
            if ($state['sides'] === null) {
                $state['sides'] = $this->loadSides($state['posts']);
                self::$state[$request] = $state;
            }

            return $state['sides'][$post->id] ?? null;
        };
    }

    private function queue(Post $post, ServerRequestInterface $request): void
    {
        $state = self::$state[$request] ?? ['posts' => [], 'blocks' => null, 'sides' => null];
        $state['posts'][$post->id] = $post;
        self::$state[$request] = $state;
    }

    /**
     * @param array<int, Post> $posts
     * @return array<int, list<array<string, mixed>>>
     */
    private function loadBlocks(array $posts, ServerRequestInterface $request): array
    {
        $holding = array_filter($posts, fn (Post $p) => Markers::has($p->getAttributes()['content'] ?? null));
        if (! $holding) {
            return [];
        }

        /** @var Collection<int, Block> $blocks */
        $blocks = Block::query()->whereIn('post_id', array_keys($holding))->whereNull('hidden_at')->orderBy('id')->get();
        foreach ($blocks as $block) {
            $post = $holding[$block->post_id];
            if ($post->relationLoaded('discussion') && $post->discussion) {
                $block->setRelation('discussion', $post->discussion);
            }
        }

        $out = [];
        foreach ($this->presenter->many($blocks, RequestUtil::getActor($request)) as $data) {
            $out[$data['postId']][] = $data;
        }

        return $out;
    }

    /**
     * @param array<int, Post> $posts
     * @return array<int, array<string, mixed>|null>
     */
    private function loadSides(array $posts): array
    {
        $authored = array_filter($posts, fn (Post $p) => $p->type === 'comment' && $p->user_id);
        if (! $authored) {
            return [];
        }

        $badges = $this->sides->badgeBlocks(array_values(array_map(fn (Post $p) => (int) $p->discussion_id, $authored)));
        if (! $badges) {
            return [];
        }

        $history = $this->sides->history(
            array_map(fn (Block $b) => $b->id, array_values($badges)),
            array_values(array_map(fn (Post $p) => (int) $p->user_id, $authored))
        );

        $out = [];
        foreach ($authored as $post) {
            $block = $badges[(int) $post->discussion_id] ?? null;
            if ($block) {
                $out[$post->id] = $this->sides->side($block, $history->get($block->id.':'.$post->user_id), $post->created_at);
            }
        }

        return $out;
    }
}
