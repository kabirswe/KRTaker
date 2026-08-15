// Shared UI helpers for app-v3 views (unified badge colors, view-mode toggle).
import { ref, computed, watch } from 'vue'
import { t } from './i18n'

// ── badge colors (single source of truth — tenants-page convention) ──
const BADGE_MAP = {
  // green — good / done / active
  Active: 'b-green', Leased: 'b-green', Paid: 'b-green', Success: 'b-green', Verified: 'b-green',
  Approved: 'b-green', Completed: 'b-green', Resolved: 'b-green', Accepted: 'b-green', Published: 'b-green',
  Delivered: 'b-green', 'Move-in': 'b-green', Collected: 'b-green', Confirmed: 'b-green',
  // gray — neutral / closed / inactive
  Closed: 'b-gray', Expired: 'b-gray', Ended: 'b-gray', Cancelled: 'b-gray', Refunded: 'b-gray',
  Vacant: 'b-gray', Draft: 'b-gray', 'No Dues': 'b-gray', Inactive: 'b-gray', Archived: 'b-gray',
  Released: 'b-gray',
  // red — problem
  Open: 'b-red', Overdue: 'b-red', Terminated: 'b-red', Rejected: 'b-red', Failed: 'b-red', Critical: 'b-red',
  // orange — attention / in-between
  Unpaid: 'b-orange', Partial: 'b-orange', Pending: 'b-orange', 'Pending Registration': 'b-orange',
  'Awaiting Payment': 'b-orange', Maintenance: 'b-orange', Due: 'b-orange', 'Soft Due': 'b-orange', Probation: 'b-orange',
  // blue — in motion / assigned
  'In Progress': 'b-blue', Assigned: 'b-blue', Offered: 'b-blue', Scheduled: 'b-blue', 'Move-out': 'b-orange',
}
export function badge(st) { return BADGE_MAP[st] || 'b-gray' }

// ── avatar / misc helpers ──
export const AV_COLORS = ['#2F80ED', '#27AE60', '#E67E22', '#9B59B6', '#E74C3C', '#16A085', '#8E44AD', '#2980B9']
export function avatarColor(id) { let h = 0; for (const c of String(id)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return AV_COLORS[h % AV_COLORS.length] }
export function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() }
export function maskNid(nid) { return nid ? String(nid).replace(/^(.{4}).*(.{4})$/, '$1••••$2') : '—' }
export function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }
export function fmtSize(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b > 1024 ? Math.round(b / 1024) + ' KB' : (b || 0) + ' B' }
export function fmtTs(ts) { return ts ? String(ts).replace('T', ' ').slice(0, 16) : '—' }
export function monthLabel(m) {
  if (!m) return '—'
  const [y, mo] = m.split('-')
  const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return (names[parseInt(mo, 10) - 1] || mo) + ' ' + y
}
export function leaseDaysLeft(l) { if (!l?.end) return null; return Math.round((new Date(l.end) - Date.now()) / 86400000) }
export function today() { return new Date().toISOString().slice(0, 10) }

// ── list/grid view mode with per-page localStorage persistence ──
export function useViewMode(key) {
  const mode = ref(localStorage.getItem('kr_vm_' + key) || 'grid')
  watch(mode, v => localStorage.setItem('kr_vm_' + key, v))
  return mode
}

// ── pagination for a computed source (filtered list) ──
// Returns top-level refs/computeds so templates auto-unwrap:
//   const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)
export function usePager(source, perPage = 12) {
  const page = ref(1)
  const pageSize = ref(perPage)
  const pageCount = computed(() => Math.max(1, Math.ceil(source.value.length / pageSize.value)))
  const paged = computed(() => source.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))
  const rangeLabel = computed(() => {
    if (!source.value.length) return t('0 records')
    const from = (page.value - 1) * pageSize.value + 1
    const to = Math.min(page.value * pageSize.value, source.value.length)
    return `${from}–${to} ${t('of')} ${source.value.length}`
  })
  function setPage(p) { page.value = Math.min(Math.max(1, p), pageCount.value) }
  function setPageSize(n) { pageSize.value = n; page.value = 1 }
  // Keep page in range when the data shrinks (filters / refresh).
  watch(pageCount, (c) => { if (page.value > c) page.value = c })
  return { paged, page, pageSize, pageCount, rangeLabel, setPage, setPageSize }
}

// ── view-toggle buttons (grid / list) — drop into any page toolbar ──
export const VIEW_TOGGLE = null // (template snippet lives in each view; kept here for docs)

// ── Notification center (V2.15) ──
export const NOTIF_TYPE_META = {
  sla:         { ico: '🔧', label: 'SLA', bn: 'এসএলএ', cls: 'b-blue' },
  compliance:  { ico: '📋', label: 'Compliance', bn: 'কমপ্লায়েন্স', cls: 'b-orange' },
  arrears:     { ico: '💰', label: 'Arrears', bn: 'বকেয়া', cls: 'b-red' },
  renewal:     { ico: '📅', label: 'Renewals', bn: 'নবায়ন', cls: 'b-purple' },
  land:        { ico: '🌍', label: 'Land', bn: 'জমি', cls: 'b-green' },
  nrb:         { ico: '🏠', label: 'Vacancies', bn: 'খালি ইউনিট', cls: 'b-purple' },
  maintenance: { ico: '🔧', label: 'Maintenance', bn: 'মেইনটেন্যান্স', cls: 'b-orange' },
  payment:     { ico: '💳', label: 'Payments', bn: 'পেমেন্ট', cls: 'b-green' },
  kyc:         { ico: '🪪', label: 'KYC', bn: 'কেওয়াইসি', cls: 'b-blue' },
  system:      { ico: '⚙️', label: 'System', bn: 'সিস্টেম', cls: 'b-gray' },
}
export function notifMeta(t) { return NOTIF_TYPE_META[t] || NOTIF_TYPE_META.system }
export function notifTarget(a) {
  const ref = a?.ref || ''
  switch (a?.type) {
    case 'sla':         return { path: '/maintenance', query: ref ? { open: ref } : {} }
    case 'compliance':  return { path: '/compliance', query: ref ? { open: ref } : {} }
    case 'arrears':     return { path: '/invoices', query: ref ? { open: ref } : {} }
    case 'renewal':     return { path: '/leases', query: ref ? { open: ref } : {} }
    case 'land':        return { path: '/land', query: ref ? { open: ref } : {} }
    case 'nrb':         return { path: '/portfolio', query: {} }
    case 'maintenance': return { path: '/maintenance', query: ref ? { open: ref } : {} }
    case 'payment':     return { path: '/receipts', query: ref ? { open: ref } : {} }
    case 'kyc':         return { path: '/secure', query: { tab: 'kyc' } }
    default:            return { path: '/dashboard', query: {} }
  }
}
