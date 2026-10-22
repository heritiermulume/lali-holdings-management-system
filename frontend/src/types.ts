export type Role = {
  id: number
  name: string
  slug: string
  level: number
  module_slug?: string | null
  office_scoped?: boolean
  company_id?: number | null
  office_id?: number | null
}

export type User = {
  id: number
  first_name: string
  last_name: string
  name: string
  email: string
  phone?: string | null
  gender?: string | null
  avatar_url?: string | null
  locale: string
  timezone: string
  is_active: boolean
  is_certified?: boolean
  roles: Role[]
  permissions: string[]
  is_super_admin: boolean
  is_group_admin: boolean
  accessible_company_ids: number[]
  accessible_office_ids?: number[]
  accessible_module_slugs?: string[]
  assignments?: Array<{
    company_id: number | null
    role: Role | null
    office_ids: number[]
    offices?: Array<{ office_id: number; role: Role }>
  }>
  primary_company?: { id: number; name: string; currency_code?: string | null } | null
  primary_office?: { id: number; name: string } | null
  created_office_ids?: number[]
  accessible_companies?: Array<{
    id: number
    name: string
    code?: string
    currency_code?: string | null
    module_slug?: string | null
  }>
  last_login_at?: string | null
  password_changed_at?: string | null
  must_change_password?: boolean
  preferences?: {
    decimal_places?: number
    date_format?: string
    timezone?: string
    group_name?: string
    reporting_currency?: string
    default_timezone?: string
    tax_name?: string
    default_tax_rate?: number
    password_min_length?: number
    password_require_mixed?: boolean
    session_lifetime_minutes?: number
    force_password_reset_days?: number
  } | null
  features?: Record<string, boolean>
  account?: {
    email?: string | null
    phone?: string | null
    password?: string | null
    is_active?: boolean
  } | null
}

export type SystemModule = {
  id: number
  slug: string
  name: string
  name_fr: string
  description?: string | null
  description_fr?: string | null
  icon?: string | null
  version: string
  status: 'active' | 'in_development' | 'coming_soon' | 'inactive'
  sort_order: number
}

export type Paginated<T> = {
  success: boolean
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    unread_count?: number
  }
}
