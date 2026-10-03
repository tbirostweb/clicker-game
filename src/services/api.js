const BASE_URL = import.meta.env.VITE_API_URL || 'http://clicker-game.local:8319'

async function request(path, options = {}) {
  const res = await fetch(`${BASE_URL}${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  })

  const body = await res.json().catch(() => null)
  if (!res.ok) {
    const error = new Error(body?.error || `Request failed with status ${res.status}`)
    error.status = res.status
    error.details = body?.details
    throw error
  }
  return body
}

export function fetchLeaderboard(limit = 20, sort = 'active') {
  return request(`/api/leaderboard?limit=${limit}&sort=${sort}`)
}

export function submitRun({ name, rebirths, score, timeSeconds, activeSeconds, trophies }) {
  return request('/api/leaderboard', {
    method: 'POST',
    body: JSON.stringify({ name, rebirths, score, timeSeconds, activeSeconds, trophies }),
  })
}

// Refreshes a previously submitted run in place (id remembered from the
// first submit) so replaying/rebirthing more doesn't leave a stale duplicate
// entry behind on the leaderboard.
export function updateRun(id, { name, rebirths, score, timeSeconds, activeSeconds, trophies }) {
  return request(`/api/leaderboard/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ name, rebirths, score, timeSeconds, activeSeconds, trophies }),
  })
}
