<?php

namespace App\Tests\Support;

use App\Security\RunPlausibility;

/**
 * Re-implementation of the browser game loop (src/composables/useGameState.js)
 * played by an aggressive player, used to prove that the plausibility
 * guard-rails never refuse a legitimate run.
 *
 * Worst case on purpose: every trophy bonus is active from the first second,
 * the player buys the best cps/price upgrade as soon as it is affordable and
 * rebirths whenever the rebirth costs under 2% of the money earned so far.
 */
final class RunPlausibilitySimulation
{
    /**
     * One point every $interval seconds, starting at $firstAt.
     *
     * @return list<array{score: int, rebirths: int, timeSeconds: int, activeSeconds: int, trophies: int}>
     */
    public static function play(int $seconds, int $clicksPerSecond, int $interval, int $firstAt = 600, bool $rebirths = true): array
    {
        $upgrades = RunPlausibility::GAME_UPGRADES;
        $fresh = static fn (): array => [array_fill(0, count($upgrades), 0), array_map(static fn (array $u) => (float) $u[1], $upgrades)];
        [$levels, $prices] = $fresh();
        $counter = 0.0;
        $earned = 0.0;
        $rebirth = 0;
        $points = [];

        for ($t = 1; $t <= $seconds; ++$t) {
            $cps = 0;
            foreach ($upgrades as $i => [$c]) {
                $cps += $levels[$i] * $c;
            }
            $income = $cps * RunPlausibility::GAME_MAX_CPS_BONUS
                + $clicksPerSecond * (1 + 3 * $rebirth) * RunPlausibility::GAME_MAX_CLICK_BONUS;
            $counter += $income;
            $earned += $income;

            $rebirthPrice = ($rebirth + 1) * RunPlausibility::GAME_REBIRTH_BASE_PRICE;
            if ($rebirths && $counter >= $rebirthPrice && $rebirthPrice < $earned / 50) {
                ++$rebirth;
                $counter = 0.0;
                [$levels, $prices] = $fresh();
            }

            do {
                $best = -1;
                $bestRatio = 0.0;
                foreach ($upgrades as $i => [$c]) {
                    if ($prices[$i] <= $counter && $c / $prices[$i] > $bestRatio) {
                        $bestRatio = $c / $prices[$i];
                        $best = $i;
                    }
                }
                if ($best >= 0) {
                    $counter -= $prices[$best];
                    ++$levels[$best];
                    $prices[$best] = floor($prices[$best] * RunPlausibility::GAME_UPGRADE_MULTIPLIER);
                }
            } while ($best >= 0);

            if ($t >= $firstAt && 0 === ($t - $firstAt) % $interval) {
                $points[] = [
                    'score' => (int) min(floor($earned), 9_007_199_254_740_991),
                    'rebirths' => $rebirth,
                    'timeSeconds' => $t,
                    'activeSeconds' => $t,
                    'trophies' => 20,
                ];
            }
        }

        return $points;
    }
}
