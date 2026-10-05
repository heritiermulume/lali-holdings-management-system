export type SettingFieldType = 'string' | 'number' | 'boolean' | 'select' | 'password'

export type SettingField = {
  key: string
  type: SettingFieldType
  options?: Array<{ value: string; labelKey: string }>
}

export type SettingGroupId = 'general' | 'finance' | 'security'

export const settingGroups: Record<SettingGroupId, SettingField[]> = {
  general: [
    { key: 'group_name', type: 'string' },
    { key: 'legal_name', type: 'string' },
    { key: 'support_email', type: 'string' },
    { key: 'default_locale', type: 'select', options: [
      { value: 'fr', labelKey: 'settings.options.localeFr' },
      { value: 'en', labelKey: 'settings.options.localeEn' },
    ] },
    { key: 'default_timezone', type: 'string' },
    { key: 'date_format', type: 'select', options: [
      { value: 'DD/MM/YYYY', labelKey: 'settings.options.dateDmy' },
      { value: 'YYYY-MM-DD', labelKey: 'settings.options.dateIso' },
      { value: 'MM/DD/YYYY', labelKey: 'settings.options.dateMdy' },
    ] },
    { key: 'week_start', type: 'select', options: [
      { value: '1', labelKey: 'settings.options.monday' },
      { value: '0', labelKey: 'settings.options.sunday' },
    ] },
    { key: 'mail_from_address', type: 'string' },
    { key: 'mail_from_name', type: 'string' },
    { key: 'mail_host', type: 'string' },
    { key: 'mail_port', type: 'number' },
    { key: 'mail_username', type: 'string' },
    { key: 'mail_password', type: 'password' },
    { key: 'mail_encryption', type: 'select', options: [
      { value: 'tls', labelKey: 'settings.options.mailTls' },
      { value: 'ssl', labelKey: 'settings.options.mailSsl' },
      { value: 'none', labelKey: 'settings.options.mailNone' },
    ] },
  ],
  finance: [
    { key: 'decimal_places', type: 'number' },
    { key: 'fiscal_year_start_month', type: 'select', options: [
      { value: '1', labelKey: 'settings.options.month1' },
      { value: '4', labelKey: 'settings.options.month4' },
      { value: '7', labelKey: 'settings.options.month7' },
      { value: '10', labelKey: 'settings.options.month10' },
    ] },
    { key: 'tax_inclusive_prices', type: 'boolean' },
    { key: 'fx_spread_percent', type: 'number' },
    { key: 'tax_name', type: 'string' },
    { key: 'default_tax_rate', type: 'number' },
    { key: 'multi_currency', type: 'boolean' },
  ],
  security: [
    { key: 'password_min_length', type: 'number' },
    { key: 'password_require_mixed', type: 'boolean' },
    { key: 'session_lifetime_minutes', type: 'number' },
    { key: 'force_password_reset_days', type: 'number' },
  ],
}

export const moduleSettingFields: Record<string, SettingField[]> = {
  kams: [
    { key: 'order_prefix', type: 'string' },
    { key: 'invoice_prefix', type: 'string' },
    { key: 'stock_alert_threshold', type: 'number' },
    { key: 'stock_loss_alert_percent', type: 'number' },
    { key: 'default_payment_terms_days', type: 'number' },
    { key: 'allow_backorders', type: 'boolean' },
    { key: 'low_stock_notify', type: 'boolean' },
    { key: 'interview_hours', type: 'string' },
    { key: 'observation_hours', type: 'string' },
    { key: 'default_member_rank', type: 'string' },
  ],
  'heavy-equipment': [
    { key: 'default_rental_days', type: 'number' },
    { key: 'maintenance_interval_hours', type: 'number' },
    { key: 'require_operator_license', type: 'boolean' },
  ],
  'real-estate': [
    { key: 'default_lease_months', type: 'number' },
    { key: 'occupancy_alert_percent', type: 'number' },
    { key: 'late_rent_grace_days', type: 'number' },
  ],
  'rice-mills': [
    { key: 'standard_bag_kg', type: 'number' },
    { key: 'moisture_max_percent', type: 'number' },
    { key: 'mill_shift_hours', type: 'number' },
  ],
  'lali-tv': [
    { key: 'default_broadcast_tz', type: 'string' },
    { key: 'max_upload_mb', type: 'number' },
    { key: 'content_review_required', type: 'boolean' },
  ],
}
