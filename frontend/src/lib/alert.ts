export type AlertTone = 'info' | 'success' | 'warning' | 'danger'

export type AlertRequest = {
  message: string
  title?: string
  tone?: AlertTone
  confirmLabel?: string
  cancelLabel?: string
  mode?: 'alert' | 'confirm'
}

type AlertHandler = (request: AlertRequest) => Promise<boolean>

let handler: AlertHandler | null = null

export function registerAlertHandler(next: AlertHandler | null) {
  handler = next
}

function normalize(
  message: string,
  options?: Omit<AlertRequest, 'message' | 'mode'>,
  mode: 'alert' | 'confirm' = 'alert',
): AlertRequest {
  return {
    message,
    title: options?.title,
    tone: options?.tone ?? (mode === 'confirm' ? 'warning' : 'info'),
    confirmLabel: options?.confirmLabel,
    cancelLabel: options?.cancelLabel,
    mode,
  }
}

export function showAlert(
  message: string,
  options?: Omit<AlertRequest, 'message' | 'mode'>,
): Promise<void> {
  if (!handler) {
    window.alert(message)
    return Promise.resolve()
  }
  return handler(normalize(message, options, 'alert')).then(() => undefined)
}

export function showConfirm(
  message: string,
  options?: Omit<AlertRequest, 'message' | 'mode'>,
): Promise<boolean> {
  if (!handler) {
    return Promise.resolve(window.confirm(message))
  }
  return handler(normalize(message, options, 'confirm'))
}
