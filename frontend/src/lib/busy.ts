export type BusyKind = 'data' | 'import'

type Listener = (pending: number, kind: BusyKind) => void

type BusyState = {
  pending: number
  kind: BusyKind
  listeners: Set<Listener>
}

const root = globalThis as typeof globalThis & { __lhmsBusy?: BusyState }
const state: BusyState = root.__lhmsBusy ?? {
  pending: 0,
  kind: 'data',
  listeners: new Set(),
}
root.__lhmsBusy = state

function notify() {
  state.listeners.forEach((listener) => listener(state.pending, state.kind))
}

export function beginBusy(next: BusyKind = 'data') {
  state.pending += 1
  state.kind = next
  notify()
}

export function endBusy() {
  state.pending = Math.max(0, state.pending - 1)
  notify()
}

export function subscribeBusy(listener: Listener) {
  state.listeners.add(listener)
  listener(state.pending, state.kind)
  return () => {
    state.listeners.delete(listener)
  }
}

export async function withBusy<T>(next: BusyKind, task: () => Promise<T>): Promise<T> {
  beginBusy(next)
  const started = Date.now()
  try {
    return await task()
  } finally {
    const minimum = next === 'import' ? 700 : 0
    const remaining = Math.max(0, minimum - (Date.now() - started))
    if (remaining) {
      await new Promise((resolve) => window.setTimeout(resolve, remaining))
    }
    endBusy()
  }
}
