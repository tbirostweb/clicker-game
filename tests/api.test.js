import { test } from 'node:test'
import assert from 'node:assert/strict'
import { submitRun, updateRun, deleteRun, fetchLeaderboard, newIdempotencyKey } from '../src/services/api.js'

function fakeFetch(status = 200, body = {}) {
  const calls = []
  const impl = async (url, init) => {
    calls.push({ url, init })
    return { ok: status < 400, status, json: async () => body }
  }
  return { calls, impl }
}

const run = { name: 'Alice', rebirths: 1, score: 1e30, timeSeconds: 12.7, activeSeconds: -3, trophies: 2 }

test('submitRun sends Idempotency-Key, no credentials, and clamps values to safe integers', async () => {
  const f = fakeFetch(201, { id: 1, editToken: 'a'.repeat(64) })
  await submitRun(run, 'key-1234567890abcdef', f.impl)
  const { init } = f.calls[0]
  assert.equal(init.method, 'POST')
  assert.equal(init.credentials, 'omit')
  assert.equal(init.headers['Idempotency-Key'], 'key-1234567890abcdef')
  const sent = JSON.parse(init.body)
  assert.equal(sent.score, Number.MAX_SAFE_INTEGER)
  assert.ok(Number.isInteger(sent.timeSeconds) && sent.timeSeconds === 12)
  assert.equal(sent.activeSeconds, 0)
})

test('updateRun / deleteRun send the edit token header and encode the id', async () => {
  const f = fakeFetch(200, { id: 1 })
  await updateRun('1/../2', 'tok', run, f.impl)
  assert.match(f.calls[0].url, /\/api\/leaderboard\/1%2F\.\.%2F2$/)
  assert.equal(f.calls[0].init.headers['X-Edit-Token'], 'tok')
  const d = fakeFetch(204)
  await deleteRun(5, 'tok', d.impl)
  assert.equal(d.calls[0].init.method, 'DELETE')
  assert.equal(d.calls[0].init.headers['X-Edit-Token'], 'tok')
})

test('errors expose the HTTP status (used to fall back to a new run on 401/403/404)', async () => {
  const f = fakeFetch(403, { error: 'Forbidden' })
  await assert.rejects(updateRun(1, 'bad', run, f.impl), (e) => e.status === 403 && e.message === 'Forbidden')
})

test('fetchLeaderboard encodes query parameters', async () => {
  const f = fakeFetch(200, [])
  await fetchLeaderboard(20, 'score&x=1', f.impl)
  assert.match(f.calls[0].url, /sort=score%26x%3D1/)
})

test('idempotency keys are random and match the API format', () => {
  const a = newIdempotencyKey()
  const b = newIdempotencyKey()
  assert.notEqual(a, b)
  assert.match(a, /^[A-Za-z0-9-]{16,128}$/)
})
