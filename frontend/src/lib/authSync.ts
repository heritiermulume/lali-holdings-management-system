type AuthSyncHandler = () => void

let permissionsStaleHandler: AuthSyncHandler | null = null
let refreshing = false

/** Register a handler that reloads the current user/permissions from the API. */
export function setPermissionsStaleHandler(handler: AuthSyncHandler | null) {
  permissionsStaleHandler = handler
}

/** Notify that permissions may have changed (e.g. API 403). Deduped while a refresh runs. */
export function notifyPermissionsPossiblyStale() {
  if (!permissionsStaleHandler || refreshing) return
  refreshing = true
  try {
    permissionsStaleHandler()
  } finally {
    // Allow another refresh shortly after (async refresh may still be in flight).
    window.setTimeout(() => {
      refreshing = false
    }, 1500)
  }
}
