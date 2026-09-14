export type Role = {
  id: number
  name: string
  slug: string
  level: number
}

export type User = {
  id: number
  first_name: string
  last_name: string
  name: string
  email: string
  phone?: string | null
  avatar_url?: string | null
  locale: string
  timezone: string
  is_active: boolean
  roles: Role[]
  permissions: string[]
  is_super_admin: boolean
  is_group_admin: boolean
  accessible_company_ids: number[]
  primary_company?: { id: number; name: string } | null
  primary_office?: { id: number; name: string } | null
  last_login_at?: string | null
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
