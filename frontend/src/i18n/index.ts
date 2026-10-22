import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './en.json'
import fr from './fr.json'
import sw from './sw.json'
import { normalizeLocale } from './locales'

const saved = normalizeLocale(localStorage.getItem('lhms_locale') || 'fr')

void i18n.use(initReactI18next).init({
  resources: {
    fr: { translation: fr },
    en: { translation: en },
    sw: { translation: sw },
  },
  lng: saved,
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
}).then(() => {
  document.documentElement.lang = normalizeLocale(i18n.language)
})

i18n.on('languageChanged', (lng) => {
  const next = normalizeLocale(lng)
  localStorage.setItem('lhms_locale', next)
  document.documentElement.lang = next
})

export default i18n
