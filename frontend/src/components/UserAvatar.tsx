import { cn } from '../lib/cn'
import { initials } from '../lib/format'
import type { User } from '../types'

export function UserAvatar({
  user,
  className,
  textClassName,
}: {
  user?: Pick<User, 'name' | 'avatar_url'> | null
  className?: string
  textClassName?: string
}) {
  if (user?.avatar_url) {
    return (
      <img
        src={user.avatar_url}
        alt={user.name}
        className={cn('h-full w-full rounded-full object-cover', className)}
      />
    )
  }

  return <span className={textClassName}>{initials(user?.name)}</span>
}
