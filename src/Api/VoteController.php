<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Votes;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /api/stances/{id}/vote   {choice}: vote, or change it where the block allows
 * DELETE /api/stances/{id}/vote   take it back where the block allows
 */
class VoteController extends Handler
{
    public const PER_MINUTE = 60;

    public function __construct(
        private readonly Votes $votes,
        private readonly Presenter $presenter,
        private readonly Repository $cache,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $block = Find::visible($request, $actor);

        if (! $this->presenter->one($block, $actor)['canVote']) {
            self::deny();
        }

        $choice = $request->getMethod() === 'DELETE' ? null : Input::choice(self::body($request)['choice'] ?? null);
        if ($request->getMethod() !== 'DELETE' && $choice === null) {
            self::deny();
        }

        // Every vote and change is a history row: 60 a minute is far past anyone clicking.
        $key = 'stances-votes-'.$actor->id;
        $count = (int) $this->cache->get($key, 0);
        if ($count >= self::PER_MINUTE) {
            throw new Refusal('stances_too_many_votes', 429);
        }
        $this->cache->put($key, $count + 1, 60);

        $block = $this->votes->vote($block, $actor, $choice);

        return new JsonResponse(['data' => $this->presenter->one($block, $actor)]);
    }
}
