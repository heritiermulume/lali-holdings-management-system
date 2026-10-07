import { Camera, ChevronDown, History, Home, Info, Package, PackagePlus, Pencil, Scale, Trash2, Truck } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'

type Office = { id: number; company_id: number; name: string; code: string; city?: { name?: string } | null }
type Province = { id: number; name: string; name_fr?: string }
type Product = {
  id: number
  name: string
  sku?: string | null
  price: number
  cost: number
  field_price?: number
  company_price?: number
  stock: number
  image_path?: string | null
  image_url?: string | null
  balances?: Array<{ id: number; quantity: number; depot_id?: number; depot?: { id: number; name: string; kind: string } | null }>
}
type Balance = { id: number; quantity: number; condition?: string; product?: Product | null }
type Location = {
  id: number
  company_id?: number
  name: string
  code?: string | null
  kind: string
  status: string
  notes?: string | null
  office_id?: number | null
  province_id?: number | null
  office?: { id: number; name: string; code?: string; city?: { name?: string } | null } | null
  province?: Province | null
  balances?: Balance[]
}

type Adjustment = {
  id: number
  reference: string
  type: string
  quantity: number
  quantity_before: number
  quantity_after: number
  reason: string
  adjusted_at?: string | null
  creator?: { id: number; name: string } | null
}

type Party = { id: number; name: string; warehouse_ids?: number[] }
type Lookups = {
  offices: Office[]
  provinces: Province[]
  warehouses: Location[]
  depots: Location[]
  products: Product[]
  suppliers?: Party[]
  can_choose_office: boolean
  can_manage_warehouses: boolean
}

function locationUnits(row: Location) {
  return (row.balances || []).filter((item) => (item.condition || 'available') === 'available').reduce((sum, item) => sum + (item.quantity || 0), 0)
}

function ProductThumb({ name, url, className = 'h-11 w-11' }: { name: string; url?: string | null; className?: string }) {
  return (
    <span className={cn('grid shrink-0 overflow-hidden place-items-center rounded-lg bg-lhms-soft text-lhms', className)}>
      {url ? <img src={url} alt={name} className="h-full w-full object-cover" /> : <Package size={18} />}
    </span>
  )
}

function suppliersForLocation(suppliers: Party[], locationId?: number | null) {
  if (!locationId) return suppliers
  const linked = suppliers.filter((supplier) => (supplier.warehouse_ids || []).includes(locationId))
  return linked.length > 0 ? linked : suppliers
}

function assignedLocationIds(product: Product, kind: 'warehouse' | 'depot') {
  return (product.balances || [])
    .filter((item) => item.depot?.kind === kind || (!item.depot && item.depot_id && kind === 'depot'))
    .map((item) => item.depot?.id || item.depot_id)
    .filter((id): id is number => typeof id === 'number')
}

function assignedDepotIds(product: Product) {
  return assignedLocationIds(product, 'depot')
}

function assignedWarehouseIds(product: Product) {
  return assignedLocationIds(product, 'warehouse')
}

type WarehouseProductRow = {
  id: number | string
  quantity: number
  condition?: string
  product: Product
}

/** Products linked to a warehouse from DB balances / product assignments (no static catalog). */
function productsForWarehouse(warehouse: Location, catalog: Product[]): WarehouseProductRow[] {
  const balanceByProductId = new Map<number, Balance>()
  for (const balance of warehouse.balances || []) {
    const productId = balance.product?.id
    if (!productId || (balance.condition || 'available') !== 'available') continue
    balanceByProductId.set(productId, balance)
  }

  const rows: WarehouseProductRow[] = []
  const seen = new Set<number>()

  for (const product of catalog) {
    const balance = balanceByProductId.get(product.id)
    const assigned = assignedWarehouseIds(product).includes(warehouse.id)
    if (!balance && !assigned) continue
    seen.add(product.id)
    rows.push({
      id: balance?.id ?? `product-${product.id}`,
      quantity: balance?.quantity ?? 0,
      condition: balance?.condition || 'available',
      product,
    })
  }

  for (const [productId, balance] of balanceByProductId) {
    if (seen.has(productId) || !balance.product) continue
    rows.push({
      id: balance.id,
      quantity: balance.quantity,
      condition: balance.condition,
      product: balance.product,
    })
  }

  return rows.sort((a, b) => a.product.name.localeCompare(b.product.name))
}

export function KamsWarehouses() {
  const { t, i18n } = useTranslation()
  const { hasPermission, user } = useAuth()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Location[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [lookups, setLookups] = useState<Lookups | null>(null)
  const [openWarehouseIds, setOpenWarehouseIds] = useState<number[]>([])
  const [open, setOpen] = useState(false)
  const [receiveOpen, setReceiveOpen] = useState(false)
  const [productOpen, setProductOpen] = useState(false)
  const [editingProduct, setEditingProduct] = useState<Product | null>(null)
  const [deletingProduct, setDeletingProduct] = useState<Product | null>(null)
  const [target, setTarget] = useState<Location | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [productForm, setProductForm] = useState<Record<string, string>>({})
  const [selectedWarehouseIds, setSelectedWarehouseIds] = useState<number[]>([])
  const [assignAllWarehouses, setAssignAllWarehouses] = useState(true)
  const [selectedDepotIds, setSelectedDepotIds] = useState<number[]>([])
  const [assignAllDepots, setAssignAllDepots] = useState(true)
  const [imageFile, setImageFile] = useState<File | null>(null)
  const [imagePreview, setImagePreview] = useState<string | null>(null)
  const [removeImage, setRemoveImage] = useState(false)
  const [error, setError] = useState('')
  const [productError, setProductError] = useState('')
  const [transferTarget, setTransferTarget] = useState<{ warehouse: Location; product: Product; quantity: number } | null>(null)
  const [transferForm, setTransferForm] = useState<Record<string, string>>({})
  const [transferError, setTransferError] = useState('')
  const [transferLines, setTransferLines] = useState<Array<{ to_depot_id: string; quantity: string }>>([{ to_depot_id: '', quantity: '1' }])
  const imageInput = useRef<HTMLInputElement>(null)

  const loadWarehouses = () => api.get('/modules/kams/depots', { params: { kind: 'warehouse', per_page: 100 } }).then((res) => setRows(res.data.data || []))
  const loadProducts = () => api.get('/modules/kams/products', { params: { per_page: 200 } }).then((res) => setProducts(res.data.data || []))

  useEffect(() => {
    void loadWarehouses()
    void loadProducts()
    api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
  }, [])

  const resetProductForm = () => {
    setEditingProduct(null)
    setProductForm({})
    setSelectedWarehouseIds((lookups?.warehouses || []).map((row) => row.id))
    setAssignAllWarehouses(true)
    setSelectedDepotIds([])
    setAssignAllDepots(true)
    setImageFile(null)
    setImagePreview(null)
    setRemoveImage(false)
    setProductError('')
  }

  const openCreateProduct = (warehouse?: Location) => {
    resetProductForm()
    const allIds = (lookups?.warehouses || []).map((row) => row.id)
    const warehouseIds = warehouse ? [warehouse.id] : allIds
    setSelectedWarehouseIds(warehouseIds)
    setAssignAllWarehouses(!warehouse && allIds.length > 0)
    setProductForm({ warehouse_id: warehouse ? String(warehouse.id) : (allIds[0] ? String(allIds[0]) : '') })
    setProductOpen(true)
  }

  const openEditProduct = (product: Product) => {
    const warehouseIds = assignedWarehouseIds(product)
    const depotIds = assignedDepotIds(product)
    const allWarehouseIds = (lookups?.warehouses || []).map((row) => row.id)
    const allDepotIds = (lookups?.depots || []).map((depot) => depot.id)
    setEditingProduct(product)
    setProductForm({
      name: product.name,
      sku: product.sku || '',
      price: String(product.field_price || product.price || ''),
      cost: String(product.company_price || product.cost || ''),
    })
    setSelectedWarehouseIds(warehouseIds)
    setAssignAllWarehouses(allWarehouseIds.length > 0 && allWarehouseIds.every((id) => warehouseIds.includes(id)))
    setSelectedDepotIds(depotIds)
    setAssignAllDepots(allDepotIds.length > 0 && allDepotIds.every((id) => depotIds.includes(id)))
    setImageFile(null)
    setImagePreview(product.image_url || null)
    setRemoveImage(false)
    setProductError('')
    setProductOpen(true)
  }

  const toggleWarehouseOpen = (id: number) => {
    setOpenWarehouseIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))
  }

  const toggleWarehouse = (id: number) => {
    setAssignAllWarehouses(false)
    setSelectedWarehouseIds((current) => {
      const next = current.includes(id) ? current.filter((item) => item !== id) : [...current, id]
      if (!next.map(String).includes(productForm.warehouse_id || '') && next[0]) {
        setProductForm((form) => ({ ...form, warehouse_id: String(next[0]) }))
      }
      return next
    })
  }

  const toggleDepot = (id: number) => {
    setAssignAllDepots(false)
    setSelectedDepotIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/depots', {
        kind: 'warehouse',
        name: form.name,
        code: form.code || null,
        province_id: form.province_id ? Number(form.province_id) : null,
        notes: form.notes || null,
        status: 'active',
      })
      setOpen(false)
      setForm({})
      void loadWarehouses()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const receive = async (event: FormEvent) => {
    event.preventDefault()
    if (!target) return
    if (!form.product_id) {
      setError(t('validation.required'))
      return
    }
    setError('')
    try {
      await api.post('/modules/kams/stock/receipts', {
        warehouse_id: target.id,
        product_id: Number(form.product_id),
        quantity: Number(form.quantity),
        supplier_name: form.supplier_name || null,
        notes: form.notes || null,
        validate: true,
      })
      setReceiveOpen(false)
      setForm({})
      void loadWarehouses()
      void loadProducts()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const submitProduct = async (event: FormEvent) => {
    event.preventDefault()
    setProductError('')
    const companyId = lookups?.warehouses[0]?.company_id || user?.primary_company?.id
    const warehouseIds = assignAllWarehouses ? (lookups?.warehouses || []).map((row) => row.id) : selectedWarehouseIds
    const depotIds = assignAllDepots ? (lookups?.depots || []).map((depot) => depot.id) : selectedDepotIds
    const initialWarehouseId = productForm.warehouse_id
      ? Number(productForm.warehouse_id)
      : warehouseIds[0]

    try {
      if (imageFile || (editingProduct && removeImage)) {
        const payload = new FormData()
        if (!editingProduct && companyId) payload.append('company_id', String(companyId))
        payload.append('name', productForm.name || '')
        if (productForm.sku) payload.append('sku', productForm.sku)
        payload.append('price', productForm.price || '0')
        payload.append('field_price', productForm.price || '0')
        payload.append('company_price', productForm.cost || '0')
        payload.append('cost', productForm.cost || '0')
        if (!editingProduct) {
          payload.append('stock', productForm.stock || '0')
          if (initialWarehouseId) payload.append('warehouse_id', String(initialWarehouseId))
        }
        payload.append('assign_all_warehouses', assignAllWarehouses ? '1' : '0')
        warehouseIds.forEach((id) => payload.append('warehouse_ids[]', String(id)))
        payload.append('assign_all_depots', assignAllDepots ? '1' : '0')
        depotIds.forEach((id) => payload.append('depot_ids[]', String(id)))
        if (imageFile) payload.append('image', imageFile)
        if (editingProduct && removeImage && !imageFile) payload.append('remove_image', '1')

        if (editingProduct) {
          payload.append('_method', 'PUT')
          await api.post(`/modules/kams/products/${editingProduct.id}`, payload)
        } else {
          await api.post('/modules/kams/products', payload)
        }
      } else {
        const body = {
          company_id: companyId,
          name: productForm.name,
          sku: productForm.sku || null,
          price: Number(productForm.price || 0),
          field_price: Number(productForm.price || 0),
          company_price: Number(productForm.cost || 0),
          cost: Number(productForm.cost || 0),
          stock: productForm.stock ? Number(productForm.stock) : 0,
          warehouse_id: initialWarehouseId || undefined,
          assign_all_warehouses: assignAllWarehouses,
          warehouse_ids: warehouseIds,
          assign_all_depots: assignAllDepots,
          depot_ids: depotIds,
        }
        if (editingProduct) {
          const { company_id: _c, stock: _s, warehouse_id: _w, ...updateBody } = body
          await api.put(`/modules/kams/products/${editingProduct.id}`, updateBody)
        } else {
          await api.post('/modules/kams/products', body)
        }
      }
      setProductOpen(false)
      resetProductForm()
      void loadProducts()
      void loadWarehouses()
      api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setProductError(response?.message || t('kams.saveError'))
    }
  }

  const confirmDeleteProduct = async () => {
    if (!deletingProduct) return
    setProductError('')
    try {
      await api.delete(`/modules/kams/products/${deletingProduct.id}`)
      setDeletingProduct(null)
      void loadProducts()
      void loadWarehouses()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setProductError(response?.message || t('kams.saveError'))
    }
  }

  const openTransfer = (warehouse: Location, product: Product, quantity: number) => {
    setTransferTarget({ warehouse, product, quantity })
    setTransferForm({ notes: '' })
    setTransferLines([{ to_depot_id: '', quantity: String(Math.min(1, quantity) || 1) }])
    setTransferError('')
  }

  const openReplenish = (warehouse: Location, product?: Product) => {
    setTarget(warehouse)
    setForm({
      product_id: product ? String(product.id) : '',
      quantity: '1',
      supplier_id: '',
      supplier_name: '',
      unit_cost: product?.cost != null ? String(product.cost) : '',
    })
    setError('')
    setReceiveOpen(true)
  }

  const submitTransfer = async (event: FormEvent) => {
    event.preventDefault()
    if (!transferTarget) return
    setTransferError('')
    const lines = transferLines
      .filter((line) => line.to_depot_id && Number(line.quantity) > 0)
      .map((line) => ({ to_depot_id: Number(line.to_depot_id), quantity: Number(line.quantity) }))
    if (lines.length === 0) {
      setTransferError(t('kams.transferDestinationRequired'))
      return
    }
    const total = lines.reduce((sum, line) => sum + line.quantity, 0)
    if (total > transferTarget.quantity) {
      setTransferError(t('kams.transferQtyExceeded', { available: transferTarget.quantity }))
      return
    }
    const duplicate = new Set(lines.map((line) => line.to_depot_id)).size !== lines.length
    if (duplicate) {
      setTransferError(t('kams.transferDuplicateDestination'))
      return
    }
    try {
      for (const line of lines) {
        await api.post('/modules/kams/stock/transfers', {
          from_depot_id: transferTarget.warehouse.id,
          to_depot_id: line.to_depot_id,
          notes: transferForm.notes || null,
          auto_receive: true,
          items: [{ product_id: transferTarget.product.id, quantity: line.quantity }],
        })
      }
      setTransferTarget(null)
      setTransferForm({})
      setTransferLines([{ to_depot_id: '', quantity: '1' }])
      void loadWarehouses()
      void loadProducts()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setTransferError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted">{t('kams.warehousesHint')}</p>
        {lookups?.can_manage_warehouses && hasPermission('kams.products.create') ? (
          <Button className="shrink-0 self-end sm:self-auto" onClick={() => { setForm({}); setError(''); setOpen(true) }}>{t('kams.addWarehouse')}</Button>
        ) : null}
      </div>
      <div className="grid gap-3">
        {rows.map((row) => {
          const expanded = openWarehouseIds.includes(row.id)
          const warehouseProducts = productsForWarehouse(row, products)
          const warehouseUnits = warehouseProducts.reduce((sum, item) => sum + (item.quantity || 0), 0)
          return (
            <Card key={row.id} className="overflow-hidden">
              <button
                type="button"
                onClick={() => toggleWarehouseOpen(row.id)}
                aria-expanded={expanded}
                className="flex w-full items-start justify-between gap-3 p-5 text-left"
              >
                <div className="flex min-w-0 items-start gap-3">
                  <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms">
                    <Home size={24} strokeWidth={2.25} />
                  </span>
                  <div className="min-w-0">
                    <div className="font-semibold">{row.name}</div>
                    <div className="text-sm text-muted">
                      {row.code || '—'} · {i18n.language.startsWith('fr') ? row.province?.name_fr || row.province?.name : row.province?.name || t('kams.noRegion')}
                    </div>
                    <div className="mt-1 text-xs text-muted">
                      {t('kams.products')}: {warehouseProducts.length}
                      {' · '}
                      {t('kams.quantity')}: {warehouseUnits}
                    </div>
                  </div>
                </div>
                <ChevronDown size={18} className={cn('mt-1 shrink-0 text-muted transition', expanded ? 'rotate-0' : '-rotate-90')} />
              </button>
              {expanded ? (
                <div className="border-t border-line bg-surface px-5 py-4">
                  {warehouseProducts.length === 0 ? (
                    <p className="text-sm text-muted">{t('common.empty')}</p>
                  ) : (
                    <div className="space-y-3">
                      {warehouseProducts.map((item) => (
                        <div key={item.id} className="grid grid-cols-[2.75rem_minmax(0,1fr)] items-start gap-x-3 gap-y-1 rounded-xl border border-line bg-white px-3 py-3 text-sm sm:flex sm:items-center sm:gap-3">
                          <ProductThumb name={item.product.name || ''} url={item.product.image_url} />
                          <div className="min-w-0 sm:flex-1">
                            <div className="text-base font-bold">
                              {item.product.name}
                              {item.condition && item.condition !== 'available' ? ` (${item.condition})` : ''}
                            </div>
                            <div className="mt-0.5 flex items-baseline justify-between gap-2 text-xs text-muted">
                              <span>
                                {item.product.sku ? `${item.product.sku} · ` : ''}
                                {t('kams.salePrice')} {money(item.product.field_price || item.product.price)}
                              </span>
                              <span className="shrink-0 text-sm font-semibold text-ink sm:hidden">{item.quantity}</span>
                            </div>
                          </div>
                          <span className="hidden shrink-0 font-semibold sm:inline">{item.quantity}</span>
                          <div className="col-start-2 flex flex-wrap items-center gap-1 sm:col-auto sm:shrink-0">
                            {lookups?.can_manage_warehouses && hasPermission('kams.stock.update') ? (
                              <button
                                type="button"
                                aria-label={t('kams.replenishStock')}
                                title={t('kams.replenishStock')}
                                className="inline-flex h-9 w-9 items-center justify-center gap-1.5 rounded-lg text-sm text-ink hover:bg-surface md:w-auto md:px-3"
                                onClick={() => openReplenish(row, item.product)}
                              >
                                <PackagePlus size={16} />
                                <span className="hidden md:inline">{t('kams.replenishStock')}</span>
                              </button>
                            ) : null}
                            {hasPermission('kams.stock.update') && item.quantity > 0 ? (
                              <button
                                type="button"
                                aria-label={t('kams.transferStock')}
                                title={t('kams.transferStock')}
                                className="inline-flex h-9 w-9 items-center justify-center gap-1.5 rounded-lg text-sm text-ink hover:bg-surface md:w-auto md:px-3"
                                onClick={() => openTransfer(row, item.product, item.quantity)}
                              >
                                <Truck size={16} />
                                <span className="hidden md:inline">{t('kams.transferStock')}</span>
                              </button>
                            ) : null}
                            {hasPermission('kams.products.update') ? (
                              <button
                                type="button"
                                aria-label={t('common.edit')}
                                title={t('common.edit')}
                                className="inline-flex h-9 w-9 items-center justify-center gap-1.5 rounded-lg text-sm text-ink hover:bg-surface md:w-auto md:px-3"
                                onClick={() => {
                                  const catalog = products.find((product) => product.id === item.product.id) || item.product
                                  openEditProduct(catalog)
                                }}
                              >
                                <Pencil size={16} />
                                <span className="hidden md:inline">{t('common.edit')}</span>
                              </button>
                            ) : null}
                            {hasPermission('kams.products.delete') ? (
                              <button
                                type="button"
                                aria-label={t('common.delete')}
                                title={t('common.delete')}
                                className="inline-flex h-9 w-9 items-center justify-center gap-1.5 rounded-lg text-sm text-danger hover:bg-surface md:w-auto md:px-3"
                                onClick={() => { setProductError(''); setDeletingProduct(item.product) }}
                              >
                                <Trash2 size={16} />
                                <span className="hidden md:inline">{t('common.delete')}</span>
                              </button>
                            ) : null}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                  <div className="mt-4 flex flex-wrap items-center gap-2">
                    {hasPermission('kams.products.create') ? (
                      <Button onClick={() => openCreateProduct(row)}>{t('kams.addProduct')}</Button>
                    ) : null}
                    {lookups?.can_manage_warehouses && hasPermission('kams.stock.update') ? (
                      <Button
                        variant="secondary"
                        onClick={() => openReplenish(row)}
                      >
                        {t('kams.receiveStock')}
                      </Button>
                    ) : null}
                    {hasPermission('kams.products.view') || hasPermission('kams.stock.warehouses.view') || hasPermission('kams.stock.depots.view') ? (
                      <>
                        {(lookups?.can_manage_warehouses && hasPermission('kams.stock.update')) || hasPermission('kams.products.create') ? (
                          <span className="mx-1 hidden h-8 w-px bg-line sm:inline-block" aria-hidden />
                        ) : null}
                        <Button
                          variant="secondary"
                          onClick={() => navigate(`/modules/kams/stock/warehouses/${row.id}/movements`)}
                        >
                          <History size={16} />
                          {t('kams.movements')}
                        </Button>
                      </>
                    ) : null}
                  </div>
                </div>
              ) : null}
            </Card>
          )
        })}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal open={open} title={t('kams.newWarehouse')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <Field label={t('kams.depotCode')} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <Select label={t('kams.region')} value={form.province_id || ''} onChange={(e) => setForm({ ...form, province_id: e.target.value })}>
            <option value="">—</option>
            {(lookups?.provinces || []).map((province) => (
              <option key={province.id} value={province.id}>{i18n.language.startsWith('fr') ? province.name_fr || province.name : province.name}</option>
            ))}
          </Select>
          <Field label={t('kams.notes')} value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal
        open={receiveOpen}
        title={form.product_id ? t('kams.replenishStock') : t('kams.receiveStock')}
        onClose={() => setReceiveOpen(false)}
      >
        <form className="space-y-3" onSubmit={(e) => void receive(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">{target?.name}</p>
          <SearchCombobox
            label={t('kams.supplier')}
            value={
              form.supplier_id
                ? (() => {
                    const supplier = (lookups?.suppliers || []).find((item) => String(item.id) === form.supplier_id)
                    return supplier ? { id: String(supplier.id), label: supplier.name } : (form.supplier_name ? { id: form.supplier_id, label: form.supplier_name } : null)
                  })()
                : null
            }
            items={suppliersForLocation(lookups?.suppliers || [], target?.id).map((supplier): SearchComboboxItem => ({
              id: String(supplier.id),
              label: supplier.name,
            }))}
            placeholder={t('kams.searchSuppliers')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => {
              if (!item) {
                setForm({ ...form, supplier_id: '', supplier_name: '' })
                return
              }
              setForm({ ...form, supplier_id: item.id, supplier_name: item.label })
            }}
          />
          <SearchCombobox
            label={t('kams.product')}
            value={
              form.product_id
                ? (() => {
                    const product = products.find((item) => String(item.id) === form.product_id)
                    return product
                      ? { id: String(product.id), label: product.name, hint: product.sku || undefined }
                      : null
                  })()
                : null
            }
            items={products.map((product): SearchComboboxItem => ({
              id: String(product.id),
              label: product.name,
              hint: product.sku || undefined,
            }))}
            placeholder={t('kams.searchProducts')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => {
              if (!item) {
                setForm({ ...form, product_id: '', unit_cost: '' })
                return
              }
              const product = products.find((row) => String(row.id) === item.id)
              setForm({
                ...form,
                product_id: item.id,
                unit_cost: product?.cost != null ? String(product.cost) : form.unit_cost || '',
              })
            }}
          />
          <Field label={t('kams.quantity')} type="number" min="1" value={form.quantity || ''} onChange={(e) => setForm({ ...form, quantity: e.target.value })} required />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={productOpen} title={editingProduct ? t('kams.editProduct') : t('kams.newProduct')} onClose={() => { setProductOpen(false); resetProductForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submitProduct(e)}>
          {productError ? <p className="text-sm text-red-600">{productError}</p> : null}
          <div className="flex flex-col items-center gap-2 pb-1">
            <div className="relative h-24 w-24">
              <button
                type="button"
                onClick={() => imageInput.current?.click()}
                aria-label={t('kams.productImage')}
                className="block h-24 w-24 overflow-hidden rounded-2xl border border-line bg-lhms-soft p-0 text-lhms transition hover:border-lhms"
              >
                {imagePreview ? (
                  <img src={imagePreview} alt={productForm.name || t('kams.products')} className="h-full w-full object-cover" />
                ) : (
                  <span className="grid h-full w-full place-items-center">
                    <Package size={32} />
                  </span>
                )}
              </button>
              {imagePreview ? (
                <button
                  type="button"
                  aria-label={t('kams.removeImage')}
                  className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-danger p-0 text-white shadow-md ring-2 ring-white transition hover:bg-[#a31818]"
                  onClick={() => {
                    setImageFile(null)
                    setImagePreview((current) => {
                      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
                      return null
                    })
                    setRemoveImage(true)
                    if (imageInput.current) imageInput.current.value = ''
                  }}
                >
                  <Trash2 size={14} />
                </button>
              ) : null}
              <button
                type="button"
                aria-label={t('kams.productImage')}
                className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-lhms p-0 text-white shadow-md ring-2 ring-white transition hover:bg-lhms-deep"
                onClick={() => imageInput.current?.click()}
              >
                <Camera size={14} />
              </button>
            </div>
            <input
              ref={imageInput}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(event) => {
                const file = event.target.files?.[0] || null
                setImageFile(file)
                setRemoveImage(false)
                setImagePreview((current) => {
                  if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
                  return file ? URL.createObjectURL(file) : (editingProduct?.image_url || null)
                })
                event.target.value = ''
              }}
            />
          </div>
          <Field label={t('org.name')} value={productForm.name || ''} onChange={(e) => setProductForm({ ...productForm, name: e.target.value })} required />
          <Field label="SKU" value={productForm.sku || ''} onChange={(e) => setProductForm({ ...productForm, sku: e.target.value })} />
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('kams.purchasePrice')} type="number" min="0" step="0.01" value={productForm.cost || ''} onChange={(e) => setProductForm({ ...productForm, cost: e.target.value })} required />
            <Field label={t('kams.salePrice')} type="number" min="0" step="0.01" value={productForm.price || ''} onChange={(e) => setProductForm({ ...productForm, price: e.target.value })} required />
          </div>
          {!editingProduct ? (
            <>
              <div className="grid grid-cols-2 gap-3">
                <Field label={t('kams.initialStock')} type="number" min="0" value={productForm.stock || ''} onChange={(e) => setProductForm({ ...productForm, stock: e.target.value })} />
              </div>
              <p className="text-xs text-muted">{t('kams.initialStockHint')}</p>
              {(assignAllWarehouses ? (lookups?.warehouses || []).map((row) => row.id) : selectedWarehouseIds).length > 1 ? (
                <Select label={t('kams.initialStockWarehouse')} value={productForm.warehouse_id || ''} onChange={(e) => setProductForm({ ...productForm, warehouse_id: e.target.value })}>
                  <option value="">—</option>
                  {(lookups?.warehouses || [])
                    .filter((row) => assignAllWarehouses || selectedWarehouseIds.includes(row.id))
                    .map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
                </Select>
              ) : null}
            </>
          ) : null}
          <div className="space-y-2 rounded-xl border border-line p-3">
            <div className="flex items-center justify-between gap-3">
              <div className="text-sm font-medium">{t('kams.assignWarehouses')}</div>
              <label className="flex items-center gap-2 text-sm text-muted">
                <input
                  type="checkbox"
                  checked={assignAllWarehouses}
                  onChange={(e) => {
                    setAssignAllWarehouses(e.target.checked)
                    if (e.target.checked) {
                      const ids = (lookups?.warehouses || []).map((row) => row.id)
                      setSelectedWarehouseIds(ids)
                      if (ids[0]) setProductForm((form) => ({ ...form, warehouse_id: String(ids[0]) }))
                    }
                  }}
                />
                {t('kams.allWarehouses')}
              </label>
            </div>
            <div className="max-h-40 space-y-2 overflow-y-auto">
              {(lookups?.warehouses || []).map((warehouse) => (
                <label key={warehouse.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={assignAllWarehouses || selectedWarehouseIds.includes(warehouse.id)}
                    disabled={assignAllWarehouses}
                    onChange={() => toggleWarehouse(warehouse.id)}
                  />
                  <span>{warehouse.name}{warehouse.code ? ` · ${warehouse.code}` : ''}</span>
                </label>
              ))}
              {(lookups?.warehouses || []).length === 0 ? <p className="text-xs text-muted">{t('kams.noWarehousesYet')}</p> : null}
            </div>
          </div>
          <div className="space-y-2 rounded-xl border border-line p-3">
            <div className="flex items-center justify-between gap-3">
              <div className="text-sm font-medium">{t('kams.assignDepots')}</div>
              <label className="flex items-center gap-2 text-sm text-muted">
                <input
                  type="checkbox"
                  checked={assignAllDepots}
                  onChange={(e) => {
                    setAssignAllDepots(e.target.checked)
                    if (e.target.checked) setSelectedDepotIds((lookups?.depots || []).map((depot) => depot.id))
                  }}
                />
                {t('kams.allDepots')}
              </label>
            </div>
            <div className="max-h-40 space-y-2 overflow-y-auto">
              {(lookups?.depots || []).map((depot) => (
                <label key={depot.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={assignAllDepots || selectedDepotIds.includes(depot.id)}
                    disabled={assignAllDepots}
                    onChange={() => toggleDepot(depot.id)}
                  />
                  <span>{depot.name}{depot.office?.name ? ` · ${depot.office.name}` : ''}</span>
                </label>
              ))}
              {(lookups?.depots || []).length === 0 ? <p className="text-xs text-muted">{t('kams.noDepotsYet')}</p> : null}
            </div>
          </div>
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(deletingProduct)} title={t('kams.deleteProduct')} onClose={() => setDeletingProduct(null)}>
        <div className="space-y-4">
          {productError ? <p className="text-sm text-red-600">{productError}</p> : null}
          <p className="text-sm text-muted">{t('kams.deleteProductConfirm', { name: deletingProduct?.name })}</p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeletingProduct(null)}>{t('common.cancel')}</Button>
            <Button onClick={() => void confirmDeleteProduct()}>{t('common.delete')}</Button>
          </div>
        </div>
      </Modal>

      <Modal open={Boolean(transferTarget)} title={t('kams.transferStockTitle')} onClose={() => setTransferTarget(null)}>
        <form className="space-y-3" onSubmit={(e) => void submitTransfer(e)}>
          {transferError ? <p className="text-sm text-red-600">{transferError}</p> : null}
          <p className="text-sm text-muted">
            <span className="text-base font-bold text-ink">{transferTarget?.product.name}</span>
            {' · '}{transferTarget?.warehouse.name}
            {transferTarget ? ` · ${t('kams.availableQty')}: ${transferTarget.quantity}` : ''}
          </p>
          {transferLines.map((line, index) => {
            const selectedElsewhere = new Set(
              transferLines
                .filter((_, i) => i !== index)
                .map((item) => item.to_depot_id)
                .filter(Boolean),
            )
            return (
              <div key={index} className="grid gap-2 sm:grid-cols-[1fr_100px_auto]">
                <Select
                  label={t('kams.transferDestination')}
                  value={line.to_depot_id}
                  onChange={(e) => setTransferLines((current) => current.map((item, i) => i === index ? { ...item, to_depot_id: e.target.value } : item))}
                  required
                >
                  <option value="">—</option>
                  <optgroup label={t('kams.warehouses')}>
                    {(lookups?.warehouses || [])
                      .filter((row) => row.id !== transferTarget?.warehouse.id)
                      .map((row) => (
                        <option
                          key={`w-${row.id}`}
                          value={row.id}
                          disabled={selectedElsewhere.has(String(row.id))}
                        >
                          {row.name}
                        </option>
                      ))}
                  </optgroup>
                  <optgroup label={t('kams.depot')}>
                    {(lookups?.depots || []).map((row) => (
                      <option
                        key={`d-${row.id}`}
                        value={row.id}
                        disabled={selectedElsewhere.has(String(row.id))}
                      >
                        {row.name}{row.office?.name ? ` · ${row.office.name}` : ''}
                      </option>
                    ))}
                  </optgroup>
                </Select>
                <Field
                  label={t('kams.quantity')}
                  type="number"
                  min="1"
                  max={String(transferTarget?.quantity || 1)}
                  value={line.quantity}
                  onChange={(e) => setTransferLines((current) => current.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))}
                  required
                />
                {transferLines.length > 1 ? (
                  <button
                    type="button"
                    className="mt-6 text-sm text-danger"
                    onClick={() => setTransferLines((current) => current.filter((_, i) => i !== index))}
                  >
                    {t('kams.removeDestination')}
                  </button>
                ) : <span />}
              </div>
            )
          })}
          <button
            type="button"
            className="text-sm font-medium text-lhms"
            onClick={() => setTransferLines((current) => [...current, { to_depot_id: '', quantity: '1' }])}
          >
            {t('kams.addTransferDestination')}
          </button>
          <Field
            label={t('kams.notes')}
            value={transferForm.notes || ''}
            onChange={(e) => setTransferForm({ ...transferForm, notes: e.target.value })}
          />
          <p className="text-xs text-muted">{t('kams.transferStockHint')}</p>
          <Button type="submit" className="w-full">{t('kams.transferStock')}</Button>
        </form>
      </Modal>
    </div>
  )
}

export function KamsDepots() {
  const { t, i18n } = useTranslation()
  const { hasPermission, user } = useAuth()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Location[]>([])
  const [lookups, setLookups] = useState<Lookups | null>(null)
  const [openDepotIds, setOpenDepotIds] = useState<number[]>([])
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<Location | null>(null)
  const [receiveOpen, setReceiveOpen] = useState(false)
  const [receiveTarget, setReceiveTarget] = useState<Location | null>(null)
  const [receiveForm, setReceiveForm] = useState<Record<string, string>>({})
  const [receiveError, setReceiveError] = useState('')
  const [infoProduct, setInfoProduct] = useState<{ product: Product; quantity: number; depotName: string; depotId: number } | null>(null)
  const [infoAdjustments, setInfoAdjustments] = useState<Adjustment[]>([])
  const [supplyTarget, setSupplyTarget] = useState<{ depot: Location; product: Product } | null>(null)
  const [adjustTarget, setAdjustTarget] = useState<{ location: Location; product: Product; quantity: number } | null>(null)
  const [removeTarget, setRemoveTarget] = useState<{ location: Location; product: Product; quantity: number } | null>(null)
  const [supplyForm, setSupplyForm] = useState<Record<string, string>>({})
  const [adjustForm, setAdjustForm] = useState<Record<string, string>>({})
  const [removeForm, setRemoveForm] = useState<Record<string, string>>({})
  const [form, setForm] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [supplyError, setSupplyError] = useState('')
  const [adjustError, setAdjustError] = useState('')
  const [removeError, setRemoveError] = useState('')
  const [saving, setSaving] = useState(false)

  const load = () => api.get('/modules/kams/depots', { params: { kind: 'depot', per_page: 50 } }).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
  }, [])

  const assignedOfficeId = () => {
    if (user?.primary_office?.id && (lookups?.offices || []).some((office) => office.id === user.primary_office?.id)) {
      return String(user.primary_office.id)
    }
    return lookups?.offices[0] ? String(lookups.offices[0].id) : ''
  }

  const toggleDepot = (id: number) => {
    setOpenDepotIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))
  }

  const openCreate = () => {
    setEditing(null)
    setForm({ office_id: assignedOfficeId() })
    setError('')
    setOpen(true)
  }

  const openEdit = (depot: Location) => {
    setEditing(depot)
    setForm({
      name: depot.name || '',
      code: depot.code || '',
      office_id: depot.office_id ? String(depot.office_id) : assignedOfficeId(),
      notes: depot.notes || '',
      status: depot.status || 'active',
    })
    setError('')
    setOpen(true)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!form.name?.trim()) {
      setError(t('kams.requiredDepotFields'))
      return
    }
    setError('')
    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        code: form.code || null,
        notes: form.notes || null,
        status: form.status || 'active',
        ...(lookups?.can_choose_office ? { office_id: Number(form.office_id || assignedOfficeId()) } : {}),
      }
      if (editing) {
        await api.put(`/modules/kams/depots/${editing.id}`, payload)
      } else {
        await api.post('/modules/kams/depots', {
          kind: 'depot',
          ...payload,
          office_id: Number(lookups?.can_choose_office ? form.office_id : assignedOfficeId()),
        })
      }
      setOpen(false)
      setEditing(null)
      setForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const openInfo = (depot: Location, product: Product, quantity: number) => {
    setInfoProduct({ product, quantity, depotName: depot.name, depotId: depot.id })
    setInfoAdjustments([])
    api.get('/modules/kams/stock/adjustments', {
      params: { depot_id: depot.id, product_id: product.id, per_page: 10 },
    }).then((res) => setInfoAdjustments(res.data.data || [])).catch(() => setInfoAdjustments([]))
  }

  const openReceive = (depot: Location, product?: Product) => {
    setReceiveTarget(depot)
    setReceiveForm({
      product_id: product ? String(product.id) : '',
      quantity: '1',
      supplier_id: '',
      supplier_name: '',
      unit_cost: product?.cost != null ? String(product.cost) : '',
    })
    setReceiveError('')
    setReceiveOpen(true)
  }

  const preferredWarehouseId = (depot: Location) => {
    const warehouses = lookups?.warehouses || []
    if (warehouses.length === 0) return ''
    if (depot.province_id) {
      const regional = warehouses.find((warehouse) => warehouse.province_id === depot.province_id)
      if (regional) return String(regional.id)
    }
    return String(warehouses[0].id)
  }

  const openRemove = (depot: Location, product: Product, quantity: number) => {
    setRemoveTarget({ location: depot, product, quantity })
    setRemoveForm({
      warehouse_id: preferredWarehouseId(depot),
      quantity: String(quantity || 1),
    })
    setRemoveError('')
  }

  const submitReceive = async (event: FormEvent) => {
    event.preventDefault()
    if (!receiveTarget) return
    if (!receiveForm.product_id) {
      setReceiveError(t('validation.required'))
      return
    }
    setReceiveError('')
    try {
      await api.post('/modules/kams/stock/receipts', {
        depot_id: receiveTarget.id,
        product_id: Number(receiveForm.product_id),
        quantity: Number(receiveForm.quantity),
        supplier_name: receiveForm.supplier_name || null,
        validate: true,
      })
      setReceiveOpen(false)
      setReceiveTarget(null)
      setReceiveForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setReceiveError(response?.message || t('kams.saveError'))
    }
  }

  const submitSupply = async (event: FormEvent) => {
    event.preventDefault()
    if (!supplyTarget) return
    setSupplyError('')
    try {
      await api.post('/modules/kams/stock/transfers', {
        from_depot_id: Number(supplyForm.warehouse_id),
        to_depot_id: supplyTarget.depot.id,
        auto_receive: true,
        items: [{ product_id: supplyTarget.product.id, quantity: Number(supplyForm.quantity) }],
      })
      setSupplyTarget(null)
      setSupplyForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setSupplyError(response?.message || t('kams.saveError'))
    }
  }

  const submitAdjust = async (event: FormEvent) => {
    event.preventDefault()
    if (!adjustTarget) return
    setAdjustError('')
    if (!(adjustForm.reason || '').trim()) {
      setAdjustError(t('kams.adjustReasonRequired'))
      return
    }
    try {
      await api.post('/modules/kams/stock/adjustments', {
        depot_id: adjustTarget.location.id,
        product_id: adjustTarget.product.id,
        type: adjustForm.type || 'defective',
        quantity: Number(adjustForm.quantity),
        reason: adjustForm.reason.trim(),
      })
      setAdjustTarget(null)
      setAdjustForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setAdjustError(response?.message || t('kams.saveError'))
    }
  }

  const submitRemove = async (event: FormEvent) => {
    event.preventDefault()
    if (!removeTarget) return
    const quantity = Number(removeForm.quantity)
    const warehouseId = removeForm.warehouse_id || preferredWarehouseId(removeTarget.location)
    if (!warehouseId) {
      setRemoveError(t('kams.noWarehousesYet'))
      return
    }
    if (!quantity || quantity < 1 || quantity > removeTarget.quantity) {
      setRemoveError(t('validation.required'))
      return
    }
    setRemoveError('')
    setSaving(true)
    try {
      await api.post('/modules/kams/stock/returns', {
        type: 'depot_to_warehouse',
        from_depot_id: removeTarget.location.id,
        to_depot_id: Number(warehouseId),
        condition: 'available',
        reason: t('kams.removeFromDepot'),
        items: [{ product_id: removeTarget.product.id, quantity }],
      })
      setRemoveTarget(null)
      setRemoveForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setRemoveError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted">{t('kams.depotsHint')}</p>
        {hasPermission('kams.products.create') ? (
          <Button className="shrink-0 self-end sm:self-auto" onClick={openCreate}>{t('kams.addDepot')}</Button>
        ) : null}
      </div>
      <div className="grid gap-3">
        {rows.map((row) => {
          const isOpen = openDepotIds.includes(row.id)
          const products = (row.balances || []).filter((item) => (item.condition || 'available') === 'available' && item.product && item.quantity > 0)
          return (
            <Card key={row.id} className="overflow-hidden">
              <div className="flex w-full items-start justify-between gap-3 p-5">
                <button
                  type="button"
                  onClick={() => toggleDepot(row.id)}
                  aria-expanded={isOpen}
                  className="flex min-w-0 flex-1 items-start gap-3 text-left"
                >
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms">
                    <Home size={16} />
                  </span>
                  <div className="min-w-0">
                    <div className="font-semibold">{row.name}</div>
                    <div className="text-sm text-muted">{row.office?.name || '—'} · {row.code || '—'}</div>
                    <div className="mt-1 text-xs text-muted">
                      {t('kams.products')}: {products.length}
                      {' · '}
                      {t('kams.quantity')}: {locationUnits(row)}
                    </div>
                  </div>
                </button>
                <div className="flex shrink-0 items-center gap-1">
                  {hasPermission('kams.products.update') ? (
                    <button
                      type="button"
                      aria-label={t('kams.editDepot')}
                      title={t('kams.editDepot')}
                      className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-surface"
                      onClick={() => openEdit(row)}
                    >
                      <Pencil size={16} />
                    </button>
                  ) : null}
                  <button
                    type="button"
                    aria-label={isOpen ? t('common.close') : t('common.view')}
                    title={isOpen ? t('common.close') : t('common.view')}
                    className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface"
                    onClick={() => toggleDepot(row.id)}
                  >
                    <ChevronDown size={18} className={cn('transition', isOpen ? 'rotate-0' : '-rotate-90')} />
                  </button>
                </div>
              </div>
              {isOpen ? (
                <div className="border-t border-line bg-surface px-5 py-4">
                  {products.length === 0 ? (
                    <p className="text-sm text-muted">{t('common.empty')}</p>
                  ) : (
                    <div className="space-y-3">
                      {products.map((item) => (
                        <div key={item.id} className="grid grid-cols-[2.75rem_minmax(0,1fr)] items-start gap-x-3 gap-y-1 text-sm sm:flex sm:items-center sm:gap-3">
                          <ProductThumb name={item.product?.name || ''} url={item.product?.image_url} />
                          <div className="min-w-0 sm:flex-1">
                            <div className="text-base font-bold">
                              {item.product?.name}
                              {item.condition && item.condition !== 'available' ? ` (${item.condition})` : ''}
                            </div>
                            {item.product ? (
                              <div className="mt-0.5 flex items-baseline justify-between gap-2 text-xs text-muted">
                                <span>
                                  {item.product.sku ? `${item.product.sku} · ` : ''}
                                  {t('kams.salePrice')} {money(item.product.field_price || item.product.price)}
                                </span>
                                <span className="shrink-0 text-sm font-semibold text-ink sm:hidden">{item.quantity}</span>
                              </div>
                            ) : null}
                          </div>
                          <span className="hidden shrink-0 font-semibold sm:inline">{item.quantity}</span>
                          <div className="col-start-2 flex flex-wrap items-center gap-1 sm:col-auto sm:shrink-0">
                            <button
                              type="button"
                              aria-label={t('kams.productInfo')}
                              title={t('kams.productInfo')}
                              className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white"
                              onClick={() => item.product && openInfo(row, item.product, item.quantity)}
                            >
                              <Info size={16} />
                            </button>
                            {hasPermission('kams.stock.update') ? (
                              <button
                                type="button"
                                aria-label={t('kams.supplyStock')}
                                title={t('kams.supplyStock')}
                                className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white"
                                onClick={() => {
                                  if (!item.product) return
                                  setSupplyTarget({ depot: row, product: item.product })
                                  setSupplyForm({
                                    warehouse_id: lookups?.warehouses[0] ? String(lookups.warehouses[0].id) : '',
                                    quantity: '1',
                                  })
                                  setSupplyError('')
                                }}
                              >
                                <PackagePlus size={16} />
                              </button>
                            ) : null}
                            {item.product && item.quantity > 0 && hasPermission('kams.stock.update') ? (
                              <button
                                type="button"
                                aria-label={t('kams.removeFromDepot')}
                                title={t('kams.removeFromDepot')}
                                className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white"
                                onClick={() => item.product && openRemove(row, item.product, item.quantity)}
                              >
                                <Truck size={16} />
                              </button>
                            ) : null}
                            {hasPermission('kams.stock.update') ? (
                              <button
                                type="button"
                                aria-label={t('kams.adjustStock')}
                                title={t('kams.adjustStock')}
                                className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white"
                                onClick={() => {
                                  if (!item.product) return
                                  setAdjustTarget({ location: row, product: item.product, quantity: item.quantity })
                                  setAdjustForm({ type: 'defective', quantity: '1', reason: '' })
                                  setAdjustError('')
                                }}
                              >
                                <Scale size={16} />
                              </button>
                            ) : null}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                  <div className="mt-4 flex flex-wrap items-center gap-2">
                    {hasPermission('kams.stock.update') ? (
                      <Button variant="secondary" onClick={() => openReceive(row)}>
                        {t('kams.receiveStock')}
                      </Button>
                    ) : null}
                    {hasPermission('kams.products.view') || hasPermission('kams.stock.warehouses.view') || hasPermission('kams.stock.depots.view') ? (
                      <>
                        {hasPermission('kams.stock.update') ? (
                          <span className="mx-1 hidden h-8 w-px bg-line sm:inline-block" aria-hidden />
                        ) : null}
                        <Button
                          variant="secondary"
                          onClick={() => navigate(`/modules/kams/stock/depots/${row.id}/movements`)}
                        >
                          <History size={16} />
                          {t('kams.movements')}
                        </Button>
                      </>
                    ) : null}
                  </div>
                </div>
              ) : null}
            </Card>
          )
        })}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal
        open={open}
        title={editing ? t('kams.editDepot') : t('kams.newDepot')}
        onClose={() => { setOpen(false); setEditing(null); setForm({}); setError('') }}
      >
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {lookups?.can_choose_office ? (
            <Select
              label={t('kams.office')}
              value={form.office_id || ''}
              disabled={Boolean(editing)}
              onChange={(e) => setForm({ ...form, office_id: e.target.value })}
            >
              <option value="">—</option>
              {(lookups.offices || []).map((office) => <option key={office.id} value={office.id}>{office.name}</option>)}
            </Select>
          ) : null}
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <Field label={t('kams.depotCode')} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          {editing ? (
            <Select label={t('common.status')} value={form.status || 'active'} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              <option value="active">{t('kams.status.active')}</option>
              <option value="inactive">{t('kams.status.inactive')}</option>
            </Select>
          ) : null}
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal
        open={Boolean(removeTarget)}
        title={t('kams.removeFromDepotTitle')}
        onClose={() => { setRemoveTarget(null); setRemoveForm({}); setRemoveError('') }}
      >
        {removeTarget ? (
          <form className="space-y-3" onSubmit={(e) => void submitRemove(e)}>
            {removeError ? <p className="text-sm text-red-600">{removeError}</p> : null}
            <p className="text-sm text-muted">
              {t('kams.removeFromDepotConfirm', {
                product: removeTarget.product.name,
                depot: removeTarget.location.name,
              })}
            </p>
            <p className="text-xs text-muted">{t('kams.removeFromDepotHint')}</p>
            <SearchCombobox
              label={t('kams.warehouse')}
              value={
                removeForm.warehouse_id
                  ? (() => {
                      const warehouse = (lookups?.warehouses || []).find((item) => String(item.id) === removeForm.warehouse_id)
                      return warehouse
                        ? {
                            id: String(warehouse.id),
                            label: warehouse.name,
                            hint: [warehouse.code, warehouse.province ? (i18n.language.startsWith('fr') ? warehouse.province.name_fr || warehouse.province.name : warehouse.province.name) : null].filter(Boolean).join(' · ') || undefined,
                          }
                        : null
                    })()
                  : null
              }
              items={(lookups?.warehouses || []).map((warehouse): SearchComboboxItem => ({
                id: String(warehouse.id),
                label: warehouse.name,
                hint: [warehouse.code, warehouse.province ? (i18n.language.startsWith('fr') ? warehouse.province.name_fr || warehouse.province.name : warehouse.province.name) : null].filter(Boolean).join(' · ') || undefined,
              }))}
              placeholder={t('kams.searchWarehouse')}
              emptyLabel={t('common.empty')}
              onSelect={(item) => setRemoveForm({ ...removeForm, warehouse_id: item?.id ?? '' })}
            />
            <Field
              label={t('kams.quantity')}
              type="number"
              min="1"
              max={removeTarget.quantity}
              value={removeForm.quantity || ''}
              onChange={(e) => setRemoveForm({ ...removeForm, quantity: e.target.value })}
              required
            />
            <div className="flex gap-2">
              <Button type="button" variant="secondary" className="flex-1" onClick={() => { setRemoveTarget(null); setRemoveForm({}); setRemoveError('') }}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" variant="danger" className="flex-1" disabled={saving}>
                {saving ? t('common.loading') : t('kams.removeFromDepot')}
              </Button>
            </div>
          </form>
        ) : null}
      </Modal>

      <Modal
        open={receiveOpen}
        title={t('kams.receiveStock')}
        onClose={() => { setReceiveOpen(false); setReceiveTarget(null) }}
      >
        <form className="space-y-3" onSubmit={(e) => void submitReceive(e)}>
          {receiveError ? <p className="text-sm text-red-600">{receiveError}</p> : null}
          <p className="text-sm text-muted">{receiveTarget?.name}</p>
          <SearchCombobox
            label={t('kams.supplier')}
            value={
              receiveForm.supplier_id
                ? (() => {
                    const supplier = (lookups?.suppliers || []).find((item) => String(item.id) === receiveForm.supplier_id)
                    return supplier
                      ? { id: String(supplier.id), label: supplier.name }
                      : (receiveForm.supplier_name ? { id: receiveForm.supplier_id, label: receiveForm.supplier_name } : null)
                  })()
                : null
            }
            items={(lookups?.suppliers || []).map((supplier): SearchComboboxItem => ({
              id: String(supplier.id),
              label: supplier.name,
            }))}
            placeholder={t('kams.searchSuppliers')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => {
              if (!item) {
                setReceiveForm({ ...receiveForm, supplier_id: '', supplier_name: '' })
                return
              }
              setReceiveForm({ ...receiveForm, supplier_id: item.id, supplier_name: item.label })
            }}
          />
          <SearchCombobox
            label={t('kams.product')}
            value={
              receiveForm.product_id
                ? (() => {
                    const product = (lookups?.products || []).find((item) => String(item.id) === receiveForm.product_id)
                    return product
                      ? { id: String(product.id), label: product.name, hint: product.sku || undefined }
                      : null
                  })()
                : null
            }
            items={(lookups?.products || []).map((product): SearchComboboxItem => ({
              id: String(product.id),
              label: product.name,
              hint: product.sku || undefined,
            }))}
            placeholder={t('kams.searchProducts')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => {
              if (!item) {
                setReceiveForm({ ...receiveForm, product_id: '', unit_cost: '' })
                return
              }
              const product = (lookups?.products || []).find((row) => String(row.id) === item.id)
              setReceiveForm({
                ...receiveForm,
                product_id: item.id,
                unit_cost: product?.cost != null ? String(product.cost) : receiveForm.unit_cost || '',
              })
            }}
          />
          <Field label={t('kams.quantity')} type="number" min="1" value={receiveForm.quantity || ''} onChange={(e) => setReceiveForm({ ...receiveForm, quantity: e.target.value })} required />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(infoProduct)} title={t('kams.productInfo')} onClose={() => setInfoProduct(null)}>
        {infoProduct ? (
          <div className="space-y-4">
            <div className="flex items-start gap-3">
              <ProductThumb name={infoProduct.product.name} url={infoProduct.product.image_url} className="h-16 w-16" />
              <div>
                <div className="font-semibold">{infoProduct.product.name}</div>
                <div className="text-sm text-muted">{infoProduct.depotName}</div>
                {infoProduct.product.sku ? <div className="text-xs text-muted">SKU · {infoProduct.product.sku}</div> : null}
              </div>
            </div>
            <div className="grid gap-2 text-sm sm:grid-cols-2">
              <div>{t('kams.purchasePrice')}: <strong>{money(infoProduct.product.company_price || infoProduct.product.cost)}</strong></div>
              <div>{t('kams.salePrice')}: <strong>{money(infoProduct.product.field_price || infoProduct.product.price)}</strong></div>
              <div>{t('kams.grossProfit')}: <strong>{money(Math.max(0, (infoProduct.product.field_price || infoProduct.product.price) - (infoProduct.product.company_price || infoProduct.product.cost)))}</strong></div>
              <div>{t('kams.quantity')}: <strong>{infoProduct.quantity}</strong></div>
            </div>
            <div className="space-y-2">
              <div className="text-sm font-medium">{t('kams.adjustmentHistory')}</div>
              {infoAdjustments.length === 0 ? (
                <p className="text-xs text-muted">{t('kams.noAdjustments')}</p>
              ) : (
                <div className="max-h-40 space-y-2 overflow-y-auto text-xs">
                  {infoAdjustments.map((row) => (
                    <div key={row.id} className="rounded-lg border border-line bg-surface px-3 py-2">
                      <div className="flex justify-between gap-2 font-medium">
                        <span>{row.reference} · {t(`kams.adjustType.${row.type}`, { defaultValue: row.type })}</span>
                        <span>{row.quantity_before} → {row.quantity_after}</span>
                      </div>
                      <div className="mt-1 text-muted">{row.reason}</div>
                      <div className="mt-1 text-muted">
                        {row.creator?.name || '—'}
                        {row.adjusted_at ? ` · ${new Date(row.adjusted_at).toLocaleString()}` : ''}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <Button className="w-full" variant="secondary" onClick={() => setInfoProduct(null)}>{t('common.close')}</Button>
          </div>
        ) : null}
      </Modal>

      <Modal open={Boolean(supplyTarget)} title={t('kams.supplyStock')} onClose={() => setSupplyTarget(null)}>
        <form className="space-y-3" onSubmit={(e) => void submitSupply(e)}>
          {supplyError ? <p className="text-sm text-red-600">{supplyError}</p> : null}
          <p className="text-sm text-muted">
            {supplyTarget?.product.name} → {supplyTarget?.depot.name}
          </p>
          <Select label={t('kams.fromWarehouse')} value={supplyForm.warehouse_id || ''} onChange={(e) => setSupplyForm({ ...supplyForm, warehouse_id: e.target.value })} required>
            <option value="">—</option>
            {(lookups?.warehouses || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <Field label={t('kams.quantity')} type="number" min="1" value={supplyForm.quantity || ''} onChange={(e) => setSupplyForm({ ...supplyForm, quantity: e.target.value })} required />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(adjustTarget)} title={t('kams.adjustStock')} onClose={() => setAdjustTarget(null)}>
        <form className="space-y-3" onSubmit={(e) => void submitAdjust(e)}>
          {adjustError ? <p className="text-sm text-red-600">{adjustError}</p> : null}
          <p className="text-sm text-muted">
            {adjustTarget?.product.name} · {adjustTarget?.location.name}
            {adjustTarget ? ` · ${t('kams.availableQty')}: ${adjustTarget.quantity}` : ''}
          </p>
          <Select label={t('kams.adjustTypeLabel')} value={adjustForm.type || 'defective'} onChange={(e) => setAdjustForm({ ...adjustForm, type: e.target.value })} required>
            <option value="defective">{t('kams.adjustType.defective')}</option>
            <option value="lost">{t('kams.adjustType.lost')}</option>
            <option value="found">{t('kams.adjustType.found')}</option>
          </Select>
          <Field
            label={t('kams.quantity')}
            type="number"
            min="1"
            max={adjustForm.type === 'found' ? undefined : String(Math.max(1, adjustTarget?.quantity || 1))}
            value={adjustForm.quantity || ''}
            onChange={(e) => setAdjustForm({ ...adjustForm, quantity: e.target.value })}
            required
          />
          <Field
            label={t('kams.reason')}
            value={adjustForm.reason || ''}
            onChange={(e) => setAdjustForm({ ...adjustForm, reason: e.target.value })}
            required
            placeholder={t('kams.adjustReasonPlaceholder')}
          />
          <p className="text-xs text-muted">{t('kams.adjustTraceHint')}</p>
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}
