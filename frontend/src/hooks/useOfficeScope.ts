import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import type { User } from '../types'

export type OfficeOption = {
  id: number
  name: string
  code?: string | null
}

type OfficeFilterUser = Pick<User, 'primary_office' | 'roles' | 'created_office_ids'> | null | undefined

/**
 * Default office filter:
 * - the user's principal office, when they hold a role there
 * - "all offices" when they are only linked as creator of an office, or have no principal office
 */
export function defaultOfficeFilterValue(offices: Array<{ id: number }>, user?: OfficeFilterUser): string {
  const primaryId = user?.primary_office?.id
  if (!primaryId || !offices.some((office) => office.id === primaryId)) return ''

  const hasRoleThere = (user?.roles ?? []).some((role) => Number(role.office_id) === primaryId)
  const createdOnly = (user?.created_office_ids ?? []).includes(primaryId) && !hasRoleThere
  if (createdOnly) return ''

  return String(primaryId)
}

/**
 * Shared office filter behaviour:
 * - several offices → show selector; principal office selected when the user has that role
 * - exactly one office → hide selector and lock officeId
 */
export function useOfficeScope(offices: OfficeOption[] = []) {
  const { user } = useAuth()
  const [officeId, setOfficeIdState] = useState('')
  const chosen = useRef(false)
  const officeKey = useMemo(() => offices.map((office) => office.id).join(','), [offices])
  const canChooseOffice = offices.length > 1

  const setOfficeId = useCallback((value: string) => {
    chosen.current = true
    setOfficeIdState(value)
  }, [])

  useEffect(() => {
    if (offices.length === 1) {
      setOfficeIdState(String(offices[0].id))
      return
    }
    if (offices.length === 0) {
      setOfficeIdState('')
      return
    }
    if (!chosen.current) {
      setOfficeIdState(defaultOfficeFilterValue(offices, user))
    }
  }, [officeKey, offices, user])

  return {
    officeId,
    setOfficeId,
    offices,
    canChooseOffice,
    showOfficeFilter: canChooseOffice,
  }
}

type OfficeFilterPayload = {
  can_filter_offices?: boolean
  office_id?: number | null
  filter_offices?: OfficeOption[]
} | null | undefined

/** Lock local officeId from an API payload that already resolved a single office. */
export function syncOfficeIdFromPayload(
  setOfficeId: (value: string) => void,
  payload?: OfficeFilterPayload,
) {
  if (!payload || payload.can_filter_offices) return
  if (payload.office_id) {
    setOfficeId(String(payload.office_id))
    return
  }
  if (payload.filter_offices?.length === 1) {
    setOfficeId(String(payload.filter_offices[0].id))
  }
}

/** Office filter whose first value follows the principal-office rule, then stays on the user's choice. */
export function usePayloadOfficeFilter() {
  const { user } = useAuth()
  const [officeId, setOfficeIdState] = useState('')
  const chosen = useRef(false)

  const setOfficeId = useCallback((value: string) => {
    chosen.current = true
    setOfficeIdState(value)
  }, [])

  const syncFromPayload = useCallback((payload?: OfficeFilterPayload) => {
    if (!payload) return
    if (!payload.can_filter_offices) {
      syncOfficeIdFromPayload(setOfficeIdState, payload)
      return
    }
    if (chosen.current) return
    chosen.current = true
    setOfficeIdState(defaultOfficeFilterValue(payload.filter_offices || [], user))
  }, [user])

  return { officeId, setOfficeId, syncFromPayload }
}
