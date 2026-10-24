export type ContactFieldErrors = { email: string; phone: string; form: string }

export function contactFieldErrors(
  data: { message?: string; errors?: Record<string, string[]> } | undefined,
  fallback: string,
): ContactFieldErrors {
  const email = data?.errors?.email?.[0] || ''
  const phone = data?.errors?.phone?.[0] || ''
  const other = Object.entries(data?.errors || {})
    .filter(([key]) => key !== 'email' && key !== 'phone')
    .flatMap(([, messages]) => messages)
    .find(Boolean)
  return {
    email,
    phone,
    form: other || (email || phone ? '' : data?.message || fallback),
  }
}
