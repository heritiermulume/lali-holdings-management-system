import { useEffect, useRef } from 'react'
import { subscribeDataSync, withSoftRefresh, type DataSyncReason } from '../lib/dataSync'

const DEFAULT_POLL_MS = 12_000
const DEFAULT_COOLDOWN_MS = 8_000

type LiveDataOptions = {
  /** When false, no soft refresh runs (e.g. logged-out / disabled feature). */
  enabled?: boolean
  /** Soft poll while the tab is visible. Set 0 to disable polling. */
  pollMs?: number
  /** Minimum gap between soft refreshes. */
  cooldownMs?: number
}

/**
 * Soft-refetch page data when the tab becomes visible again, on a gentle poll,
 * and after mutations elsewhere in the app (or in another tab).
 *
 * Keep the existing mount / filter `useEffect(() => load(), …)` — this only
 * adds background freshness without full-page reload UX.
 */
export function useLiveData(
  refetch: () => void | Promise<unknown>,
  options: LiveDataOptions = {},
) {
  const {
    enabled = true,
    pollMs = DEFAULT_POLL_MS,
    cooldownMs = DEFAULT_COOLDOWN_MS,
  } = options

  const refetchRef = useRef(refetch)
  refetchRef.current = refetch
  const lastSoftRefreshAt = useRef(0)
  const inFlight = useRef(false)

  useEffect(() => {
    if (!enabled) return

    const softRefresh = (reason: DataSyncReason) => {
      if (!localStorage.getItem('lhms_token')) return
      if (document.visibilityState !== 'visible') return
      if (inFlight.current) return

      const now = Date.now()
      // Mutations in other views should win over a slightly stale cooldown.
      const wait = reason === 'mutation' ? Math.min(cooldownMs, 2_500) : cooldownMs
      if (now - lastSoftRefreshAt.current < wait) return

      lastSoftRefreshAt.current = now
      inFlight.current = true
      void withSoftRefresh(async () => {
        await Promise.resolve(refetchRef.current())
      }).catch(() => {
        // Soft refresh failures are non-fatal; next poll / visibility will retry.
      }).finally(() => {
        inFlight.current = false
      })
    }

    const onVisibility = () => {
      if (document.visibilityState === 'visible') softRefresh('visibility')
    }

    const unsubscribe = subscribeDataSync((reason) => softRefresh(reason))
    document.addEventListener('visibilitychange', onVisibility)
    const intervalId = pollMs > 0
      ? window.setInterval(() => softRefresh('poll'), pollMs)
      : null

    return () => {
      unsubscribe()
      document.removeEventListener('visibilitychange', onVisibility)
      if (intervalId != null) window.clearInterval(intervalId)
    }
  }, [enabled, pollMs, cooldownMs])
}
