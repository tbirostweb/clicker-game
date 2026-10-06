<?php

namespace App\Tests\Functional;

use App\Entity\Player;
use App\Tests\Support\RunPlausibilitySimulation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression tests for the 2026-10-06 security audit fixes (F1-F7).
 */
final class LeaderboardHardeningTest extends WebTestCase
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
        foreach (['cache.app', 'cache.rate_limiter', 'leaderboard.cache'] as $pool) {
            static::getContainer()->get('cache.global_clearer')->clearPool($pool);
        }
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Alice', 'rebirths' => 1, 'score' => 25000, 'timeSeconds' => 120, 'activeSeconds' => 100, 'trophies' => 3];
    }

    private function api(string $method, string $uri, ?array $body = null, array $headers = [], string $ip = '10.1.0.1', array $server = []): array
    {
        $server += ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->client->request($method, $uri, [], [], $server, null === $body ? null : json_encode($body));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function create(array $overrides = [], string $ip = '10.1.0.1'): array
    {
        [$status, $body] = $this->api('POST', '/api/leaderboard', $this->payload($overrides), [], $ip);
        self::assertSame(201, $status, json_encode($body));

        return $body;
    }

    /** Moves the last accepted update of a run back in time. */
    private function ageRun(int $id, int $seconds): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE player SET updated_at = ? WHERE id = ?',
            [(new \DateTimeImmutable(sprintf('-%d seconds', $seconds)))->format('Y-m-d H:i:s'), $id]
        );
        $this->em()->clear();
    }

    // --- F3: trusted proxies ---------------------------------------------

    public function testForgedForwardedForFromUntrustedPeerIsIgnored(): void
    {
        // TRUSTED_PROXIES=127.0.0.1 in phpunit.dist.xml.
        $this->api('GET', '/api/leaderboard', null, [], '203.0.113.7', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        self::assertSame('203.0.113.7', $this->client->getRequest()->getClientIp(), 'untrusted peer: REMOTE_ADDR wins');

        // Rotating forged X-Forwarded-For values does not escape the limit.
        for ($i = 0; $i < 10; ++$i) {
            [$status] = $this->api('POST', '/api/leaderboard', $this->payload(['name' => 'Forge' . $i]), [], '203.0.113.7', ['HTTP_X_FORWARDED_FOR' => '198.51.100.' . $i]);
            self::assertSame(201, $status);
        }
        [$status] = $this->api('POST', '/api/leaderboard', $this->payload(), [], '203.0.113.7', ['HTTP_X_FORWARDED_FOR' => '198.51.100.99']);
        self::assertSame(429, $status);

        // The trusted proxy itself may forward the real client address.
        $this->api('GET', '/api/leaderboard', null, [], '127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        self::assertSame('198.51.100.1', $this->client->getRequest()->getClientIp());
    }

    // --- F2: rate limiting -------------------------------------------------

    public function testConcurrentPostsFromOneIpCreateAtMostTen(): void
    {
        $script = dirname(__DIR__) . '/Support/post_once.php';
        $barrier = sys_get_temp_dir() . '/clicker-barrier-' . bin2hex(random_bytes(6));
        $env = ['PATH' => (string) getenv('PATH')];
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_SECRET', 'DEFAULT_URI', 'TRUSTED_PROXIES', 'DATABASE_URL', 'CORS_ALLOW_ORIGIN', 'ADMIN_TOKEN', 'KERNEL_CLASS'] as $name) {
            $env[$name] = (string) $_SERVER[$name];
        }

        $processes = [];
        for ($i = 0; $i < 30; ++$i) {
            $body = json_encode($this->payload(['name' => 'Rush' . $i]));
            $cmd = [\PHP_BINARY, $script, $barrier, '10.9.9.9', $body];
            $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        usleep(1_500_000); // let every process boot its kernel
        touch($barrier);

        $statuses = [];
        foreach ($processes as [$process, $pipes]) {
            $statuses[] = (int) trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        @unlink($barrier);

        $counts = array_count_values($statuses);
        self::assertCount(30, $statuses);
        self::assertLessThanOrEqual(10, $counts[201] ?? 0, json_encode($counts));
        self::assertGreaterThanOrEqual(20, $counts[429] ?? 0, json_encode($counts));
        self::assertLessThanOrEqual(10, $this->em()->getRepository(Player::class)->count([]));
    }

    public function testIpv6ClientsAreAggregatedPer64(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->create(['name' => 'Six' . $i], '2001:db8:1:2::' . dechex($i));
        }
        [$status] = $this->api('POST', '/api/leaderboard', $this->payload(), [], '2001:db8:1:2:ffff:ffff:ffff:ffff');
        self::assertSame(429, $status, 'same /64');
        $this->create(['name' => 'Other64'], '2001:db8:1:3::1');
    }

    public function testGlobalHourlyPostQuota(): void
    {
        static::getContainer()->get('limiter.leaderboard_post_global')->create('global')->consume(500);
        [$status] = $this->api('POST', '/api/leaderboard', $this->payload(), [], '10.1.2.3');
        self::assertSame(429, $status);
    }

    public function testLeaderboardGetIsCachedRateLimitedAndInvalidatedOnWrite(): void
    {
        $this->create(['name' => 'First']);
        [, $list] = $this->api('GET', '/api/leaderboard');
        self::assertCount(1, $list);

        // A row added behind the API is not visible until the cache expires...
        $em = $this->em();
        $em->persist((new Player())->setName('Direct')->setRebirth(0)->setScore(1)->setTimeSeconds(5)->setActiveSeconds(1)->setTrophyCount(0));
        $em->flush();
        [, $list] = $this->api('GET', '/api/leaderboard');
        self::assertCount(1, $list, 'served from the short cache');

        // ...but any write through the API invalidates it immediately.
        $this->create(['name' => 'Second'], '10.1.0.2');
        [, $list] = $this->api('GET', '/api/leaderboard');
        self::assertCount(3, $list);

        for ($i = 0; $i < 117; ++$i) {
            [$status] = $this->api('GET', '/api/leaderboard', null, [], '10.1.0.200');
            self::assertSame(200, $status);
        }
        for ($i = 0; $i < 3; ++$i) {
            $this->api('GET', '/api/leaderboard', null, [], '10.1.0.200');
        }
        [$status] = $this->api('GET', '/api/leaderboard', null, [], '10.1.0.200');
        self::assertSame(429, $status);
    }

    public function testPurgeCommandOnlyRemovesInactiveNonVisibleRuns(): void
    {
        $em = $this->em();
        for ($i = 0; $i < 105; ++$i) {
            $em->persist((new Player())->setName('P' . $i)->setRebirth($i)->setScore($i)->setTimeSeconds($i + 1)->setActiveSeconds($i)->setTrophyCount(min($i, 54))
                ->setUpdatedAt(new \DateTimeImmutable('-400 days')));
        }
        $em->flush();
        $em->clear();
        // trophies are capped at 54, so make the five lowest runs clearly last everywhere.
        $em->getConnection()->executeStatement("UPDATE player SET trophy_count = 0 WHERE name IN ('P0','P1','P2','P3','P4')");

        $tester = new CommandTester((new Application(static::$kernel))->find('app:leaderboard:purge-inactive'));
        $tester->execute(['--days' => '365']);
        self::assertSame(105, $this->em()->getRepository(Player::class)->count([]), 'dry run by default');

        $tester->execute(['--days' => '365', '--force' => true]);
        self::assertSame(0, $tester->getStatusCode());
        $this->em()->clear();
        $names = array_map(static fn (Player $p) => $p->getName(), $this->em()->getRepository(Player::class)->findAll());
        self::assertCount(100, $names);
        foreach (['P0', 'P1', 'P2', 'P3', 'P4'] as $gone) {
            self::assertNotContains($gone, $names);
        }
    }

    // --- F1: plausibility --------------------------------------------------

    public function testPutAddingMorePlaytimeThanElapsedRealTimeIsRejected(): void
    {
        $run = $this->create();
        // Run just created: claiming 2 more hours of playtime is impossible.
        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->payload(['timeSeconds' => 120 + 7200, 'activeSeconds' => 100]), ['X-Edit-Token' => $run['editToken']]);
        self::assertSame(422, $status);

        // Two real hours later the same update is accepted.
        $this->ageRun($run['id'], 7200);
        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->payload(['timeSeconds' => 120 + 7200, 'activeSeconds' => 100]), ['X-Edit-Token' => $run['editToken']]);
        self::assertSame(200, $status);
    }

    public function testPutWithImpossibleScoreGainIsRejected(): void
    {
        $run = $this->create();
        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->payload(['timeSeconds' => 130, 'score' => 9_000_000_000_000_000]), ['X-Edit-Token' => $run['editToken']]);
        self::assertSame(422, $status);
        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->payload(['timeSeconds' => 130, 'rebirths' => 500, 'score' => 30000]), ['X-Edit-Token' => $run['editToken']]);
        self::assertSame(422, $status, 'rebirths not paid by score');

        $this->em()->clear();
        $stored = $this->em()->getRepository(Player::class)->find($run['id']);
        self::assertSame(25000, $stored->getScore(), 'nothing persisted');
    }

    /**
     * A fast legitimate player (aggressive greedy strategy, 20 clicks/s,
     * every trophy bonus) submits once, then the client auto-updates every
     * 5 minutes for 3 hours: every request must be accepted.
     */
    public function testLegitimateFastRunIsNeverRefused(): void
    {
        $trajectory = RunPlausibilitySimulation::play(3 * 3600, 20, 300);
        $first = array_shift($trajectory);
        $run = $this->create(['name' => 'Speedy'] + $first);
        foreach ($trajectory as $i => $point) {
            $this->ageRun($run['id'], 300);
            // Real clients send one PUT per 5 minutes; here they are replayed
            // back to back, so each comes from its own IP (PUT rate limit).
            [$status, $body] = $this->api('PUT', '/api/leaderboard/' . $run['id'], ['name' => 'Speedy'] + $point, ['X-Edit-Token' => $run['editToken']], '10.2.0.' . ($i + 1));
            self::assertSame(200, $status, json_encode([$point, $body]));
        }
        self::assertGreaterThan(100_000_000, $this->em()->getRepository(Player::class)->find($run['id'])->getScore());
    }

    // --- F4: names ---------------------------------------------------------

    public function testNamesAreNfkcNormalizedAndModerationSkipsUnchangedNames(): void
    {
        $run = $this->create(['name' => 'Ａｌｉｃｅ']);
        self::assertSame('Alice', $run['name']);
        $this->create(['name' => 'Conrad'], '10.1.0.3'); // no Scunthorpe false positive
        $this->create(['name' => 'Technique'], '10.1.0.4');

        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->payload(['name' => 'Fuck', 'timeSeconds' => 130]), ['X-Edit-Token' => $run['editToken']]);
        self::assertSame(422, $status, 'renaming to a banned name is refused');

        // A run stored before moderation existed keeps updating unchanged.
        $this->em()->getConnection()->executeStatement('UPDATE player SET name = ? WHERE id = ?', ['Connard', $run['id']]);
        $this->em()->clear();
        [$status] = $this->api('PUT', '/api/leaderboard/' . $run['id'], $this->payload(['name' => 'Connard', 'timeSeconds' => 130]), ['X-Edit-Token' => $run['editToken']]);
        self::assertSame(200, $status);
    }

    // --- F6: indexes -------------------------------------------------------

    public function testTopRunsQueriesUseAnIndexInsteadOfSorting(): void
    {
        $connection = $this->em()->getConnection();
        foreach (['active', 'rebirths', 'score', 'trophies'] as $sort) {
            $sql = $this->em()->getRepository(Player::class)->topRunsQuery(20, $sort)->getSQL();
            $plan = implode("\n", array_column($connection->fetchAllAssociative('EXPLAIN QUERY PLAN ' . $sql), 'detail'));
            self::assertStringNotContainsString('TEMP B-TREE', $plan, $sort . ': ' . $plan);
            self::assertStringContainsString('USING INDEX idx_player_', $plan, $sort . ': ' . $plan);
        }
    }
}
