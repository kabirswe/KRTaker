// Mall Manager Global Search — client-side index over the app-bootstrap payload (data.db).
// Indexes the MALL collections (shops, bills, staff, assets, complaints, notices)
// + static quick commands. Instant, offline-capable, zero backend round-trip.

// Field spec per collection:
//   group  — label shown in the results dropdown
//   ic     — emoji icon
//   tab    — MallView tab to open on selection
//   fields — array of [value, weight] pairs; weight 2 = id/code (prefix match), 1 = name/contact
//   sub    — function(row, db) → subtitle string
const SPECS = [
  { coll: 'shops', group: 'Spaces', ic: '🏪', tab: 'shops',
    fields: [['no', 2], ['owner_name', 1], ['owner_mobile', 1], ['owner_nid', 1], ['floor', 1], ['status', 1], ['id', 2]],
    sub: (r) => [r.floor, r.owner_name, r.owner_mobile, r.status, '৳' + Number(r.service_rate || 0).toLocaleString('en-IN') + '/mo'].filter(Boolean).join(' · ') },
  { coll: 'shop_bills', group: 'Bills & Collections', ic: '🧾', tab: 'bills',
    fields: [['id', 2], ['month', 1], ['kind', 1], ['status', 1], ['shop', 1]],
    sub: (r, db) => {
      const s = (db.shops || []).find(x => x.id === r.shop)
      return [(s && s.no) || r.shop, r.kind, r.month, r.status, '৳' + Number(r.amount || 0).toLocaleString('en-IN')].filter(Boolean).join(' · ')
    } },
  { coll: 'mall_staff', group: 'Staff', ic: '🧑‍💼', tab: 'staff',
    fields: [['name', 1], ['designation', 1], ['phone', 1], ['nid', 1], ['id', 2], ['status', 1]],
    sub: (r) => [r.designation, r.phone, r.status, '৳' + Number(r.salary || 0).toLocaleString('en-IN') + '/mo'].filter(Boolean).join(' · ') },
  { coll: 'mall_assets', group: 'Assets & AMC', ic: '🛠️', tab: 'assets',
    fields: [['name', 1], ['type', 1], ['location', 1], ['vendor', 1], ['id', 2], ['status', 1]],
    sub: (r) => [r.type, r.location, r.vendor, r.contract_until ? 'AMC till ' + r.contract_until : '', r.status].filter(Boolean).join(' · ') },
  { coll: 'mall_complaints', group: 'Complaints', ic: '🔧', tab: 'complaints',
    fields: [['subject', 1], ['descr', 1], ['priority', 1], ['status', 1], ['id', 2]],
    sub: (r, db) => {
      const s = (db.shops || []).find(x => x.id === r.shop)
      return [(s && s.no) || r.shop, r.priority, r.status].filter(Boolean).join(' · ')
    } },
  { coll: 'mall_notices', group: 'Notices', ic: '📢', tab: 'notices',
    fields: [['title', 1], ['body', 1], ['id', 2], ['date', 1]],
    sub: (r) => [r.date, r.author, r.pinned ? '📌 pinned' : ''].filter(Boolean).join(' · ') },
]

// Static quick commands — type an action like "generate" or "add shop".
const COMMANDS = [
  ['⚙️ Generate monthly bills', 'Create service-charge bills for all active shops', 'bills'],
  ['💸 Compute late fees', 'Apply late payment fines to overdue bills', 'bills'],
  ['🏪 Add a Space', 'Register a new space with owner & rate', 'shops'],
  ['⚡ Enter meter reading', 'Sub-meter reading → auto elec/water bill', 'meters'],
  ['📉 Record an expense', 'Lift / DESCO / security / salary expense entry', 'expenses'],
  ['🔧 Log a complaint', 'Report a shop issue (lift, AC, light…)', 'complaints'],
  ['🛠️ Add an asset', 'Lift, generator, extinguisher + AMC tracking', 'assets'],
  ['🧑‍💼 Add staff', 'Security guard / office staff + salary', 'staff'],
  ['💸 Pay a salary', 'Monthly salary payment to staff', 'staff'],
  ['👥 Add a system user', 'Create owner / manager / accountant / collector', 'users'],
  ['📢 Post a notice', 'Committee announcement for shop owners', 'notices'],
  ['📋 Audit trail', 'Who did what, when', 'audit'],
  ['🏪 Per-shop ledger', 'Paid vs billed for every shop', 'ledger'],
  ['⚡💧 Custodial reconciliation', 'DESCO/WASA collected vs paid', 'ledger'],
  ['⚙️ Mall settings', 'Profile, billing rules, bank, receipt', 'settings'],
  ['👤 My profile', 'Name, password, preferences', 'settings'],
]

// Case-insensitive token match; id/code fields rank first (prefix), others substring.
function scoreRow(row, spec, tokens) {
  let score = 0
  for (const [f, w] of spec.fields) {
    const v = row[f]
    if (v == null) continue
    const s = String(v).toLowerCase()
    if (!s) continue
    for (const t of tokens) {
      if (s === t) score += 4 * w
      else if (s.startsWith(t)) score += 3 * w
      else if (s.includes(t)) score += 1.5 * w
    }
  }
  return score
}

// Escape + wrap query tokens in <b class="gs-hl"> for the results list (safe HTML).
export function hlHtml(text, q) {
  const esc = String(text ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
  const tokens = (q || '').trim().toLowerCase().split(/\s+/).filter(Boolean)
  if (!tokens.length) return esc
  return esc.replace(new RegExp('(' + tokens.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'gi'), '<b class="gs-hl">$1</b>')
}

// Search data.db collections → grouped, scored, capped results + quick commands.
// Returns [{ group, ic, tab, kind: 'data'|'cmd', items: [{ id, title, sub, cmd }] }] (only groups with hits).
export function globalSearch(db, q, maxPerGroup = 5) {
  const qs = (q || '').trim()
  if (qs.length < 2) return []
  const tokens = qs.toLowerCase().split(/\s+/).filter(Boolean)
  const out = []
  for (const spec of SPECS) {
    const rows = Array.isArray(db[spec.coll]) ? db[spec.coll] : []
    if (!rows.length) continue
    const scored = []
    for (const row of rows) {
      const sc = scoreRow(row, spec, tokens)
      if (sc > 0) scored.push({ row, sc })
    }
    if (!scored.length) continue
    scored.sort((a, b) => b.sc - a.sc || String(a.row.id || '').localeCompare(String(b.row.id || '')))
    const items = scored.slice(0, maxPerGroup).map(({ row }) => ({
      id: row.id ?? '',
      title: String(row[spec.fields[0][0]] ?? row.id ?? ''),
      sub: spec.sub(row, db),
      tab: spec.tab,
    }))
    out.push({ group: spec.group, ic: spec.ic, tab: spec.tab, kind: 'data', items })
  }
  // quick commands
  const cmds = []
  for (const [title, desc, tab] of COMMANDS) {
    const s = (title + ' ' + desc).toLowerCase()
    if (tokens.every(t => s.includes(t))) cmds.push({ title, sub: desc, tab, cmd: true })
  }
  if (cmds.length) out.push({ group: 'Quick actions', ic: '⚡', tab: '', kind: 'cmd', items: cmds.slice(0, 6) })
  return out
}

// Navigation target for a result — jumps into the Mall module on the right tab.
export function searchTarget(grp, item) {
  return { path: '/mall', query: { tab: item.tab || grp.tab || 'dashboard' } }
}

// Recent searches (localStorage, max 5)
const RECENT_KEY = 'mm_recent_searches'
export function getRecent() {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]') } catch (e) { return [] }
}
export function addRecent(q) {
  const qs = (q || '').trim().slice(0, 60)
  if (!qs) return
  const list = getRecent().filter(x => x !== qs)
  list.unshift(qs)
  try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 5))) } catch (e) {}
}

export const SEARCH_HINT = 'Search shops, bills, staff, assets…  (Ctrl+K)'
