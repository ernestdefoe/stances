<?php

namespace ErnestDefoe\Stances\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/** The Floxum code review of 1.0.0 (2026-11-11), one test per finding. */
class FloxumReviewTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-stances');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'lee', 'email' => 'lee@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, $path, $options));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function draft(int $as = 2): int
    {
        [$status, $json] = $this->call('POST', '/api/stances', $as, ['type' => 'agree', 'question' => 'The trade deadline should move to week 10.', 'labels' => ['Agree', 'Disagree']]);
        $this->assertSame(201, $status, json_encode($json));

        return (int) $json['data']['id'];
    }

    /** @return array{0: int, 1: int} discussion id, first post id */
    private function discussion(string $content, int $as = 2): array
    {
        [$status, $json] = $this->call('POST', '/api/discussions', $as, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Owners meeting', 'content' => $content]]]);
        $this->assertSame(201, $status, json_encode($json));

        return [(int) $json['data']['id'], (int) $json['data']['relationships']['firstPost']['data']['id']];
    }

    /** The sidebar count from the forum resource, and whether working it out queried the votes table. */
    private function needCount(int $as): array
    {
        $db = $this->app()->getContainer()->make('db')->connection();
        $db->flushQueryLog();
        $db->enableQueryLog();
        [, $json] = $this->call('GET', '/api', $as);
        $queried = collect($db->getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'stance_votes'));
        $db->disableQueryLog();

        return [$json['data']['attributes']['stancesNeedVote'], $queried];
    }

    private function upload(string $bytes, string $type, int $as): array
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'p', $type);
        $response = $this->send($this->request('POST', '/api/stances/images', ['authenticatedAs' => $as])->withUploadedFiles(['image' => $file]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function the_sidebar_count_is_cached_per_member_and_follows_every_change()
    {
        $this->assertSame([0, true], $this->needCount(3));
        // Cached: the next page load runs no count.
        $this->assertSame([0, false], $this->needCount(3));

        // A block joins a post: every member's count is worked out again.
        $id = $this->draft();
        [, $post] = $this->discussion("[stance={$id}]");
        $this->assertSame([1, true], $this->needCount(3));
        $this->assertSame([1, false], $this->needCount(3));
        $this->assertSame([1, true], $this->needCount(4));

        // Her own vote forgets only her count.
        $this->assertSame(200, $this->call('POST', "/api/stances/{$id}/vote", 3, ['choice' => 0])[0]);
        $this->assertSame([0, true], $this->needCount(3));
        $this->assertSame([1, false], $this->needCount(4));

        // The author closes it, reopens it; a moderator hides the post.
        $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => true]);
        $this->assertSame([0, true], $this->needCount(4));
        $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => false]);
        $this->assertSame([1, true], $this->needCount(4));
        $this->assertSame(200, $this->call('PATCH', "/api/posts/{$post}", 1, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['isHidden' => true]]])[0]);
        $this->assertSame([0, true], $this->needCount(4));
    }

    #[Test]
    public function an_upload_is_judged_by_its_bytes_not_the_type_the_browser_claims()
    {
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();

        // Claimed to be a PNG, but HTML (or an SVG): refused before anything decodes it.
        $this->assertSame('stances_bad_image', $this->upload('<html><script>alert(1)</script></html>', 'image/png', 3)[1]['errors'][0]['code']);
        $this->assertSame(422, $this->upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/png', 3)[0]);
        // A real PNG the browser didn't label: taken.
        [$status, $json] = $this->upload($png, 'application/octet-stream', 3);
        $this->assertSame(201, $status, json_encode($json));
    }
}
