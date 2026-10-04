import { test } from 'node:test'
import assert from 'node:assert/strict'
import { useGameSave } from '../src/composables/useGameSave.js'

function memoryStorage({ failWrites = false } = {}) {
  const data = new Map()
  return {
    data,
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => {
      if (failWrites) {
        const err = new Error('QuotaExceededError')
        err.name = 'QuotaExceededError'
        throw err
      }
      data.set(k, v)
    },
    removeItem: (k) => data.delete(k),
  }
}

test('load returns null on corrupted JSON instead of throwing', () => {
  const storage = memoryStorage()
  storage.data.set('k', '{broken')
  assert.equal(useGameSave('k', { storage }).load(), null)
})

test('quota errors are reported, never thrown into the game loop', () => {
  const errors = []
  const save = useGameSave('k', { storage: memoryStorage({ failWrites: true }), onError: (e) => errors.push(e) })
  assert.doesNotThrow(() => save.save({ a: 1 }))
  assert.equal(save.save({ a: 1 }), false)
  assert.equal(errors.length, 2)
})

test('flush writes a pending debounced save immediately (pagehide)', () => {
  const storage = memoryStorage()
  const save = useGameSave('k', { storage })
  let n = 0
  save.scheduleSave(() => ({ clicks: ++n }))
  assert.equal(storage.getItem('k'), null, 'debounced: not written yet')
  assert.equal(save.flush(), true)
  assert.deepEqual(JSON.parse(storage.getItem('k')), { clicks: 1 })
  assert.equal(save.flush(), true, 'nothing pending: no-op')
  assert.equal(n, 1)
})

test('reset cancels pending save and clears storage', async () => {
  const storage = memoryStorage()
  const save = useGameSave('k', { storage })
  save.save({ a: 1 })
  save.scheduleSave(() => ({ a: 2 }))
  save.reset()
  await new Promise((r) => setTimeout(r, 900))
  assert.equal(storage.getItem('k'), null)
})
