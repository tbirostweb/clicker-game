<?php

namespace App\Tests\Functional;

use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LeaderboardApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        static::getContainer()->get('cache.app')->clear();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function runPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Alice',
            'rebirths' => 1,
            'score' => 25000,
            'timeSeconds' => 120,
            'activeSeconds' => 100,
            'trophies' => 3,
        ];
    }

    private function api(string $method, string $uri, mixed $body = null, array $headers = [], string $ip = '10.0.0.1'): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $content = is_string($body) ? $body : (null === $body ? null : json_encode($body));
        $this->client->request($method, $uri, [], [], $server, $content);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $response];
    }

    private function create(array $overrides = [], array $headers = [], string $ip = '10.0.0.1'): array
    {
        [$status, $body] = $this->api('POST', '/api/leaderboard', $this->runPayload($overrides), $headers, $ip);
        self::assertSame(201, $status, json_encode($body));

        return $body;
    }

    public function testPostReturnsSecretTokenThatGetNeverExposes(): void
    {
        $run = $this->create();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run['editToken']);

        [$status, $list, $response] = $this->api('GET', '/api/leaderboard');
        self::assertSame(200, $status);
        self::assertCount(1, $list);
        self::assertArrayNotHasKey('editToken', $list[0]);
        self::assertStringNotContainsString($run['editToken'], (string) $response->getContent());
        self::assertStringNotContainsString(hash('sha256', $run['editToken']), (string) $response->getContent());

        $stored = $this->em()->getRepository(Player::class)->find($run['id']);
        self::assertSame(hash('sha256', $run['editToken']), $stored->getEditTokenHash(), 'only the hash is stored');
    }

    public function testOwnerCanUpdateOwnRunButNotSomeoneElses(): void
    {
        $a = $this->create(['name' => 'Alice']);
        $b = $this->create(['name' => 'Bob']);

        [$status, $body] = $this->api('PUT', '/api/leaderboard/' . $a['id'], $this->runPayload(['name' => 'Alice', 'timeSeconds' => 200, 'rebirths' => 2]), ['X-Edit-Token' => $a['editToken']]);
        self::assertSame(200, $status);
        self::assertSame(2, $body['rebirths']);

        [$status] = $this->api('PUT', '/api/leaderboard/' . $b['id'], $this->runPayload(['name' => 'Hacked', 'timeSeconds' => 999]), ['X-Edit-Token' => $a['editToken']]);
        self::assertSame(403, $status);

        [$status] = $this->api('PUT', '/api/leaderboard/' . $b['id'], $this->runPayload(['name' => 'Hacked', 'timeSeconds' => 999]));
        self::assertSame(401, $status);

        [, $list] = $this->api('GET', '/api/leaderboard');
        $names = array_column($list, 'name');
        self::assertContains('Bob', $names);
        self::assertNotContains('Hacked', $names);
    }

    public function testLegacyRunWithoutTokenCannotBeEdited(): void
    {
        $legacy = (new Player())->setName('Legacy')->setRebirth(1)->setScore(1)->setTimeSeconds(10)->setActiveSeconds(5)->setTrophyCount(0);
        $this->em()->persist($legacy);
        $this->em()->flush();

        [$status] = $this->api('PUT', '/api/leaderboard/' . $legacy->getId(), $this->runPayload(['timeSeconds' => 50]), ['X-Edit-Token' => str_repeat('a', 64)]);
        self::assertSame(403, $status);
    }

    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function invalidPayloads(): iterable
    {
        yield 'score as string' => [['score' => '123'], 422];
        yield 'score as array' => [['score' => [1]], 422];
        yield 'score as float' => [['score' => 1.5], 422];
        yield 'negative rebirths' => [['rebirths' => -1], 422];
        yield 'score overflow' => [['score' => 9_007_199_254_740_992], 422];
        yield 'time over a year' => [['timeSeconds' => 365 * 86400 + 1], 422];
        yield 'missing timeSeconds' => [['timeSeconds' => null], 422];
        yield 'name not string' => [['name' => ['x']], 422];
        yield 'name too long' => [['name' => str_repeat('a', 21)], 422];
        yield 'name too short' => [['name' => 'a'], 422];
        yield 'zero time' => [['timeSeconds' => 0], 422];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayloads(array $override, int $expected): void
    {
        $payload = $this->runPayload($override);
        $payload = array_filter($payload, static fn ($v) => null !== $v);
        [$status] = $this->api('POST', '/api/leaderboard', $payload);
        self::assertSame($expected, $status);
        self::assertSame(0, $this->em()->getRepository(Player::class)->count([]), 'nothing persisted on rejection');
    }

    public function testRejectsMalformedRequests(): void
    {
        [$status] = $this->api('POST', '/api/leaderboard', '{not json');
        self::assertSame(400, $status);
        [$status] = $this->api('POST', '/api/leaderboard', '[1,2]');
        self::assertSame(400, $status);
        [$status] = $this->api('POST', '/api/leaderboard', json_encode($this->runPayload()) . str_repeat(' ', 3000));
        self::assertSame(413, $status);

        $this->client->request('POST', '/api/leaderboard', [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '10.0.0.9'], json_encode($this->runPayload()));
        self::assertSame(415, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em()->getRepository(Player::class)->count([]));
    }

    public function testHtmlInNameIsStoredAsInertText(): void
    {
        $run = $this->create(['name' => '<b>x</b>"\'&']);
        self::assertSame('<b>x</b>"\'&', $run['name']);
        $this->api('GET', '/api/leaderboard');
        $response = $this->client->getResponse();
        self::assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('<b>', (string) $response->getContent(), 'JSON escapes HTML');
    }

    public function testUnknownSortFallsBackAndLimitIsCapped(): void
    {
        [$status] = $this->api('GET', '/api/leaderboard?sort=id;DROP&limit=100000');
        self::assertSame(200, $status);
    }

    public function testIdempotencyKeyPreventsDuplicatesAndRotatesToken(): void
    {
        $key = 'b7c1d7a2-4f3e-4c47-9a5e-2f6d1c0e9a11';
        $first = $this->create([], ['Idempotency-Key' => $key]);
        [$status, $second] = $this->api('POST', '/api/leaderboard', $this->runPayload(), ['Idempotency-Key' => $key]);
        self::assertSame(200, $status);
        self::assertSame($first['id'], $second['id']);
        self::assertNotSame($first['editToken'], $second['editToken']);
        self::assertSame(1, $this->em()->getRepository(Player::class)->count([]));

        [$status] = $this->api('PUT', '/api/leaderboard/' . $first['id'], $this->runPayload(['timeSeconds' => 300]), ['X-Edit-Token' => $first['editToken']]);
        self::assertSame(403, $status, 'rotated-out token no longer works');
        [$status] = $this->api('PUT', '/api/leaderboard/' . $first['id'], $this->runPayload(['timeSeconds' => 300]), ['X-Edit-Token' => $second['editToken']]);
        self::assertSame(200, $status);

        [$status] = $this->api('POST', '/api/leaderboard', $this->runPayload(), ['Idempotency-Key' => 'short']);
        self::assertSame(400, $status);
    }

    public function testStaleOrConcurrentUpdatesAreRejected(): void
    {
        $run = $this->create(['timeSeconds' => 500, 'rebirths' => 3]);
        $headers = ['X-Edit-Token' => $run['editToken']];

        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->runPayload(['timeSeconds' => 400, 'rebirths' => 3]), $headers);
        self::assertSame(409, $status, 'older time cannot overwrite newer');
        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->runPayload(['timeSeconds' => 600, 'rebirths' => 2]), $headers);
        self::assertSame(409, $status, 'fewer rebirths cannot overwrite');
        [$status, $body] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->runPayload(['timeSeconds' => 600, 'rebirths' => 3, 'version' => 999]), $headers);
        self::assertSame(409, $status);
        self::assertSame(1, $body['version']);

        [$status, $body] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->runPayload(['timeSeconds' => 700, 'rebirths' => 3, 'version' => 1]), $headers);
        self::assertSame(200, $status);
        self::assertSame(2, $body['version'], 'each update bumps the version');

        // Optimistic lock: an entity loaded before a concurrent committed
        // update (simulated with raw SQL) cannot be flushed over it.
        $em = $this->em();
        $stale = $em->getRepository(Player::class)->find($run['id']);
        $em->getConnection()->executeStatement('UPDATE player SET version = version + 1, name = ? WHERE id = ?', ['Concurrent', $run['id']]);
        $stale->setName('Overwrite');
        $this->expectException(OptimisticLockException::class);
        $em->flush();
    }

    public function testOwnerAndAdminDeletion(): void
    {
        $a = $this->create(['name' => 'Alice']);
        $b = $this->create(['name' => 'Bob']);

        [$status] = $this->api('DELETE', '/api/leaderboard/' . $b['id'], null, ['X-Edit-Token' => $a['editToken']]);
        self::assertSame(403, $status);
        [$status] = $this->api('DELETE', '/api/leaderboard/' . $a['id'], null, ['X-Edit-Token' => $a['editToken']]);
        self::assertSame(204, $status);

        [$status] = $this->api('DELETE', '/api/leaderboard/' . $b['id']);
        self::assertSame(403, $status, 'anonymous delete refused');
        [$status] = $this->api('DELETE', '/api/leaderboard/' . $b['id'], null, ['X-Admin-Token' => 'wrong']);
        self::assertSame(403, $status);
        [$status] = $this->api('DELETE', '/api/leaderboard/' . $b['id'], null, ['X-Admin-Token' => 'test-admin-token-0123456789abcdef0123']);
        self::assertSame(204, $status);
        self::assertSame(0, $this->em()->getRepository(Player::class)->count([]));
    }

    public function testAdminTokenFailsClosedWhenWeakAndIsBruteForceLimited(): void
    {
        $run = $this->create();
        $saved = [$_SERVER['ADMIN_TOKEN'] ?? null, $_ENV['ADMIN_TOKEN'] ?? null];
        try {
            $_SERVER['ADMIN_TOKEN'] = $_ENV['ADMIN_TOKEN'] = 'short';
            [$status, $body, $response] = $this->api('DELETE', '/api/leaderboard/' . $run['id'], null, ['X-Admin-Token' => 'short'], '10.0.0.50');
            self::assertSame(403, $status, 'too-short ADMIN_TOKEN is treated as not configured');
            self::assertStringNotContainsString('short', (string) $response->getContent());
        } finally {
            [$_SERVER['ADMIN_TOKEN'], $_ENV['ADMIN_TOKEN']] = $saved;
        }

        for ($i = 0; $i < 4; ++$i) {
            [$status] = $this->api('DELETE', '/api/leaderboard/' . $run['id'], null, ['X-Admin-Token' => 'guess' . $i], '10.0.0.50');
            self::assertSame(403, $status);
        }
        [$status, , $response] = $this->api('DELETE', '/api/leaderboard/' . $run['id'], null, ['X-Admin-Token' => 'test-admin-token-0123456789abcdef0123'], '10.0.0.50');
        self::assertSame(429, $status, 'locked out even with the right token');
        self::assertNotNull($response->headers->get('Retry-After'));
    }

    public function testPostIsRateLimitedPerClient(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->create(['name' => 'Spam' . $i], [], '10.0.0.77');
        }
        [$status] = $this->api('POST', '/api/leaderboard', $this->runPayload(), [], '10.0.0.77');
        self::assertSame(429, $status);
        self::assertSame(10, $this->em()->getRepository(Player::class)->count([]));
        $this->create(['name' => 'Other'], [], '10.0.0.78');
    }

    public function testHealthAndDisabledApiPlatformSurface(): void
    {
        [$status, $body] = $this->api('GET', '/api/health');
        self::assertSame(200, $status);
        self::assertSame(['status' => 'ok'], $body);

        foreach (['/api/graphql', '/api/docs', '/api/docs.json', '/api/docs.jsonopenapi', '/api', '/api/graphql/graphiql', '/api/contexts/Entrypoint.jsonld'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $uri);
        }
    }

    public function testCorsAllowsOnlyExactOrigins(): void
    {
        $this->client->request('OPTIONS', '/api/leaderboard', [], [], [
            'HTTP_ORIGIN' => 'https://portfolio.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, x-edit-token',
        ]);
        $response = $this->client->getResponse();
        self::assertSame('https://portfolio.example.test', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsStringIgnoringCase('x-edit-token', (string) $response->headers->get('Access-Control-Allow-Headers'));

        $this->client->request('OPTIONS', '/api/leaderboard', [], [], [
            'HTTP_ORIGIN' => 'https://portfolio.example.test.evil.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
        ]);
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
