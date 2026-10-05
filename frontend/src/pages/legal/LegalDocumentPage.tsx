import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { LegalDocument } from './types'

const laliLogo = '/lali_holdings_logo.png'

type Props = {
  document: LegalDocument
  otherPath: string
  otherLabel: string
}

export function LegalDocumentPage({ document: doc, otherPath, otherLabel }: Props) {
  const { t, i18n } = useTranslation()

  const switchLanguage = () => {
    const next = i18n.language.startsWith('fr') ? 'en' : 'fr'
    localStorage.setItem('lhms_locale_picked', '1')
    localStorage.setItem('lhms_locale', next)
    void i18n.changeLanguage(next)
  }

  return (
    <div className="min-h-dvh bg-[radial-gradient(circle_at_top,_#e8f3ea_0%,_#f4f7f5_40%,_#ffffff_100%)]">
      <header className="border-b border-line/80 bg-white/80 backdrop-blur-sm">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-5 py-4">
          <Link to="/login" className="flex items-center gap-3">
            <img src={laliLogo} alt="Lali Holdings" className="h-12 w-auto object-contain" />
            <div className="hidden sm:block">
              <div className="text-xs font-semibold tracking-wide text-lhms">{t('app.name')}</div>
              <div className="text-sm font-semibold text-ink">{t('app.full')}</div>
            </div>
          </Link>
          <div className="flex items-center gap-3 text-sm">
            <button type="button" className="text-muted hover:text-ink" onClick={switchLanguage}>
              {i18n.language.startsWith('fr') ? 'EN' : 'FR'}
            </button>
            <Link to="/login" className="font-medium text-lhms hover:underline">
              {t('auth.backToLogin')}
            </Link>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-4xl px-5 py-8 sm:py-12">
        <div className="rounded-3xl border border-white/80 bg-white/95 p-6 shadow-[0_18px_50px_rgba(4,55,17,0.08)] ring-1 ring-lhms/5 sm:p-10">
          <p className="text-xs font-semibold uppercase tracking-[0.22em] text-lhms/70">{t('app.name')}</p>
          <h1 className="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{doc.title}</h1>
          <p className="mt-3 text-sm text-muted">
            {t('legal.lastUpdated')}: {doc.updatedAt}
          </p>
          <p className="mt-6 text-base leading-relaxed text-ink/90">{doc.intro}</p>

          <nav className="mt-8 rounded-2xl bg-lhms-soft/60 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-lhms">{t('legal.contents')}</p>
            <ol className="mt-3 space-y-1.5 text-sm">
              {doc.sections.map((section) => (
                <li key={section.title}>
                  <a href={`#${slugify(section.title)}`} className="text-ink/80 hover:text-lhms hover:underline">
                    {section.title}
                  </a>
                </li>
              ))}
            </ol>
          </nav>

          <div className="mt-10 space-y-10">
            {doc.sections.map((section) => (
              <section key={section.title} id={slugify(section.title)} className="scroll-mt-24">
                <h2 className="text-xl font-bold text-ink">{section.title}</h2>
                <div className="mt-3 space-y-3 text-[15px] leading-relaxed text-ink/85">
                  {section.paragraphs.map((paragraph) => (
                    <p key={paragraph.slice(0, 48)}>{paragraph}</p>
                  ))}
                  {section.bullets?.length ? (
                    <ul className="list-disc space-y-2 pl-5">
                      {section.bullets.map((bullet) => (
                        <li key={bullet.slice(0, 48)}>{bullet}</li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              </section>
            ))}
          </div>

          <div className="mt-10 rounded-2xl border border-line bg-surface/60 px-4 py-4 text-sm leading-relaxed text-muted">
            {doc.contactNote}
          </div>

          <div className="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-6 text-sm">
            <Link to={otherPath} className="font-medium text-lhms hover:underline">
              {otherLabel}
            </Link>
            <Link to="/login" className="text-muted hover:text-ink">
              {t('auth.backToLogin')}
            </Link>
          </div>
        </div>
      </main>
    </div>
  )
}

function slugify(value: string) {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}
