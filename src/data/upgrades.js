// Base cost/output for the 15 upgrade tiers. Display names come from the
// `game.upgradeNames` i18n array (same index) so they stay translatable.
// Tuned so payback time (price / cps) grows gently, from ~10s (tier 1) to
// ~40min (tier 15), instead of the old curve which reached 27+ hours.
export const UPGRADE_BASE = [
  { cps: 1, price: 10 },
  { cps: 4, price: 75 },
  { cps: 15, price: 400 },
  { cps: 60, price: 2500 },
  { cps: 250, price: 15000 },
  { cps: 1100, price: 100000 },
  { cps: 5000, price: 700000 },
  { cps: 25000, price: 5_000_000 },
  { cps: 120000, price: 35_000_000 },
  { cps: 600000, price: 250_000_000 },
  { cps: 3_000_000, price: 1_800_000_000 },
  { cps: 15_000_000, price: 13_000_000_000 },
  { cps: 75_000_000, price: 95_000_000_000 },
  { cps: 400_000_000, price: 700_000_000_000 },
  { cps: 2_200_000_000, price: 5_000_000_000_000 },
]

export const UPGRADE_MULTIPLIER = 1.15
// First rebirth now reachable in a few minutes of active play (the
// "speed_first_rebirth" trophy targets under 90s), instead of requiring
// nearly the full upgrade tree maxed out.
export const REBIRTH_BASE_PRICE = 20_000
