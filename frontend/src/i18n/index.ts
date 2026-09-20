import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './en.json'
import fr from './fr.json'

const saved = localStorage.getItem('lhms_locale') || 'fr'

void i18n.use(initReactI18next).init({
  resources: {
    fr: { translation: fr },
    en: { translation: en },
  },
  lng: saved,
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
}).then(() => {
  document.documentElement.lang = i18n.language.startsWith('en') ? 'en' : 'fr'
})

i18n.on('languageChanged', (lng) => {
  const next = lng.startsWith('en') ? 'en' : 'fr'
  localStorage.setItem('lhms_locale', next)
  document.documentElement.lang = next
})

export default i18n
