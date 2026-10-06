import { test } from 'node:test'
import assert from 'node:assert/strict'
import { sanitizeSave, SAVE_SCHEMA_VERSION } from '../src/composables/saveSchema.js'
import { UPGRADE_BASE, UPGRADE_MULTIPLIER } from '../src/data/upgrades.js'

const opts = { upgradeBase: UPGRADE_BASE, multiplier: UPGRADE_MULTIPLIER, knownAchievementIds: ['click_1', 'rebirth_1'] }

test('returns null for missing or non-object saves', () => {
  for (const raw of [null, undefined, 42, 'x', [], true]) assert.equal(sanitizeSave(raw, opts), null)
})

test('a partial v1 save is completed with defaults', () => {
  const save = sanitizeSave({ counter: 50, rebirth: 2 }, opts)
  assert.equal(save.schemaVersion, SAVE_SCHEMA_VERSION)
  assert.equal(save.counter, 50)
  assert.equal(save.rebirth, 2)
  assert.equal(save.totalClicks, 0)
  assert.equal(save.upgrades.length, UPGRADE_BASE.length)
  assert.deepEqual(save.unlockedIds, [])
  assert.equal(save.leaderboardEditToken, null)
  assert.equal(save.gameStarted, false)
})

test('a full valid save keeps progress (no regression on restore)', () => {
  const upgrades = UPGRADE_BASE.map((b, i) => ({ level: i, price: b.price * 2 }))
  const token = 'a'.repeat(64)
  const input = {
    counter: 1234.5, totalEarned: 99999, totalSpent: 500, totalClicks: 321, upgrades,
    rebirth: 3, rebirthTimestamps: [60, 120], gameStarted: true, sessionElapsedSeconds: 600,
    activePlaySeconds: 500, unlockedIds: ['click_1', 'rebirth_1'], leaderboardSubmitted: true,
    leaderboardRunId: 7, leaderboardEditToken: token, leaderboardPlayerName: 'Alice', leaderboardRank: 2,
    konamiUnlocked: true,
  }
  const save = sanitizeSave(input, opts)
  for (const key of Object.keys(input)) assert.deepEqual(save[key], input[key], key)
})

test('corrupted / tampered fields are reset field by field', () => {
  const save = sanitizeSave({
    counter: 'lots', totalEarned: -5, totalClicks: 1.5, rebirth: Infinity,
    upgrades: [{ level: 1, price: 10 }], // wrong length
    rebirthTimestamps: 'nope', gameStarted: 'yes', sessionElapsedSeconds: 100, activePlaySeconds: 999,
    unlockedIds: ['click_1', 'click_1', 42, 'unknown_trophy'], leaderboardRunId: -3,
    leaderboardEditToken: '<script>', leaderboardPlayerName: 'x'.repeat(50), leaderboardRank: 'first',
    konamiUnlocked: 1,
  }, opts)
  assert.equal(save.counter, 0)
  assert.equal(save.totalEarned, 0)
  assert.equal(save.totalClicks, 0)
  assert.equal(save.rebirth, 0)
  assert.equal(save.upgrades.every((u) => u.level === 0), true)
  assert.deepEqual(save.rebirthTimestamps, [])
  assert.equal(save.gameStarted, false)
  assert.equal(save.activePlaySeconds, 100, 'active time capped to elapsed time')
  assert.deepEqual(save.unlockedIds, ['click_1'])
  assert.equal(save.leaderboardRunId, null)
  assert.equal(save.leaderboardEditToken, null)
  assert.equal(save.leaderboardPlayerName.length, 20)
  assert.equal(save.leaderboardRank, null)
  assert.equal(save.konamiUnlocked, false)
})

test('a non-finite upgrade price (serialized as null) is recomputed', () => {
  const upgrades = UPGRADE_BASE.map(() => ({ level: 2, price: null }))
  const save = sanitizeSave({ upgrades }, opts)
  save.upgrades.forEach((u, i) => {
    assert.equal(u.level, 2)
    assert.ok(Number.isFinite(u.price) && u.price >= UPGRADE_BASE[i].price)
  })
})

test('a pending Idempotency-Key is kept until the creation is acknowledged, then dropped', () => {
  const key = 'b7c1d7a2-4f3e-4c47-9a5e-2f6d1c0e9a11'
  const pending = sanitizeSave({ leaderboardPendingKey: key }, opts)
  assert.equal(pending.leaderboardPendingKey, key, 'unacknowledged creation: retried with the same key')

  const done = sanitizeSave({ leaderboardPendingKey: key, leaderboardRunId: 7, leaderboardEditToken: 'a'.repeat(64) }, opts)
  assert.equal(done.leaderboardPendingKey, null, 'run id + token known: key forgotten')
  assert.equal(done.leaderboardRunId, 7)
})
