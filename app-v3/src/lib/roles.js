// KRTaker three-group access model.
//   Admin      (super_admin > admin)                    → can view-as ANY user (except admin-group superior/peer)
//   Subscriber (property_owner > property_manager > tenant > building_staff)   → same group, strictly below
//   Backoffice (hr_admin = legal = accountant > crm = service_manager > service_partner > service_staff) → same group, strictly below
// Keys are the DB role ids (legacy + new taxonomy aliases map to the same entry).

export const GROUP_LABEL = { admin: 'Admin', sub: 'Subscriber', bo: 'Backoffice' }

export const HIERARCHY = {
  superadmin: { g: 'admin', r: 2 }, super_admin: { g: 'admin', r: 2 },
  admin: { g: 'admin', r: 1 },
  owner: { g: 'sub', r: 4 }, property_owner: { g: 'sub', r: 4 },
  manager: { g: 'sub', r: 3 }, property_manager: { g: 'sub', r: 3 },
  tenant: { g: 'sub', r: 2 },
  building_staff: { g: 'sub', r: 1 },
  hr: { g: 'bo', r: 4 }, hr_admin: { g: 'bo', r: 4 },
  legal: { g: 'bo', r: 4 }, legal_counsel: { g: 'bo', r: 4 },
  accountant: { g: 'bo', r: 4 },
  crm: { g: 'bo', r: 3 }, crm_helpdesk: { g: 'bo', r: 3 },
  svc_mgr: { g: 'bo', r: 3 }, service_manager: { g: 'bo', r: 3 },
  partner: { g: 'bo', r: 2 }, service_partner: { g: 'bo', r: 2 },
  service_staff: { g: 'bo', r: 1 },
}

// Switchable accounts (existing logins). Keep in display order.
export const ROLES = [
  { id: 'owner', role: 'Property Owner', ico: '🏠', email: 'owner@krtaker.com', group: 'sub', desc: 'Portfolio-wide view across every building' },
  { id: 'manager', role: 'Property Manager', ico: '🗝️', email: 'manager@krtaker.com', group: 'sub', desc: 'Day-to-day ops on assigned properties' },
  { id: 'tenant', role: 'Tenant', ico: '🔑', email: 'tenant@krtaker.com', group: 'sub', desc: 'Invoices, receipts, repairs — your side' },
  { id: 'hr', role: 'HR & Admin', ico: '👥', email: 'hr@krtaker.com', group: 'bo', desc: 'Staff, onboarding, org admin' },
  { id: 'legal', role: 'Legal Counsel', ico: '⚖️', email: 'legal@krtaker.com', group: 'bo', desc: 'Registrations, PRCA cases, compliance docket' },
  { id: 'accountant', role: 'Accountant', ico: '💰', email: 'accountant@krtaker.com', group: 'bo', desc: 'Cash flow, TDS, invoices, aging' },
  { id: 'crm', role: 'CRM & Help Desk', ico: '🎧', email: 'crm@krtaker.com', group: 'bo', desc: 'Tickets, CSAT, tenant onboarding, leads' },
  { id: 'svc_mgr', role: 'Service Manager', ico: '✅', email: 'svc_mgr@krtaker.com', group: 'bo', desc: 'Quality control & SLA across partners' },
  { id: 'partner', role: 'Service Partner', ico: '🛠️', email: 'partner@krtaker.com', group: 'bo', desc: 'Jobs, QC feedback, payouts' },
]

export const roleLabel = (id) => ROLES.find(r => r.id === id)?.role || id || '—'
export const roleGroup = (id) => HIERARCHY[id]?.g || ''

// May `myRole` view-as `targetId`? Three-group rules (mirrors server app-view-as).
export const canViewAs = (myRole, targetId) => {
  const me = HIERARCHY[myRole]
  const them = HIERARCHY[targetId]
  if (!me || !them) return false
  if (me.g === 'admin') return !(them.g === 'admin' && them.r >= me.r)
  return me.g === them.g && them.r < me.r
}
