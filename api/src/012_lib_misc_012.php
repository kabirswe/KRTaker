function ROLE_MODULES() {
    return [
        'superadmin' => ['dashboard','subscriptions','properties','units','tenants','leases','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','onboarding','compliance','legal','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','referrals','recon','templates','packages','parking','bookings','voting','forums','events','insurance'],
        'owner'      => ['dashboard','subscriptions','properties','units','tenants','leases','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','onboarding','compliance','legal','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','referrals','recon','templates'],
        'manager'    => ['dashboard','properties','units','tenants','leases','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','onboarding','compliance','legal','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents','referrals','recon','templates'],
        'tenant'     => ['dashboard','portal','invoices','receipts','payments','maintenance','legal','trust','ai','notices','documents','analytics'],
        'partner'    => ['dashboard','maintenance','vendors','invoices','payments','ai','notices','documents','referrals'],
        'svc_mgr'    => ['dashboard','maintenance','vendors','compliance','legal','trust','land','nrb','concierge','smarthome','health','build','gate','firesafety','systems','staffwatch','staff','attendance','payroll','meter','utilities','samity','ai','analytics','notices','documents'],
        'legal'      => ['dashboard','compliance','leases','legal','trust','nrb','concierge','smarthome','health','ai','notices','documents','templates'],
        'crm'        => ['dashboard','maintenance','leads','onboarding','concierge','smarthome','health','ai','notices','documents','referrals'],
        'accountant' => ['dashboard','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','legal','nrb','concierge','smarthome','health','ai','analytics','notices','documents','recon','templates'],
        'hr'         => ['dashboard','staffwatch','staff','attendance','payroll','ai','notices','documents'],
    ];
}

/* Resolve Bearer token → [user array, kind] or null */
