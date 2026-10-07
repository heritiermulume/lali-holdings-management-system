import { groupName } from './format'

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
    --lhms: #043711;
    --lhms-deep: #02250b;
    --lhms-soft: #e8f3ea;
    --lime: #4e9a2c;
    --ink: #122018;
    --muted: #5d6b62;
    --line: #e4ece6;
    --surface: #f4f7f5;
  }
  * { box-sizing: border-box; }
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
    flex-shrink: 0;
    width: 52px;
    height: 52px;
    padding: 4px;
    border-radius: 12px;
    background: var(--lhms);
    border: 1px solid rgba(255,255,255,0.18);
    overflow: hidden;
  }
  .mark img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
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

function printLogoSrc() {
  if (typeof window === 'undefined') return '/lali_holdings_logo.png'
  return `${window.location.origin}/lali_holdings_logo.png`
}

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
  const brand = escapeHtml(groupName() || 'LHMS')
  const logoSrc = escapeHtml(printLogoSrc())
  // Single @page rule (browsers often ignore a later override when printing from iframes).
  const pageCss = options.orientation === 'landscape'
    ? '@page { size: A4 landscape; margin: 10mm 12mm 12mm; }'
    : '@page { size: A4 portrait; margin: 12mm 12mm 14mm; }'

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${title} · LHMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>${pageCss}${PRINT_STYLES}</style>
</head>
<body>
  <div class="sheet">
    <header class="hero">
      <div class="brand">
        <div class="mark"><img src="${logoSrc}" alt="${brand}" /></div>
        <div>
          <p class="eyebrow">${brand}</p>
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
        <span>${brand}</span>
        <span>${escapeHtml(options.footerNote || 'Document interne · usage confidentiel')}</span>
      </footer>
    </main>
  </div>
</body>
</html>`
}

export function printHtml(html: string) {
  // Zero-size iframes make Chromium/Safari ignore @page landscape; keep a real off-screen box.
  const iframe = document.createElement('iframe')
  iframe.setAttribute('aria-hidden', 'true')
  iframe.setAttribute('title', 'print')
  Object.assign(iframe.style, {
    position: 'fixed',
    top: '0',
    left: '0',
    width: '297mm',
    height: '210mm',
    border: '0',
    opacity: '0',
    pointerEvents: 'none',
    zIndex: '-1',
  })
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
  const cleanup = () => {
    iframe.remove()
    frame?.removeEventListener?.('afterprint', cleanup)
  }
  frame.addEventListener?.('afterprint', cleanup)
  const waitForImages = Promise.all(
    Array.from(doc.images).map((img) => (
      img.complete
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
          img.onload = () => resolve()
          img.onerror = () => resolve()
        })
    )),
  )
  void waitForImages.then(() => {
    window.setTimeout(() => {
      frame.focus()
      frame.print()
      window.setTimeout(cleanup, 60_000)
    }, 100)
  })
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

export type ServiceCardOptions = {
  companyName: string
  fullName: string
  role: string
  office: string
  photoUrl?: string | null
}

export function buildServiceCardHtml(options: ServiceCardOptions) {
  const company = escapeHtml(options.companyName || 'King Attitude Traders')
  const fullName = escapeHtml(options.fullName)
  const role = escapeHtml(options.role || '—')
  const office = escapeHtml(options.office || '—')
  const photo = options.photoUrl
    ? `<img src="${escapeHtml(options.photoUrl)}" alt="${fullName}" />`
    : `<div class="avatar-fallback">${escapeHtml(options.fullName.trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase() || 'KA')}</div>`

  const pattern = `url("data:image/svg+xml,${encodeURIComponent(`
    <svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">
      <g fill="none" stroke="#0c6a34" stroke-width="1" opacity="0.28">
        <path d="M0 40 H120 M0 80 H120 M40 0 V120 M80 0 V120"/>
      </g>
      <g fill="#4e9a2c" opacity="0.18">
        <circle cx="40" cy="40" r="1.8"/>
        <circle cx="80" cy="40" r="1.8"/>
        <circle cx="40" cy="80" r="1.8"/>
        <circle cx="80" cy="80" r="1.8"/>
      </g>
      <g fill="none" stroke="#4e9a2c" stroke-width="1.1" opacity="0.22">
        <path d="M24 24h16v16H24z M80 80h16v16H80z"/>
        <path d="M84 28l12 12-12 12-12-12z"/>
      </g>
    </svg>
  `.replace(/\s+/g, ' ').trim())}")`

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${fullName} · Carte de service</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet" />
  <style>
    :root {
      --lhms: #043711;
      --lhms-deep: #02250b;
      --lime: #4e9a2c;
      --ink: #122018;
    }
    * { box-sizing: border-box; }
    @page { size: A4 portrait; margin: 12mm; }
    html, body {
      margin: 0;
      padding: 0;
      background: #d5e0d8;
      color: var(--ink);
      font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .sheet {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 28px;
      padding: 28px 24px 40px;
    }
    .page-label {
      margin: 0 0 8px;
      text-align: center;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--lhms);
    }
    .card {
      width: 96mm;
      height: 62mm;
      border-radius: 18px;
      overflow: hidden;
      position: relative;
      color: white;
      box-shadow: 0 24px 60px rgba(18,32,24,0.28);
      background:
        ${pattern},
        radial-gradient(circle at 100% 0%, rgba(78,154,44,0.28), transparent 46%),
        linear-gradient(145deg, var(--lhms-deep) 0%, var(--lhms) 55%, #0c6a34 100%);
      background-size: 48px 48px, auto, auto;
    }
    .card::before {
      content: "";
      position: absolute;
      inset: 0;
      background: repeating-linear-gradient(
        -32deg,
        rgba(12,106,52,0.12) 0 1px,
        transparent 1px 11px
      );
      pointer-events: none;
    }
    .card::after {
      content: "";
      position: absolute;
      inset: 8px;
      border: 1px solid rgba(255,255,255,0.14);
      border-radius: 14px;
      pointer-events: none;
    }
    .card-inner {
      position: relative;
      z-index: 1;
      height: 100%;
      padding: 16px 14px 14px;
      display: grid;
      grid-template-rows: auto 1fr;
      gap: 8px;
    }
    .card-back .card-inner {
      place-items: center;
      text-align: center;
      padding: 18px 18px 16px;
      gap: 10px;
    }
    .header {
      text-align: center;
      padding-top: 4px;
    }
    .title {
      margin: 0;
      text-align: center;
      font-family: "Cormorant Garamond", "Times New Roman", serif;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: #f4faf5;
      line-height: 1.05;
    }
    .company {
      margin: 6px 0 0;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(232,243,234,0.82);
    }
    .body {
      display: grid;
      grid-template-columns: 22mm 1fr;
      gap: 12px;
      align-items: center;
      min-height: 0;
    }
    .photo {
      width: 22mm;
      height: 28mm;
      border-radius: 12px;
      overflow: hidden;
      background: rgba(6,53,26,0.35);
      border: 1px solid rgba(255,255,255,0.16);
      display: grid;
      place-items: center;
      box-shadow: 0 8px 18px rgba(0,0,0,0.18);
    }
    .photo img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .avatar-fallback {
      font-size: 16px;
      font-weight: 800;
      letter-spacing: 0.04em;
    }
    .meta { min-width: 0; }
    .name {
      margin: 0;
      font-size: 15px;
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.15;
    }
    .role {
      margin: 6px 0 0;
      display: inline-flex;
      align-items: center;
      max-width: 100%;
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(6,53,26,0.28);
      border: 1px solid rgba(78,154,44,0.35);
      font-size: 10px;
      font-weight: 700;
      color: #e8f3ea;
    }
    .office {
      margin: 8px 0 0;
      font-size: 11px;
      font-weight: 600;
      color: rgba(232,243,234,0.9);
    }
    .mark {
      min-width: 54px;
      height: 42px;
      padding: 0 10px;
      border-radius: 14px;
      display: grid;
      place-items: center;
      background: rgba(6,53,26,0.35);
      border: 1px solid rgba(255,255,255,0.16);
      font-family: "Cormorant Garamond", "Times New Roman", serif;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.08em;
    }
    .back-company {
      margin: 0;
      font-family: "Cormorant Garamond", "Times New Roman", serif;
      font-size: 22px;
      font-weight: 700;
      letter-spacing: 0.04em;
      line-height: 1.1;
    }
    .back-text {
      margin: 0;
      max-width: 72mm;
      font-size: 10px;
      line-height: 1.45;
      font-weight: 600;
      color: rgba(232,243,234,0.88);
    }
    .back-note {
      margin: 0;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(232,243,234,0.7);
    }
    .strip {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 5px;
      background: linear-gradient(90deg, var(--lhms-deep), var(--lime), #0c6a34);
    }
    @media print {
      html, body { background: white; }
      .sheet { padding: 0; gap: 0; }
      .page-label { display: none; }
      .face {
        min-height: 100vh;
        display: grid;
        place-items: center;
        page-break-after: always;
        break-after: page;
      }
      .face:last-child {
        page-break-after: auto;
        break-after: auto;
      }
      .card { box-shadow: none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="face">
      <p class="page-label">Recto</p>
      <div class="card card-front">
        <div class="card-inner">
          <div class="header">
            <p class="title">Carte de service</p>
            <p class="company">${company}</p>
          </div>
          <div class="body">
            <div class="photo">${photo}</div>
            <div class="meta">
              <h1 class="name">${fullName}</h1>
              <div class="role">${role}</div>
              <p class="office">${office}</p>
            </div>
          </div>
        </div>
        <div class="strip"></div>
      </div>
    </div>
    <div class="face">
      <p class="page-label">Verso</p>
      <div class="card card-back">
        <div class="card-inner">
          <div class="mark">K.A.T.</div>
          <p class="back-company">${company}</p>
          <p class="back-text">Cette carte identifie un collaborateur autorisé de ${company}. Elle reste la propriété de l'entreprise et doit être présentée sur demande.</p>
          <p class="back-note">Si trouvée, merci de la retourner au bureau</p>
        </div>
        <div class="strip"></div>
      </div>
    </div>
  </div>
</body>
</html>`
}
