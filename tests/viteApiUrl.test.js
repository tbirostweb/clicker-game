import { test } from 'node:test'
import assert from 'node:assert/strict'
import { assertProductionApiUrl } from '../vite-api-url.js'

test('production build requires an HTTPS VITE_API_URL', () => {
  assert.equal(assertProductionApiUrl('https://clicker-api.example.test/'), 'https://clicker-api.example.test')
  for (const bad of [undefined, '', '   ', 'not a url', 'http://clicker-api.example.test', 'ftp://x.test']) {
    assert.throws(() => assertProductionApiUrl(bad), /VITE_API_URL/, String(bad))
  }
})
