// KRTaker Global Search — client-side index over the app-bootstrap payload (data.db).
// Every collection maps to a view route + deep-link (?open=<id> or ?q=<term>).
// Instant, offline-capable, zero backend round-trip.

// Field spec per collection:
//   group  — label shown in the results dropdown
//   ic     — emoji icon
//   route  — view path (string) or { path, tab } for tabbed views
//   fields — array of [value, weight] pairs; weight 2 = id/code (prefix match), 1 = name/contact
//   sub    — function(row) → subtitle string
//   tab    — optional function(row) → tab query value for tabbed views
//   openQ  — key to pass as ?open (default 'id'); set null to skip deep-link and use ?q= instead
const SPECS = [
  { coll: 'properties',      group: 'Properties',   ic: '🏢', route: '/properties',   fields: [['name', 1], ['id', 2], ['holding', 1], ['jur', 1], ['type', 1], ['address', 1]],
    sub: r => [r.holding && 'Holding ' + r.holding, r.jur, r.type, r.status].filter(Boolean).join(' · ') },
  { coll: 'units',           group: 'Units',        ic: '🚪', route: '/units',        fields: [['name', 1], ['id', 2], ['p', 1], ['floor', 1], ['status', 1]],
    sub: r => [r.p && 'In ' + r.p, r.floor, r.status, r.rent ? '৳' + Number(r.rent).toLocaleString() : ''].filter(Boolean).join(' · ') },
  { coll: 'tenants',         group: 'Tenants',      ic: '👤', route: '/tenants',      fields: [['name', 1], ['id', 2], ['phone', 1], ['email', 1], ['nid', 1], ['company', 1]],
    sub: r => [r.phone, r.email, r.kind].filter(Boolean).join(' · ') },
  { coll: 'leases',          group: 'Leases',       ic: '📄', route: '/leases',       fields: [['id', 2], ['u', 1], ['t', 1], ['status', 1]],
    sub: r => ['Unit ' + r.u, 'Tenant ' + r.t, r.status, r.rent ? '৳' + Number(r.rent).toLocaleString() : ''].filter(Boolean).join(' · ') },
  { coll: 'invoices',        group: 'Invoices',     ic: '🧾', route: '/invoices',     fields: [['id', 2], ['l', 1], ['m', 1], ['status', 1]],
    sub: r => ['Lease ' + r.l, r.m, r.status, r.net ? '৳' + Number(r.net).toLocaleString() : ''].filter(Boolean).join(' · ') },
  { coll: 'payments',        group: 'Payments',     ic: '💳', route: '/payments',     fields: [['id', 2], ['inv', 1], ['ref', 1], ['method', 1], ['status', 1]],
    sub: r => ['Invoice ' + r.inv, r.method, r.status, r.amount ? '৳' + Number(r.amount).toLocaleString() : ''].filter(Boolean).join(' · ') },
  { coll: 'receipts',        group: 'Receipts',     ic: '📎', route: '/receipts',     fields: [['id', 2], ['inv', 1], ['method', 1]],
    sub: r => ['Invoice ' + r.inv, r.method, r.date, r.amount ? '৳' + Number(r.amount).toLocaleString() : ''].filter(Boolean).join(' · ') },
  { coll: 'maintenance_requests', group: 'Maintenance', ic: '🔧', route: '/maintenance', fields: [['title', 1], ['id', 2], ['desc', 1], ['unit', 1], ['prop', 1], ['status', 1]],
    sub: r => ['Unit ' + r.unit, r.category, r.priority, r.status].filter(Boolean).join(' · ') },
  { coll: 'notices',         group: 'Notices',      ic: '📢', route: '/notices',      fields: [['title', 1], ['id', 2], ['body', 1]],
    sub: r => [r.author, r.ts].filter(Boolean).join(' · ') },
  { coll: 'legal_notices',   group: 'Legal Notices', ic: '📜', route: '/notices',     fields: [['id', 2], ['ntype', 1], ['reason', 1], ['tenant', 1], ['unit', 1], ['status', 1]],
    sub: r => [r.ntype, 'Tenant ' + r.tenant, 'Unit ' + r.unit, r.status].filter(Boolean).join(' · ') },
  { coll: 'staff',           group: 'Staff',        ic: '👷', route: '/staff',        fields: [['name', 1], ['id', 2], ['role', 1], ['dept', 1], ['status', 1]],
    sub: r => [r.role, r.dept, r.status].filter(Boolean).join(' · ') },
  { coll: 'partners',        group: 'Vendors',      ic: '🧰', route: '/vendors',      fields: [['name', 1], ['id', 2], ['trade', 1], ['status', 1]],
    sub: r => [r.trade, r.status, r.rating ? '★' + r.rating : ''].filter(Boolean).join(' · '), openQ: null },
  { coll: 'cases',           group: 'Cases',        ic: '👨‍⚖️', route: '/cases',        fields: [['title', 1], ['id', 2], ['type', 1], ['status', 1], ['ref_lease', 1]],
    sub: r => [r.type, r.status, 'Lease ' + r.ref_lease].filter(Boolean).join(' · ') },
  { coll: 'leads',           group: 'Leads',        ic: '📥', route: '/leads',        fields: [['name', 1], ['id', 2], ['phone', 1], ['email', 1], ['prop', 1], ['status', 1]],
    sub: r => [r.phone || r.email, 'For ' + r.prop, r.status].filter(Boolean).join(' · ') },
  { coll: 'documents',       group: 'Documents',    ic: '📁', route: '/documents',    fields: [['name', 1], ['id', 2], ['kind', 1], ['ref', 1]],
    sub: r => [r.kind, 'Ref ' + r.ref, r.ts].filter(Boolean).join(' · ') },
  { coll: 'compliance_items', group: 'Compliance',  ic: '⚖️', route: '/compliance',   fields: [['label', 1], ['id', 2], ['ref_no', 1], ['entity_id', 1], ['status', 1]],
    sub: r => [r.entity_type + ' ' + r.entity_id, r.ref_no, r.expiry_date, r.status].filter(Boolean).join(' · ') },
  { coll: 'gate_visits',     group: 'Gate Visits',  ic: '🚪', route: '/gate-visits',  fields: [['name', 1], ['id', 2], ['vehicle_no', 1], ['phone', 1], ['unit', 1], ['purpose', 1], ['status', 1]],
    sub: r => [r.vtype, r.vehicle_no, 'Unit ' + r.unit, r.status].filter(Boolean).join(' · ') },
  { coll: 'building_staff',  group: 'Building Staff', ic: '👷', route: '/staff',      fields: [['name', 1], ['id', 2], ['role', 1], ['phone', 1], ['prop', 1], ['status', 1]],
    sub: r => ['Prop ' + r.prop, r.role, r.phone, r.status].filter(Boolean).join(' · ') },
  { coll: 'samity_members',  group: 'Samity',       ic: '🏘️', route: '/society?tab=samity',       fields: [['name', 1], ['id', 2], ['role', 1], ['phone', 1], ['status', 1]],
    sub: r => [r.role, r.phone, r.status].filter(Boolean).join(' · ') },
  { coll: 'insurance_policies', group: 'Insurance', ic: '🛡️', route: '/insurance',    fields: [['id', 2], ['tenant', 1], ['plan', 1], ['status', 1]],
    sub: r => ['Tenant ' + r.tenant, r.plan, r.status].filter(Boolean).join(' · ') },
  { coll: 'holding_taxes',   group: 'Holding Taxes', ic: '🏛️', route: '/holding-taxes', fields: [['id', 2], ['prop', 1], ['year', 1], ['status', 1]],
    sub: r => ['Prop ' + r.prop, r.year, r.status].filter(Boolean).join(' · ') },
  { coll: 'utility_bills',   group: 'Utility Bills', ic: '🔌', route: '/utility-bills', fields: [['id', 2], ['unit', 1], ['month', 1], ['type', 1], ['status', 1]],
    sub: r => [r.type, 'Unit ' + r.unit, r.month, r.status].filter(Boolean).join(' · ') },
  { coll: 'meter_readings',  group: 'Meter Readings', ic: '⚡', route: '/meter-readings', fields: [['id', 2], ['unit', 1], ['month', 1], ['type', 1]],
    sub: r => [r.type, 'Unit ' + r.unit, r.month].filter(Boolean).join(' · ') },
  { coll: 'remittances',     group: 'Remittances',  ic: '🌍', route: '/remittances',  fields: [['id', 2], ['ref', 1], ['method', 1], ['status', 1]],
    sub: r => [r.method, r.status, r.amount ? '৳' + Number(r.amount).toLocaleString() : ''].filter(Boolean).join(' · ') },
  { coll: 'vendor_payouts',  group: 'Payouts',      ic: '💵', route: '/vendors',      fields: [['id', 2], ['partner', 1], ['month', 1], ['status', 1]],
    sub: r => ['Partner ' + r.partner, r.month, r.status].filter(Boolean).join(' · '), openQ: null },
  { coll: 'land_parcels',    group: 'Land',         ic: '🛰️', route: '/land',         fields: [['name', 1], ['id', 2], ['location', 1], ['status', 1]],
    sub: r => [r.location, r.status].filter(Boolean).join(' · ') },
  { coll: 'fire_assets',     group: 'Fire Safety',  ic: '🧯', route: '/fire-safety',  fields: [['name', 1], ['id', 2], ['location', 1], ['status', 1]],
    sub: r => [r.location, r.status].filter(Boolean).join(' · ') },
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

// Search data.db collections → grouped, scored, capped results.
// Returns [{ group, ic, route, query, items: [{ row, title, sub }] }] (only groups with hits).
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
      title: String(spec.fields[0][0] && (row[spec.fields[0][0]] ?? row.id ?? '')),
      sub: spec.sub(row),
    }))
    out.push({ group: spec.group, ic: spec.ic, route: spec.route, openQ: spec.openQ, items })
  }
  return out
}

// Navigation target for a result: { path, query } — deep-links to the record when the view supports ?open=.
export function searchTarget(grp, item) {
  if (grp.openQ === null) return { path: grp.route, query: { q: item.title } }
  return { path: grp.route, query: { open: item.id } }
}

export const SEARCH_HINT = 'Search tenants, units, invoices, maintenance…'
