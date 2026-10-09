type DataSyncListener = (reason: DataSyncReason) => void

export type DataSyncReason = 'mutation' | 'visibility' | 'poll' | 'manual'

type DataSyncState = {
  listeners: Set<DataSyncListener>
  softDepth: number
  notifyTimer: number | null
  channel: BroadcastChannel | null
}

const CHANNEL_NAME = 'lhms-data-sync'
const NOTIFY_DEBOUNCE_MS = 400

const root = globalThis as typeof globalThis & { __lhmsDataSync?: DataSyncState }
const state: DataSyncState = root.__lhmsDataSync ?? {
  listeners: new Set(),
  softDepth: 0,
  notifyTimer: null,
  channel: null,
}
root.__lhmsDataSync = state

function ensureChannel() {
  if (state.channel || typeof BroadcastChannel === 'undefined') return state.channel
  try {
    state.channel = new BroadcastChannel(CHANNEL_NAME)
    state.channel.onmessage = (event) => {
      if (event.data?.type === 'data-changed') {
        emitLocal('mutation')
      }
    }
  } catch {
    state.channel = null
  }
  return state.channel
}

function emitLocal(reason: DataSyncReason) {
  state.listeners.forEach((listener) => {
    try {
      listener(reason)
    } catch {
      // Keep other subscribers running.
    }
  })
}

/** True while a background soft-refetch is in flight (skip busy overlay / full-page spinners). */
export function isSoftRefresh() {
  return state.softDepth > 0
}

export function beginSoftRefresh() {
  state.softDepth += 1
}

export function endSoftRefresh() {
  state.softDepth = Math.max(0, state.softDepth - 1)
}

export async function withSoftRefresh<T>(task: () => Promise<T>): Promise<T> {
  beginSoftRefresh()
  try {
    return await task()
  } finally {
    endSoftRefresh()
  }
}

/**
 * Wrap a load that toggles a local loading flag so soft refreshes stay silent
 * when data is already on screen.
 */
export function trackLoadSpinner(setLoading: (value: boolean) => void): () => void {
  if (isSoftRefresh()) return () => {}
  setLoading(true)
  return () => setLoading(false)
}

/** Subscribe to cross-view / cross-tab data change signals. */
export function subscribeDataSync(listener: DataSyncListener) {
  ensureChannel()
  state.listeners.add(listener)
  return () => {
    state.listeners.delete(listener)
  }
}

/** Notify mounted views (and other tabs) that server data may have changed. */
export function notifyDataChanged(reason: DataSyncReason = 'mutation') {
  if (state.notifyTimer != null) {
    window.clearTimeout(state.notifyTimer)
  }
  state.notifyTimer = window.setTimeout(() => {
    state.notifyTimer = null
    emitLocal(reason)
    const channel = ensureChannel()
    if (reason === 'mutation' && channel) {
      try {
        channel.postMessage({ type: 'data-changed' })
      } catch {
        // Ignore BroadcastChannel failures (private mode, etc.).
      }
    }
  }, NOTIFY_DEBOUNCE_MS)
}
