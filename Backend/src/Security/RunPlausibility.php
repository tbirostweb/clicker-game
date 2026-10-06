<?php

namespace App\Security;

/**
 * Plausibility guard-rails for self-declared leaderboard runs.
 *
 * NOT an anti-cheat (the game runs entirely in the browser): it only rejects
 * values that the real game (src/data/upgrades.js, src/data/achievements.js,
 * src/composables/useGameState.js) cannot produce, with a wide margin so a
 * legitimate player is never refused.
 *
 * Score model (upper bound of the real game):
 *  - passive income per tick = baseCps x cpsBonus, where baseCps can never
 *    exceed the best cps purchasable with the money earned so far
 *    (fractional knapsack over every upgrade level, sorted by cps/price);
 *  - click income = clicks/s x (1 + 3 x rebirths) x clickBonus;
 *  - bonuses are taken at their maximum (every trophy unlocked).
 * The minimum time needed to go from score A to score B is the integral of
 * dS / maxRate(S); a run is refused only when the declared time, multiplied
 * by SAFETY_FACTOR and extended by SLACK_SECONDS, is still shorter.
 *
 * Keep GAME_* constants in sync with the front (a test checks it).
 */
final class RunPlausibility
{
    // --- Game data mirrored from src/data/*.js ---
    /** @var list<array{int, int}> [cps, price] per tier (src/data/upgrades.js UPGRADE_BASE) */
    public const GAME_UPGRADES = [
        [1, 10], [4, 75], [15, 400], [60, 2500], [250, 15000],
        [1100, 100000], [5000, 700000], [25000, 5_000_000], [120000, 35_000_000],
        [600000, 250_000_000], [3_000_000, 1_800_000_000], [15_000_000, 13_000_000_000],
        [75_000_000, 95_000_000_000], [400_000_000, 700_000_000_000], [2_200_000_000, 5_000_000_000_000],
    ];
    public const GAME_UPGRADE_MULTIPLIER = 1.15;
    public const GAME_REBIRTH_BASE_PRICE = 20_000;
    // src/data/achievements.js: number of achievements, and 1 + sum of every
    // click / cps reward ('both' counts for both).
    public const GAME_ACHIEVEMENT_COUNT = 54;
    public const GAME_MAX_CLICK_BONUS = 2.45;
    public const GAME_MAX_CPS_BONUS = 2.35;

    // --- Margins (deliberately generous) ---
    // Far above human clicking (~10-15/s); with SAFETY_FACTOR this tolerates
    // ~1000 clicks/s.
    public const MAX_CLICKS_PER_SECOND = 100;
    // Declared time may be 10x shorter than the theoretical minimum.
    public const SAFETY_FACTOR = 10;
    // Extra seconds granted on every check (latency, tick rounding).
    public const SLACK_SECONDS = 120;
    // PUT: playtime gained since the last accepted update may exceed the
    // wall-clock time elapsed on the server by 10% + 5 minutes.
    public const WALL_CLOCK_RATIO = 1.1;
    public const WALL_CLOCK_MARGIN_SECONDS = 300;
    // Rebirths: cumulative rebirth prices must be covered by the cumulative
    // money earned; only half is required (margin for old saves/rounding).
    public const REBIRTH_COST_TOLERANCE = 2;

    private const PRICE_CEILING = 1e17; // > Number.MAX_SAFE_INTEGER
    private const GRID_FACTOR = 1.02;

    /** @var array{0: list<float>, 1: list<float>, 2: list<float>}|null prefix cost, prefix cps, ratio */
    private static ?array $items = null;

    /**
     * Returns an error message when the run cannot come from the real game,
     * null when it is plausible. $previous holds the last accepted values of
     * the same run (PUT) or null (POST).
     *
     * @param array{score: int, rebirths: int, timeSeconds: int, activeSeconds: int, trophies: int} $run
     * @param array{score: int, rebirths: int, timeSeconds: int}|null $previous
     */
    public static function check(array $run, ?array $previous = null, ?int $wallClockSeconds = null): ?string
    {
        if ($run['activeSeconds'] > $run['timeSeconds']) {
            return 'activeSeconds cannot exceed timeSeconds';
        }
        if ($run['trophies'] > self::GAME_ACHIEVEMENT_COUNT) {
            return 'trophies exceed the number of achievements';
        }
        if (self::rebirthCost($run['rebirths']) > $run['score'] * self::REBIRTH_COST_TOLERANCE) {
            return 'rebirths not covered by score';
        }

        $fromScore = $previous['score'] ?? 0;
        $fromRebirths = $previous['rebirths'] ?? 0;
        $deltaTime = $run['timeSeconds'] - ($previous['timeSeconds'] ?? 0);

        if (null !== $previous && null !== $wallClockSeconds
            && $deltaTime > max(0, $wallClockSeconds) * self::WALL_CLOCK_RATIO + self::WALL_CLOCK_MARGIN_SECONDS) {
            return 'timeSeconds grew faster than real time';
        }

        $budget = self::SAFETY_FACTOR * (max(0, $deltaTime) + self::SLACK_SECONDS);
        if ($run['rebirths'] - $fromRebirths > $budget * self::MAX_CLICKS_PER_SECOND) {
            return 'rebirths grew faster than possible';
        }
        if ($run['score'] > $fromScore
            && self::minimumSeconds($fromScore, $run['score'], $run['rebirths']) > $budget) {
            return 'score grew faster than possible';
        }

        return null;
    }

    /** Sum of the prices of rebirths 1..$rebirths ((r + 1) x base each). */
    public static function rebirthCost(int $rebirths): float
    {
        return self::GAME_REBIRTH_BASE_PRICE * $rebirths * ($rebirths + 1) / 2;
    }

    /** Upper bound of the income per second once $score money was earned. */
    public static function maxIncomePerSecond(float $score, int $rebirths): float
    {
        return self::GAME_MAX_CPS_BONUS * self::maxBaseCps($score)
            + self::MAX_CLICKS_PER_SECOND * (1 + 3 * $rebirths) * self::GAME_MAX_CLICK_BONUS;
    }

    /** Lower bound of the seconds needed to earn from $from to $to. */
    public static function minimumSeconds(float $from, float $to, int $rebirths): float
    {
        if ($to <= $from) {
            return 0.0;
        }
        // The income rate only grows with the score: evaluating it at the
        // upper end of each step under-estimates the time (safe side).
        $low = max($from, 1.0);
        $seconds = $low > $from ? ($low - $from) / self::maxIncomePerSecond($low, $rebirths) : 0.0;
        while ($low < $to) {
            $high = min($to, $low * self::GRID_FACTOR);
            $seconds += ($high - $low) / self::maxIncomePerSecond($high, $rebirths);
            $low = $high;
        }

        return $seconds;
    }

    /** Best total base cps that $budget money can buy (fractional knapsack). */
    public static function maxBaseCps(float $budget): float
    {
        [$prefixCost, $prefixCps, $ratio] = self::items();
        // Largest k with prefixCost[k] <= budget (prefixCost[0] = 0).
        $lo = 0;
        $hi = count($prefixCost) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($prefixCost[$mid] <= $budget) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $cps = $prefixCps[$lo];
        if (isset($ratio[$lo])) {
            $cps += ($budget - $prefixCost[$lo]) * $ratio[$lo];
        }

        return $cps;
    }

    /** @return array{0: list<float>, 1: list<float>, 2: list<float>} */
    private static function items(): array
    {
        if (null !== self::$items) {
            return self::$items;
        }
        $levels = [];
        foreach (self::GAME_UPGRADES as [$cps, $price]) {
            // Same price progression as the front: floor(price x 1.15) per level.
            $p = (float) $price;
            while ($p < self::PRICE_CEILING) {
                $levels[] = [$cps / $p, $p, (float) $cps];
                $p = floor($p * self::GAME_UPGRADE_MULTIPLIER);
            }
        }
        usort($levels, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        $prefixCost = [0.0];
        $prefixCps = [0.0];
        $ratio = [];
        foreach ($levels as $i => [$r, $p, $c]) {
            $ratio[$i] = $r;
            $prefixCost[] = $prefixCost[$i] + $p;
            $prefixCps[] = $prefixCps[$i] + $c;
        }

        return self::$items = [$prefixCost, $prefixCps, $ratio];
    }
}
