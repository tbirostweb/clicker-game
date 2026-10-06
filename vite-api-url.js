// Build-time guard for the public API origin baked into the bundle.
// A production build without VITE_API_URL, or with a non-HTTPS URL, would
// ship a front that talks to a dev host or in clear text: fail the build.
export function assertProductionApiUrl(value) {
  let url
  try {
    url = new URL(String(value ?? '').trim())
  } catch {
    throw new Error('VITE_API_URL must be set to the HTTPS origin of the API for a production build (e.g. https://clicker-api.example).')
  }
  if (url.protocol !== 'https:') {
    throw new Error(`VITE_API_URL must use HTTPS in production (got "${url.protocol}").`)
  }
  return url.origin
}
