// Shared role catalog + rank hierarchy for subordinate switching.
// Mirrors dashboard-v2 ROLE_RANK: superadmin > owner > manager > staff peers > tenant = partner.
export const ROLES = [
  { id: 'superadmin', role: 'Super Admin', ico: '👑', email: 'belal000bd@gmail.com', desc: 'Full platform access' },
  { id: 'owner', role: 'Property Owner', ico: '🏠', email: 'owner@krtaker.com', desc: 'Portfolio-wide view across every building' },
  { id: 'manager', role: 'Property Manager', ico: '🗝️', email: 'manager@krtaker.com', desc: 'Day-to-day ops on assigned properties' },
  { id: 'svc_mgr', role: 'Service Manager', ico: '✅', email: 'svc_mgr@krtaker.com', desc: 'Quality control & SLA across partners' },
  { id: 'legal', role: 'Legal Counsel', ico: '⚖️', email: 'legal@krtaker.com', desc: 'Registrations, PRCA cases, compliance docket' },
  { id: 'crm', role: 'CRM & Help Desk', ico: '🎧', email: 'crm@krtaker.com', desc: 'Tickets, CSAT, tenant onboarding, leads' },
  { id: 'accountant', role: 'Accountant', ico: '💰', email: 'accountant@krtaker.com', desc: 'Cash flow, TDS, invoices, aging' },
  { id: 'hr', role: 'HR & Admin', ico: '👥', email: 'hr@krtaker.com', desc: 'Staff, onboarding, org admin' },
  { id: 'tenant', role: 'Tenant', ico: '🔑', email: 'tenant@krtaker.com', desc: 'Invoices, receipts, repairs — your side' },
  { id: 'partner', role: 'Service Partner', ico: '🛠️', email: 'partner@krtaker.com', desc: 'Jobs, QC feedback, payouts' },
]

export const ROLE_RANK = { superadmin: 100, owner: 90, manager: 80, svc_mgr: 60, legal: 60, crm: 60, accountant: 60, hr: 60, tenant: 20, partner: 20 }

export const roleLabel = (id) => ROLES.find(r => r.id === id)?.role || id || '—'

// Can `myRole` view-as `targetId`? Strictly lower rank, and target must exist.
export const canViewAs = (myRole, targetId) => {
  const me = ROLE_RANK[myRole] ?? 0
  const them = ROLE_RANK[targetId]
  return them !== undefined && them > 0 && them < me
}
