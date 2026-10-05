import { Crown, Nfc } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'

type CardKind = 'member' | 'central' | 'office'

type KamsBankCardProps = {
  holder: string
  subtitle?: string
  balance: number
  pending?: number
  pendingDeposits?: number
  kind?: CardKind
  className?: string
}

const kindStyles: Record<CardKind, { card: string; blob: string }> = {
  member: {
    card: 'from-[#0c5c2e] via-lhms to-lhms-deep shadow-[0_18px_40px_rgba(4,55,17,0.35)]',
    blob: 'bg-lime/25',
  },
  central: {
    card: 'from-[#1d4ed8] via-[#1e3a8a] to-[#0f172a] shadow-[0_18px_40px_rgba(15,23,42,0.38)]',
    blob: 'bg-amber-300/25',
  },
  office: {
    card: 'from-[#0f766e] via-[#115e59] to-[#042f2e] shadow-[0_18px_40px_rgba(15,118,110,0.35)]',
    blob: 'bg-cyan-200/20',
  },
}

function CardChip() {
  return (
    <span className="relative inline-block h-8 w-11 overflow-hidden rounded-[6px] bg-gradient-to-br from-[#f8e7b0] via-[#d4af37] to-[#9a6b12] shadow-[inset_0_1px_0_rgba(255,255,255,0.55)]">
      <span className="absolute inset-x-0 top-[46%] h-px bg-black/20" />
      <span className="absolute inset-y-0 left-[32%] w-px bg-black/20" />
      <span className="absolute inset-y-0 left-[64%] w-px bg-black/20" />
      <span className="absolute inset-x-[16%] top-1 h-[28%] rounded-[3px] border border-black/20" />
      <span className="absolute inset-x-[16%] bottom-1 h-[28%] rounded-[3px] border border-black/20" />
    </span>
  )
}

export function KamsBankCard({
  holder,
  subtitle,
  balance,
  pending = 0,
  pendingDeposits = 0,
  kind = 'member',
  className,
}: KamsBankCardProps) {
  const { t } = useTranslation()
  const look = kindStyles[kind]

  return (
    <div className={cn('relative box-border h-56 w-full max-w-sm overflow-hidden rounded-[1.4rem] bg-gradient-to-br p-4 text-white', look.card, className)}>
      <div className="pointer-events-none absolute -right-12 -top-16 h-44 w-44 rounded-full bg-white/10" />
      <div className={cn('pointer-events-none absolute -bottom-20 -left-10 h-40 w-40 rounded-full', look.blob)} />
      <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(115deg,transparent_18%,rgba(255,255,255,0.14)_46%,transparent_72%)]" />

      <div className="relative flex h-full min-w-0 flex-col justify-between">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/15">
              <Crown size={16} />
            </span>
            <div className="min-w-0">
              <div className="text-[11px] font-semibold tracking-[0.28em]">KAMS</div>
              <div className="truncate text-[11px] text-white/70">{subtitle || t('kams.debitCard')}</div>
            </div>
          </div>
          <Nfc size={22} className="shrink-0 rotate-90 text-white/80" />
        </div>

        <div className="shrink-0">
          <CardChip />
        </div>

        <div className="min-w-0 shrink-0">
          <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/65">{t('kams.cardHolder')}</div>
          <div className="mt-0.5 truncate text-sm font-semibold uppercase tracking-wide">{holder}</div>
        </div>

        <div className="min-w-0 shrink-0">
          <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/65">{t('kams.balance')}</div>
          <div className="mt-0.5 truncate text-2xl font-extrabold tabular-nums">{money(balance)}</div>
          {pending > 0 ? (
            <div className="mt-1 truncate text-[11px] text-amber-200">
              {t('kams.pendingWithdrawals')}: {money(pending)}
            </div>
          ) : null}
          {pendingDeposits > 0 ? (
            <div className="mt-1 truncate text-[11px] text-amber-200">
              {t('kams.pendingDeposits')}: {money(pendingDeposits)}
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}
