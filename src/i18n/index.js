import { createI18n } from 'vue-i18n'
import fr from './locales/fr.json'
import en from './locales/en.json'

const STORAGE_KEY = 'clicker-lang'
const SUPPORTED = ['fr', 'en']

function readSavedLocale() {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

function detectLocale() {
  const saved = readSavedLocale()
  if (saved && SUPPORTED.includes(saved)) return saved

  const browserLang = typeof navigator !== 'undefined' ? navigator.language?.slice(0, 2) : null
  return SUPPORTED.includes(browserLang) ? browserLang : 'fr'
}

function syncDocumentLang(locale) {
  if (typeof document !== 'undefined') document.documentElement.setAttribute('lang', locale)
}

const initialLocale = detectLocale()

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'fr',
  messages: { fr, en },
})

// <html lang> must match the UI language from the first render, not only
// after the player changes it in the settings.
syncDocumentLang(initialLocale)

export function setLocale(locale) {
  if (!SUPPORTED.includes(locale)) return
  i18n.global.locale.value = locale
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // Storage unavailable (private mode / quota): language still applies for this session.
  }
  syncDocumentLang(locale)
}
