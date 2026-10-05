import { useTranslation } from 'react-i18next'
import { LegalDocumentPage } from './LegalDocumentPage'
import { termsContent } from './termsContent'

export function TermsPage() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language.startsWith('en') ? 'en' : 'fr'

  return (
    <LegalDocumentPage
      document={termsContent[locale]}
      otherPath="/privacy"
      otherLabel={t('auth.privacy')}
    />
  )
}
