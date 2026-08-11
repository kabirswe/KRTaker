function ROLE_MODULES() {
    return [
        'superadmin' => ['dashboard','subscriptions','properties','units','tenants','leases','invoices','receipts','payments','taxes','statements','remit','accounts','receive','expense','withdraw','deposit','reconcile','maintenance','vendors','onboarding','compliance','legal','cases','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','referrals','recon','templates','packages','parking','bookings','voting','forums','events','insurance','support'],
        'owner'      => ['dashboard','subscriptions','properties','units','tenants','leases','invoices','receipts','payments','taxes','statements','remit','accounts','receive','expense','withdraw','deposit','reconcile','maintenance','vendors','onboarding','compliance','legal','cases','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','referrals','recon','templates','insurance','support'],
        'manager'    => ['dashboard','properties','units','tenants','leases','invoices','receipts','payments','taxes','statements','remit','accounts','receive','expense','withdraw','deposit','reconcile','maintenance','vendors','onboarding','compliance','legal','cases','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','referrals','recon','templates','insurance','support'],
        'tenant'     => ['dashboard','portal','invoices','receipts','payments','maintenance','legal','trust','ai','notices','documents','analytics','insurance','support'],
        'partner'    => ['dashboard','maintenance','vendors','invoices','payments','ai','notices','documents','referrals','support'],
        'svc_mgr'    => ['dashboard','maintenance','vendors','compliance','legal','cases','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','support'],
        'legal'      => ['dashboard','compliance','leases','legal','cases','trust','nrb','concierge','smarthome','health','ai','notices','documents','templates','support'],
        'crm'        => ['dashboard','maintenance','leads','onboarding','concierge','smarthome','health','ai','notices','documents','referrals','support'],
        'accountant' => ['dashboard','invoices','receipts','payments','taxes','statements','remit','accounts','receive','expense','withdraw','deposit','reconcile','maintenance','vendors','legal','cases','nrb','concierge','smarthome','health','ai','analytics','notices','documents','recon','templates','support'],
        'hr'         => ['dashboard','staffwatch','staff','attendance','payroll','ai','notices','documents','support'],
    ];
}

/* Resolve Bearer token → [user array, kind] or null */
