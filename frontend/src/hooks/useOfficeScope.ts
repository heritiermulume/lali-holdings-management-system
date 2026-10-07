import { useEffect, useMemo, useState } from 'react'

export type OfficeOption = {
  id: number
  name: string
  code?: string | null
}

/**
 * Shared office filter behaviour:
 * - several offices → show selector, optional "all"
 * - exactly one office → hide selector and lock officeId
 */
export function useOfficeScope(offices: OfficeOption[] = []) {
  const [officeId, setOfficeId] = useState('')
  const officeKey = useMemo(() => offices.map((office) => office.id).join(','), [offices])
  const canChooseOffice = offices.length > 1

  useEffect(() => {
    if (offices.length === 1) {
      setOfficeId(String(offices[0].id))
      return
    }
    if (offices.length === 0) {
      setOfficeId('')
    }
  }, [officeKey, offices])

  return {
    officeId,
    setOfficeId,
    offices,
    canChooseOffice,
    showOfficeFilter: canChooseOffice,
  }
}

/** Lock local officeId from an API payload that already resolved a single office. */
export function syncOfficeIdFromPayload(
  setOfficeId: (value: string) => void,
  payload?: { can_filter_offices?: boolean; office_id?: number | null; filter_offices?: OfficeOption[] } | null,
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
