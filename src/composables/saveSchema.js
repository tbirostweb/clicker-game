// Versioned schema + validation for the localStorage save. Pure functions
// (no Vue) so they can be unit tested with `node --test`.
//
// The save is local progress the player can edit freely: validation keeps
// the game from crashing or misbehaving on a corrupted/partial/old save, it
// is NOT an anti-cheat.

export const SAVE_SCHEMA_VERSION = 2
export const MAX_NAME_LENGTH = 20
const EDIT_TOKEN_PATTERN = /^[a-f0-9]{64}$/
const IDEMPOTENCY_KEY_PATTERN = /^[A-Za-z0-9-]{16,128}$/

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
const finiteNonNegative = (v, fallback = 0) =>
  typeof v === 'number' && Number.isFinite(v) && v >= 0 ? v : fallback
const nonNegativeInt = (v, fallback = 0) =>
  Number.isSafeInteger(v) && v >= 0 ? v : fallback
const bool = (v, fallback = false) => (typeof v === 'boolean' ? v : fallback)

export function freshUpgradesFrom(upgradeBase) {
  return upgradeBase.map((base) => ({ level: 0, price: base.price }))
}

function sanitizeUpgrades(raw, upgradeBase, multiplier) {
  if (!Array.isArray(raw) || raw.length !== upgradeBase.length) return freshUpgradesFrom(upgradeBase)
  return raw.map((u, i) => {
    const level = nonNegativeInt(u?.level, 0)
    const price = typeof u?.price === 'number' && Number.isFinite(u.price) && u.price > 0
      ? u.price
      : Math.floor(upgradeBase[i].price * multiplier ** level) || upgradeBase[i].price
    return { level, price }
  })
}

/**
 * Returns a complete, well-typed save object from whatever was stored
 * (null if nothing usable). Unknown fields are dropped, invalid ones reset
 * to their default, so a partial or tampered save never breaks the game.
 */
export function sanitizeSave(data, { upgradeBase, multiplier = 1.15, knownAchievementIds = null }) {
  if (!isPlainObject(data)) return null

  const known = knownAchievementIds ? new Set(knownAchievementIds) : null
  const unlockedIds = Array.isArray(data.unlockedIds)
    ? [...new Set(data.unlockedIds.filter((id) => typeof id === 'string' && (!known || known.has(id))))]
    : []

  const runId = nonNegativeInt(data.leaderboardRunId, null)
  const editToken = typeof data.leaderboardEditToken === 'string' && EDIT_TOKEN_PATTERN.test(data.leaderboardEditToken)
    ? data.leaderboardEditToken
    : null
  // A pending Idempotency-Key only matters while a creation is
  // unacknowledged: once the run id and its edit token are known, the key has
  // served its purpose and is dropped (the server forgets it after 24 h).
  const pendingKey = typeof data.leaderboardPendingKey === 'string' && IDEMPOTENCY_KEY_PATTERN.test(data.leaderboardPendingKey)
    && !(nonNegativeInt(data.leaderboardRunId, 0) > 0 && editToken)
    ? data.leaderboardPendingKey
    : null
  const rank = nonNegativeInt(data.leaderboardRank, null)

  const sessionElapsedSeconds = nonNegativeInt(data.sessionElapsedSeconds, 0)

  return {
    schemaVersion: SAVE_SCHEMA_VERSION,
    counter: finiteNonNegative(data.counter),
    totalEarned: finiteNonNegative(data.totalEarned),
    totalSpent: finiteNonNegative(data.totalSpent),
    totalClicks: nonNegativeInt(data.totalClicks),
    upgrades: sanitizeUpgrades(data.upgrades, upgradeBase, multiplier),
    rebirth: nonNegativeInt(data.rebirth),
    rebirthTimestamps: Array.isArray(data.rebirthTimestamps)
      ? data.rebirthTimestamps.filter((t) => typeof t === 'number' && Number.isFinite(t) && t >= 0)
      : [],
    gameStarted: bool(data.gameStarted),
    sessionElapsedSeconds,
    activePlaySeconds: Math.min(nonNegativeInt(data.activePlaySeconds), sessionElapsedSeconds),
    unlockedIds,
    leaderboardSubmitted: bool(data.leaderboardSubmitted),
    leaderboardRunId: runId === 0 ? null : runId,
    // v1 saves have no token: their run can no longer be edited, a new one
    // is created on the next submission.
    leaderboardEditToken: editToken,
    leaderboardPendingKey: pendingKey,
    leaderboardPlayerName: typeof data.leaderboardPlayerName === 'string'
      ? data.leaderboardPlayerName.slice(0, MAX_NAME_LENGTH)
      : '',
    leaderboardRank: rank === 0 ? null : rank,
    konamiUnlocked: bool(data.konamiUnlocked),
  }
}
