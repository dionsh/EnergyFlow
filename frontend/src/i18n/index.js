import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './locales/en.json'
import sq from './locales/sq.json'
import { DEFAULT_LANGUAGE, isSupported } from './languages'

const STORAGE_KEY = 'ef.locale'

function initialLanguage() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved && isSupported(saved)) return saved
  } catch {
    // Storage can be unavailable (private mode); fall through to the browser language.
  }
  const browser = (navigator.language || '').slice(0, 2).toLowerCase()
  return isSupported(browser) ? browser : DEFAULT_LANGUAGE
}

i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, sq: { translation: sq } },
  lng: initialLanguage(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
})

i18n.on('languageChanged', (language) => {
  document.documentElement.lang = language
  try {
    localStorage.setItem(STORAGE_KEY, language)
  } catch {
    // Non-critical convenience only.
  }
})
document.documentElement.lang = i18n.language

export default i18n
