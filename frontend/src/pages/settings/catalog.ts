export type SettingFieldType = 'string' | 'number' | 'boolean' | 'select'

export type SettingField = {
  key: string
  type: SettingFieldType
  options?: Array<{ value: string; labelKey: string }>
}

export type SettingGroupId = 'general' | 'finance' | 'notifications' | 'security'

export const settingGroups: Record<SettingGroupId, SettingField[]> = {
  general: [
    { key: 'group_name', type: 'string' },
    { key: 'legal_name', type: 'string' },
    { key: 'support_email', type: 'string' },
    { key: 'default_locale', type: 'select', options: [
      { value: 'fr', labelKey: 'settings.options.localeFr' },
      { value: 'en', labelKey: 'settings.options.localeEn' },
    ] },
    { key: 'default_timezone', type: 'select', options: [
      { value: 'Africa/Kinshasa', labelKey: 'settings.options.tzKinshasa' },
      { value: 'Africa/Lubumbashi', labelKey: 'settings.options.tzLubumbashi' },
      { value: 'Africa/Dar_es_Salaam', labelKey: 'settings.options.tzDar' },
      { value: 'UTC', labelKey: 'settings.options.tzUtc' },
    ] },
    { key: 'date_format', type: 'select', options: [
      { value: 'DD/MM/YYYY', labelKey: 'settings.options.dateDmy' },
      { value: 'YYYY-MM-DD', labelKey: 'settings.options.dateIso' },
      { value: 'MM/DD/YYYY', labelKey: 'settings.options.dateMdy' },
    ] },
    { key: 'week_start', type: 'select', options: [
      { value: '1', labelKey: 'settings.options.monday' },
      { value: '0', labelKey: 'settings.options.sunday' },
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
  ],
  notifications: [
    { key: 'email_enabled', type: 'boolean' },
    { key: 'sms_enabled', type: 'boolean' },
    { key: 'inapp_enabled', type: 'boolean' },
    { key: 'digest_hour', type: 'number' },
  ],
  security: [
    { key: 'password_min_length', type: 'number' },
    { key: 'password_require_mixed', type: 'boolean' },
    { key: 'session_lifetime_minutes', type: 'number' },
    { key: 'force_password_reset_days', type: 'number' },
    { key: 'audit_retention_days', type: 'number' },
  ],
}

export const moduleSettingFields: Record<string, SettingField[]> = {
  kams: [
    { key: 'order_prefix', type: 'string' },
    { key: 'invoice_prefix', type: 'string' },
    { key: 'stock_alert_threshold', type: 'number' },
    { key: 'default_payment_terms_days', type: 'number' },
    { key: 'allow_backorders', type: 'boolean' },
    { key: 'low_stock_notify', type: 'boolean' },
    { key: 'interview_hours', type: 'string' },
    { key: 'observation_hours', type: 'string' },
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
    { key: 'default_broadcast_tz', type: 'select', options: [
      { value: 'Africa/Kinshasa', labelKey: 'settings.options.tzKinshasa' },
      { value: 'Africa/Dar_es_Salaam', labelKey: 'settings.options.tzDar' },
      { value: 'UTC', labelKey: 'settings.options.tzUtc' },
    ] },
    { key: 'max_upload_mb', type: 'number' },
    { key: 'content_review_required', type: 'boolean' },
  ],
}

export const settingDefaults: Record<string, Record<string, string>> = {
  general: {
    group_name: 'LALI Holdings',
    legal_name: 'LALI Holdings SAS',
    support_email: 'samuel.w@example.com',
    default_locale: 'fr',
    default_timezone: 'Africa/Kinshasa',
    date_format: 'DD/MM/YYYY',
    week_start: '1',
  },
  finance: {
    decimal_places: '2',
    fiscal_year_start_month: '1',
    tax_inclusive_prices: '0',
    fx_spread_percent: '1.5',
  },
  notifications: {
    email_enabled: '0',
    sms_enabled: '0',
    inapp_enabled: '1',
    digest_hour: '8',
  },
  security: {
    password_min_length: '10',
    password_require_mixed: '1',
    session_lifetime_minutes: '120',
    force_password_reset_days: '90',
    audit_retention_days: '365',
  },
  kams: {
    order_prefix: 'KA',
    invoice_prefix: 'KAF',
    stock_alert_threshold: '5',
    default_payment_terms_days: '30',
    allow_backorders: '0',
    low_stock_notify: '1',
  },
  'heavy-equipment': {
    default_rental_days: '7',
    maintenance_interval_hours: '250',
    require_operator_license: '1',
  },
  'real-estate': {
    default_lease_months: '12',
    occupancy_alert_percent: '80',
    late_rent_grace_days: '5',
  },
  'rice-mills': {
    standard_bag_kg: '50',
    moisture_max_percent: '14',
    mill_shift_hours: '8',
  },
  'lali-tv': {
    default_broadcast_tz: 'Africa/Kinshasa',
    max_upload_mb: '512',
    content_review_required: '1',
  },
}
