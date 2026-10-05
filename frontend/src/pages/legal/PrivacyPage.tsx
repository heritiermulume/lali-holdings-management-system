import { useTranslation } from 'react-i18next'
import { LegalDocumentPage } from './LegalDocumentPage'
import { privacyContent } from './privacyContent'

export function PrivacyPage() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language.startsWith('en') ? 'en' : 'fr'

  return (
    <LegalDocumentPage
      document={privacyContent[locale]}
      otherPath="/terms"
      otherLabel={t('auth.terms')}
    />
  )
}
