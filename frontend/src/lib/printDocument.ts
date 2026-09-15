function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[char] ?? char))
}

export type PrintMeta = { label: string; value: string }

export type PrintTableOptions = {
  title: string
  module?: string
  subtitle?: string
  meta?: PrintMeta[]
  headers: string[]
  rows: Array<Array<string | number | null | undefined>>
  emptyLabel?: string
  footerNote?: string
  orientation?: 'portrait' | 'landscape'
}

const PRINT_STYLES = `
  :root {
    --lhms: #094924;
    --lhms-deep: #06351a;
    --lhms-soft: #e8f3ea;
    --lime: #4e9a2c;
    --ink: #122018;
    --muted: #5d6b62;
    --line: #e4ece6;
    --surface: #f4f7f5;
  }
  * { box-sizing: border-box; }
  @page { margin: 12mm 12mm 14mm; }
  @page landscape { size: A4 landscape; }
  html, body {
    margin: 0;
    padding: 0;
    background: white;
    color: var(--ink);
    font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .sheet { min-height: 100%; }
  .hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 22px;
    background: linear-gradient(135deg, var(--lhms-deep) 0%, var(--lhms) 58%, #0c6a34 100%);
    color: white;
  }
  .brand {
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .mark {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.18);
  }
  .eyebrow {
    margin: 0;
    font-size: 10px;
    letter-spacing: 0.32em;
    font-weight: 700;
    color: rgba(255,255,255,0.62);
    text-transform: uppercase;
  }
  .brand-title {
    margin: 4px 0 0;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.03em;
  }
  .hero-meta {
    text-align: right;
    font-size: 11px;
    color: rgba(255,255,255,0.78);
    line-height: 1.5;
  }
  .accent { height: 4px; background: var(--lime); }
  .body { padding: 22px 22px 16px; }
  .doc-head { margin-bottom: 16px; }
  .module {
    margin: 0 0 4px;
    color: var(--lhms);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  h1 {
    margin: 0;
    color: var(--lhms);
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.03em;
  }
  .subtitle {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
  }
  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
  }
  .chip {
    background: var(--lhms-soft);
    color: var(--lhms);
    border: 1px solid #d5e8da;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 11px;
    font-weight: 600;
  }
  .chip span { color: var(--muted); font-weight: 500; margin-right: 4px; }
  table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 11px;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 12px;
  }
  thead th {
    background: var(--lhms);
    color: white;
    font-weight: 700;
    text-align: left;
    padding: 10px 12px;
    white-space: nowrap;
  }
  tbody td {
    padding: 9px 12px;
    border-top: 1px solid var(--line);
    color: var(--ink);
    vertical-align: top;
  }
  tbody tr:nth-child(even) td { background: var(--lhms-soft); }
  tbody tr:nth-child(odd) td { background: white; }
  .empty {
    text-align: center;
    color: var(--muted);
    padding: 28px 12px !important;
    background: var(--surface) !important;
  }
  .foot {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 18px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
    color: var(--muted);
    font-size: 10px;
  }
  @media print {
    html, body { background: white; }
    .hero { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
`

const MARK_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 64 64" fill="none" aria-hidden="true">
  <path d="M18 18h8v28h-8V18zm12 0h16c6 0 10 4 10 10s-4 10-10 10H38v8h-8V18zm8 8v6h6c2 0 4-1.4 4-3s-2-3-4-3h-6z" fill="#FFFFFF"/>
</svg>`

type BrandedDocumentOptions = {
  title: string
  module?: string
  subtitle?: string
  meta?: PrintMeta[]
  body: string
  footerNote?: string
  orientation?: 'portrait' | 'landscape'
}

export function buildBrandedDocumentHtml(options: BrandedDocumentOptions) {
  const title = escapeHtml(options.title)
  const module = escapeHtml(options.module || 'LHMS')
  const subtitle = options.subtitle ? `<p class="subtitle">${escapeHtml(options.subtitle)}</p>` : ''
  const chips = (options.meta ?? []).map((item) => (
    `<div class="chip"><span>${escapeHtml(item.label)}</span>${escapeHtml(item.value)}</div>`
  )).join('')
  const generated = new Date().toLocaleString()
  const pageCss = options.orientation === 'landscape' ? '@page { size: A4 landscape; margin: 10mm 12mm 12mm; }' : ''

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${title} — LHMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>${pageCss}${PRINT_STYLES}</style>
</head>
<body>
  <div class="sheet">
    <header class="hero">
      <div class="brand">
        <div class="mark">${MARK_SVG}</div>
        <div>
          <p class="eyebrow">LALI Holdings</p>
          <p class="brand-title">LHMS</p>
        </div>
      </div>
      <div class="hero-meta">
        <div>${module}</div>
        <div>${escapeHtml(generated)}</div>
      </div>
    </header>
    <div class="accent"></div>
    <main class="body">
      <div class="doc-head">
        <p class="module">${module}</p>
        <h1>${title}</h1>
        ${subtitle}
        ${chips ? `<div class="chips">${chips}</div>` : ''}
      </div>
      ${options.body}
      <footer class="foot">
        <span>LALI Holdings Management System</span>
        <span>${escapeHtml(options.footerNote || 'Document interne — usage confidentiel')}</span>
      </footer>
    </main>
  </div>
</body>
</html>`
}

export function printHtml(html: string) {
  const iframe = document.createElement('iframe')
  iframe.setAttribute('aria-hidden', 'true')
  Object.assign(iframe.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' })
  document.body.appendChild(iframe)
  const doc = iframe.contentDocument
  if (!doc || !iframe.contentWindow) {
    iframe.remove()
    return
  }
  doc.open()
  doc.write(html)
  doc.close()
  const frame = iframe.contentWindow
  window.setTimeout(() => {
    frame?.focus()
    frame?.print()
    window.setTimeout(() => iframe.remove(), 2000)
  }, 400)
}

export function printBrandedDocument(options: BrandedDocumentOptions) {
  printHtml(buildBrandedDocumentHtml(options))
}

export function buildBrandedTableHtml(options: PrintTableOptions) {
  const headers = options.headers.map((label) => `<th>${escapeHtml(label)}</th>`).join('')
  const body = options.rows.length
    ? options.rows.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(String(cell ?? ''))}</td>`).join('')}</tr>`).join('')
    : `<tr><td class="empty" colspan="${Math.max(options.headers.length, 1)}">${escapeHtml(options.emptyLabel || '—')}</td></tr>`

  return buildBrandedDocumentHtml({
    title: options.title,
    module: options.module,
    subtitle: options.subtitle,
    meta: options.meta,
    footerNote: options.footerNote,
    orientation: options.orientation,
    body: `<table><thead><tr>${headers}</tr></thead><tbody>${body}</tbody></table>`,
  })
}

export function printBrandedTable(options: PrintTableOptions) {
  printHtml(buildBrandedTableHtml(options))
}
