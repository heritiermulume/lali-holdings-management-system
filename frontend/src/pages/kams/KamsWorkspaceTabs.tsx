import { NavLink } from 'react-router-dom'
import { cn } from '../../lib/cn'

export type KamsWorkspaceTab = {
  to: string
  end?: boolean
  label: string
  show?: boolean
}

export function KamsWorkspaceTabs({ tabs }: { tabs: KamsWorkspaceTab[] }) {
  const visible = tabs.filter((tab) => tab.show !== false)

  return (
    <nav className="mb-5 flex flex-wrap gap-2">
      {visible.map((tab) => (
        <NavLink
          key={tab.to}
          to={tab.to}
          end={tab.end}
          className={({ isActive }) => cn(
            'inline-flex min-h-10 items-center rounded-full px-3 text-xs font-semibold whitespace-nowrap sm:px-4 sm:text-sm',
            isActive ? 'bg-lhms text-white' : 'bg-white text-muted',
          )}
        >
          {tab.label}
        </NavLink>
      ))}
    </nav>
  )
}
