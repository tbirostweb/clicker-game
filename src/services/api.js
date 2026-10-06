// Production builds require an HTTPS VITE_API_URL (enforced in vite.config.js);
// the local fallback only applies to the dev server and unit tests.
const BASE_URL = import.meta.env?.VITE_API_URL || (import.meta.env?.PROD ? '' : 'http://clicker-game.local:8319')
const REQUEST_TIMEOUT_MS = 15_000

// Largest integer both the browser and the API accept exactly.
export const MAX_SAFE_SCORE = Number.MAX_SAFE_INTEGER

async function request(path, { headers = {}, ...options } = {}, fetchImpl = globalThis.fetch) {
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null
  const timer = controller ? setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS) : null
  let res
  try {
    res = await fetchImpl(`${BASE_URL}${path}`, {
      ...options,
      headers: { 'Content-Type': 'application/json', ...headers },
      credentials: 'omit',
      signal: controller?.signal,
    })
  } finally {
    if (timer) clearTimeout(timer)
  }

  const body = res.status === 204 ? null : await res.json().catch(() => null)
  if (!res.ok) {
    const error = new Error(body?.error || `Request failed with status ${res.status}`)
    error.status = res.status
    error.details = body?.details
    throw error
  }
  return body
}

function runPayload({ name, rebirths, score, timeSeconds, activeSeconds, trophies }) {
  const toInt = (value) => Math.max(0, Math.min(MAX_SAFE_SCORE, Math.floor(Number(value) || 0)))
  return {
    name,
    rebirths: toInt(rebirths),
    score: toInt(score),
    timeSeconds: toInt(timeSeconds),
    activeSeconds: toInt(activeSeconds),
    trophies: toInt(trophies),
  }
}

export function newIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

export function fetchLeaderboard(limit = 20, sort = 'active', fetchImpl) {
  const params = new URLSearchParams({ limit: String(limit), sort })
  return request(`/api/leaderboard?${params}`, {}, fetchImpl)
}

// Creates a run. The response carries a secret `editToken` (returned only
// here) that proves ownership for later updates/deletion: keep it locally,
// never display or share it. `idempotencyKey` makes a retried POST return the
// same run instead of creating a duplicate.
export function submitRun(run, idempotencyKey, fetchImpl) {
  return request('/api/leaderboard', {
    method: 'POST',
    headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
    body: JSON.stringify(runPayload(run)),
  }, fetchImpl)
}

// Refreshes a previously submitted run in place (id + editToken remembered
// from the first submit) so replaying/rebirthing more doesn't leave a stale
// duplicate entry behind on the leaderboard.
export function updateRun(id, editToken, run, fetchImpl) {
  return request(`/api/leaderboard/${encodeURIComponent(id)}`, {
    method: 'PUT',
    headers: { 'X-Edit-Token': editToken },
    body: JSON.stringify(runPayload(run)),
  }, fetchImpl)
}

// Withdraws the player's own run from the public leaderboard.
export function deleteRun(id, editToken, fetchImpl) {
  return request(`/api/leaderboard/${encodeURIComponent(id)}`, {
    method: 'DELETE',
    headers: { 'X-Edit-Token': editToken },
  }, fetchImpl)
}
