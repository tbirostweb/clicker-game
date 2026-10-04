const SAVE_DEBOUNCE_MS = 800

// Debounced localStorage persistence for a plain snapshot object.
// Caller owns the refs; this just serializes/restores a plain JS object.
// Storage errors (quota exceeded, storage disabled, private mode) never throw
// into the game loop: they are reported through `onError` so the UI can warn.
export function useGameSave(key, { storage = globalThis.localStorage, onError = () => {}, onSuccess = () => {} } = {}) {
  let timeout = null
  let pendingGetter = null

  function load() {
    try {
      const raw = storage?.getItem(key)
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  }

  function save(snapshot) {
    try {
      storage.setItem(key, JSON.stringify(snapshot))
      onSuccess()
      return true
    } catch (error) {
      onError(error)
      return false
    }
  }

  function scheduleSave(getSnapshot) {
    pendingGetter = getSnapshot
    clearTimeout(timeout)
    timeout = setTimeout(flush, SAVE_DEBOUNCE_MS)
  }

  // Writes a pending debounced save immediately (used on pagehide so the
  // last clicks before closing the tab are not lost).
  function flush() {
    clearTimeout(timeout)
    timeout = null
    if (!pendingGetter) return true
    const getter = pendingGetter
    pendingGetter = null
    return save(getter())
  }

  function reset() {
    clearTimeout(timeout)
    timeout = null
    pendingGetter = null
    try {
      storage?.removeItem(key)
    } catch (error) {
      onError(error)
    }
  }

  return { load, save, scheduleSave, flush, reset }
}
