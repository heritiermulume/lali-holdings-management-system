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
    <nav className="-mx-1 mb-5 flex gap-2 overflow-x-auto px-1 pb-1">
      {visible.map((tab) => (
        <NavLink
          key={tab.to}
          to={tab.to}
          end={tab.end}
          className={({ isActive }) => cn(
            'min-h-10 shrink-0 rounded-full px-4 text-sm font-semibold whitespace-nowrap inline-flex items-center',
            isActive ? 'bg-lhms text-white' : 'bg-white text-muted',
          )}
        >
          {tab.label}
        </NavLink>
      ))}
    </nav>
  )
}
