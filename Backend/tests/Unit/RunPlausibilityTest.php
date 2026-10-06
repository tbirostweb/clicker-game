<?php

namespace App\Tests\Unit;

use App\Controller\PlayerController;
use App\Security\NameModeration;
use App\Security\RunPlausibility;
use App\Tests\Support\RunPlausibilitySimulation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunPlausibilityTest extends TestCase
{
    /** @return iterable<string, array{int, bool}> */
    public static function strategies(): iterable
    {
        yield 'human clicks, no rebirth' => [15, false];
        yield 'autoclicker 100/s with rebirths' => [100, true];
        yield 'autoclicker 1000/s with rebirths' => [1000, true];
    }

    /**
     * 8 hours of aggressive play: every POST (any moment) and every 5-minute
     * PUT must be accepted, with a comfortable margin left.
     */
    #[DataProvider('strategies')]
    public function testSimulatedLegitimatePlayIsAlwaysPlausible(int $clicks, bool $rebirths): void
    {
        $previous = null;
        $worstRatio = 0.0;
        foreach (RunPlausibilitySimulation::play(8 * 3600, $clicks, 300, 300, $rebirths) as $point) {
            self::assertNull(RunPlausibility::check($point), 'POST ' . json_encode($point));
            if (null !== $previous) {
                self::assertNull(RunPlausibility::check($point, $previous, 300), 'PUT ' . json_encode($point));
            }
            $needed = RunPlausibility::minimumSeconds(0, $point['score'], $point['rebirths']);
            $worstRatio = max($worstRatio, $needed / ($point['timeSeconds'] + RunPlausibility::SLACK_SECONDS));
            $previous = $point;
        }
        // The simulated player never uses more than 1/10 of the tolerance.
        self::assertLessThan(RunPlausibility::SAFETY_FACTOR / 10, $worstRatio);
    }

    public function testObviouslyForgedRunsAreRejected(): void
    {
        $run = ['score' => 9_000_000_000_000_000, 'rebirths' => 0, 'timeSeconds' => 3600, 'activeSeconds' => 3600, 'trophies' => 10];
        self::assertNotNull(RunPlausibility::check($run), 'max score in one hour');
        self::assertNull(RunPlausibility::check(['timeSeconds' => 8 * 3600, 'activeSeconds' => 8 * 3600] + $run), 'max score in 8 hours is possible');
        self::assertNotNull(RunPlausibility::check(['activeSeconds' => 3601] + $run));
        self::assertNotNull(RunPlausibility::check(['trophies' => RunPlausibility::GAME_ACHIEVEMENT_COUNT + 1, 'timeSeconds' => 99999, 'activeSeconds' => 1] + $run));

        $previous = ['score' => 1_000_000, 'rebirths' => 2, 'timeSeconds' => 600];
        $next = ['score' => 2_000_000, 'rebirths' => 2, 'timeSeconds' => 900, 'activeSeconds' => 900, 'trophies' => 10];
        self::assertNull(RunPlausibility::check($next, $previous, 300));
        self::assertNull(RunPlausibility::check($next, $previous, null), 'legacy run without updated_at');
        self::assertNotNull(RunPlausibility::check(['timeSeconds' => 600 + 4000] + $next, $previous, 300), 'more playtime than real time');
    }

    /** The PHP copy of the game data must match the front (src/data/*.js). */
    public function testGameConstantsMatchTheFront(): void
    {
        $data = dirname(__DIR__, 3) . '/src/data';
        if (!is_file($data . '/upgrades.js') || !is_file($data . '/achievements.js')) {
            self::markTestSkipped('front sources not available');
        }

        $upgrades = (string) file_get_contents($data . '/upgrades.js');
        preg_match_all('/\{\s*cps:\s*([\d_]+),\s*price:\s*([\d_]+)\s*\}/', $upgrades, $m, \PREG_SET_ORDER);
        $front = array_map(static fn (array $row) => [(int) str_replace('_', '', $row[1]), (int) str_replace('_', '', $row[2])], $m);
        self::assertSame(RunPlausibility::GAME_UPGRADES, $front);
        self::assertMatchesRegularExpression('/UPGRADE_MULTIPLIER\s*=\s*1\.15\b/', $upgrades);
        self::assertMatchesRegularExpression('/REBIRTH_BASE_PRICE\s*=\s*20_000\b/', $upgrades);

        $achievements = (string) file_get_contents($data . '/achievements.js');
        self::assertSame(RunPlausibility::GAME_ACHIEVEMENT_COUNT, preg_match_all("/\{\s*id:\s*'/", $achievements));
        $click = 1.0;
        $cps = 1.0;
        preg_match_all("/reward:\s*\{\s*type:\s*'(click|cps|both)',\s*value:\s*([\d.]+)/", $achievements, $rewards, \PREG_SET_ORDER);
        foreach ($rewards as [, $type, $value]) {
            $click += 'cps' !== $type ? (float) $value : 0.0;
            $cps += 'click' !== $type ? (float) $value : 0.0;
        }
        self::assertEqualsWithDelta(RunPlausibility::GAME_MAX_CLICK_BONUS, $click, 1e-9);
        self::assertEqualsWithDelta(RunPlausibility::GAME_MAX_CPS_BONUS, $cps, 1e-9);
    }

    public function testAdminTokenStrength(): void
    {
        self::assertTrue(PlayerController::adminTokenIsStrong(bin2hex(random_bytes(16))));
        self::assertTrue(PlayerController::adminTokenIsStrong(bin2hex(random_bytes(32))));
        self::assertTrue(PlayerController::adminTokenIsStrong(rtrim(base64_encode(random_bytes(32)), '=')));
        self::assertFalse(PlayerController::adminTokenIsStrong('0123456789ab'), 'too short');
        self::assertFalse(PlayerController::adminTokenIsStrong(str_repeat('a', 64)), 'no entropy');
        self::assertFalse(PlayerController::adminTokenIsStrong(str_repeat('ab', 20)), 'low entropy');
        self::assertFalse(PlayerController::adminTokenIsStrong('correct horse battery staple and more words'), 'not hex/base64');
    }

    public function testNameModeration(): void
    {
        foreach (['Alice', 'Conrad', 'Technique', 'Habitué', 'Scunthorpe', 'Bob_93', 'Élodie'] as $ok) {
            self::assertTrue(NameModeration::isAllowed($ok), $ok);
        }
        foreach (['connard', 'C0NN4RD', 'Sal.o.pe', 'gros con', 'FUCK', 'ｆｕｃｋ', 'Enculé'] as $banned) {
            self::assertFalse(NameModeration::isAllowed($banned), $banned);
        }
        self::assertSame('Alice', NameModeration::normalize('  Ａｌｉｃｅ '));
    }
}
