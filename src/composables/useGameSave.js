const SAVE_DEBOUNCE_MS = 800

// Debounced localStorage persistence for a plain snapshot object.
// Caller owns the refs; this just serializes/restores a plain JS object.
export function useGameSave(key) {
  let timeout = null

  function load() {
    try {
      const raw = localStorage.getItem(key)
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  }

  function save(snapshot) {
    localStorage.setItem(key, JSON.stringify(snapshot))
  }

  function scheduleSave(getSnapshot) {
    clearTimeout(timeout)
    timeout = setTimeout(() => save(getSnapshot()), SAVE_DEBOUNCE_MS)
  }

  function reset() {
    clearTimeout(timeout)
    localStorage.removeItem(key)
  }

  return { load, save, scheduleSave, reset }
}
