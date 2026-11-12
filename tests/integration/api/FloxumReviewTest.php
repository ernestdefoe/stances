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
    use JsonApiCalls;

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

    /** @return array{int, array<string, mixed>|null, string} */
    private function raw(string $method, string $path, ?int $as, ?array $json = null, array $params = []): array
    {
        $options = ($as ? ['authenticatedAs' => $as] : []) + ($json !== null ? ['json' => $json] : []);
        $response = $this->send($this->request($method, $path, $options)->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $response->getHeaderLine('Content-Type')];
    }

    #[Test]
    public function blocks_are_a_json_api_resource_with_json_api_errors()
    {
        $attributes = ['blockType' => 'agree', 'question' => 'Week 10?', 'labels' => ['Agree', 'Disagree']];

        [$status, $json, $type] = $this->raw('POST', '/api/stances', 3, ['data' => ['type' => 'stances', 'attributes' => $attributes]]);
        $this->assertSame(201, $status, json_encode($json));
        $this->assertStringContainsString('application/vnd.api+json', $type);
        $this->assertSame('stances', $json['data']['type']);
        $this->assertIsString($json['data']['id']);
        $this->assertTrue($json['data']['attributes']['canEdit']);
        $this->assertSame('agree', $json['data']['attributes']['blockType']);
        $this->assertArrayNotHasKey('type', $json['data']['attributes'], 'reserved in JSON:API');
        $id = $json['data']['id'];

        // Show, Update and the actions answer with the same document.
        $this->assertSame('stances', $this->raw('GET', "/api/stances/{$id}", 3)[1]['data']['type']);
        [$status, $json] = $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['question' => 'Week 11?', 'labels' => ['Agree', 'Disagree']]]]);
        $this->assertSame([200, 'Week 11?'], [$status, $json['data']['attributes']['question']]);

        // Errors are JSON:API error objects carrying our codes.
        [$status, $json] = $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['anonymous' => true]]]);
        $this->assertSame(200, $status);
        [$status, $json, $type] = $this->raw('GET', '/api/stances/999999', 3);
        $this->assertSame([404, '404', 'stances_not_found'], [$status, $json['errors'][0]['status'], $json['errors'][0]['code']]);
        $this->assertStringContainsString('application/vnd.api+json', $type);

        // Fields the schema doesn't have, or that are read-only, are refused; guests can't write.
        $this->assertSame(400, $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['userId' => 1]]])[0]);
        $this->assertSame(403, $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['voteCount' => 50]]])[0]);
        // (A guest without a session meets the CSRF check first: 400; with one, the endpoint's 401.)
        $before = \ErnestDefoe\Stances\Block::query()->count();
        $this->assertContains($this->raw('POST', '/api/stances', null, ['data' => ['type' => 'stances', 'attributes' => $attributes]])[0], [400, 401]);
        $this->assertContains($this->raw('DELETE', "/api/stances/{$id}", null)[0], [400, 401]);
        $this->assertSame($before, \ErnestDefoe\Stances\Block::query()->count());

        // Someone else's draft is as missing as one that doesn't exist; its author deletes it.
        $this->assertSame(404, $this->raw('DELETE', "/api/stances/{$id}", 4)[0]);
        $this->assertSame(204, $this->raw('DELETE', "/api/stances/{$id}", 3)[0]);
    }

    #[Test]
    public function the_stances_page_is_the_index_endpoint_twenty_at_a_time()
    {
        for ($d = 0; $d < 7; $d++) {
            $markers = [];
            for ($i = 0; $i < 3; $i++) {
                $markers[] = '[stance='.$this->draft(1).']';
            }
            $this->discussion(implode("\n\n", $markers), 1);
        }

        [$status, $json] = $this->raw('GET', '/api/stances', 3, null, ['filter' => ['tab' => 'new']]);
        $this->assertSame(200, $status, json_encode($json));
        $this->assertCount(20, $json['data']);
        $this->assertArrayHasKey('next', $json['links']);
        $firstId = $json['data'][0]['id'];
        $first = $json['data'][0]['attributes'];
        $this->assertSame('Owners meeting', $first['discussion']['title']);
        $this->assertSame(0, $first['votesToday']);
        $this->assertSame('admin', $first['author']['username']);
        $this->assertArrayNotHasKey('total', $json['meta']['page'] ?? [], 'nothing counts a total');

        [, $json] = $this->raw('GET', '/api/stances', 3, null, ['filter' => ['tab' => 'new'], 'page' => ['offset' => 20]]);
        $this->assertCount(1, $json['data']);

        // Only the page carries where a block is; Show doesn't.
        $this->assertArrayNotHasKey('votesToday', $this->raw('GET', "/api/stances/{$firstId}", 3)[1]['data']['attributes']);
    }

    #[Test]
    public function as_a_notifications_subject_a_block_carries_only_its_question_and_place()
    {
        $this->setting('ernestdefoe-stances.closed_alert', '1');
        $id = $this->draft();
        $this->discussion("[stance={$id}]");
        $this->assertSame(200, $this->call('POST', "/api/stances/{$id}/vote", 3, ['choice' => 1])[0]);
        $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => true]);
        $command = $this->app()->getContainer()->make(\ErnestDefoe\Stances\Console\TickCommand::class);
        $command->setLaravel($this->app()->getContainer());
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());

        [$status, $json] = $this->raw('GET', '/api/notifications', 3);
        $this->assertSame(200, $status, json_encode($json));
        $subject = collect($json['included'] ?? [])->firstWhere('type', 'stances');
        $this->assertNotNull($subject, json_encode($json));
        $this->assertEqualsCanonicalizing(['question', 'postId', 'discussionId'], array_keys($subject['attributes']));
    }
}
