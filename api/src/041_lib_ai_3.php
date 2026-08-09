function ai_execute_tool($u, $name, $args) {
    $pdo = db();
    $args = is_array($args) ? $args : [];
    switch ($name) {
        case 'ask_legal': {
            $q = trim($args['query'] ?? '');
            $hits = $q ? legal_search($q) : [];
            if (!$hits) return ['ok' => false, 'text' => 'No legal guidance found for that query — ask your lawyer to confirm.'];
            $parts = [];
            foreach ($hits as $h) $parts[] = $h['title'] . ': ' . $h['body'];
            return ['ok' => true, 'text' => implode("\n\n", $parts)];
        }
        case 'generate_invoice': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot generate invoices.'];
            $lease = trim($args['lease_id'] ?? ''); $month = trim($args['month'] ?? '');
            if (!$lease || !$month) return ['ok' => false, 'text' => 'lease_id and month (YYYY-MM) are required.'];
            $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$lease]);
            if (!$st->fetch()) return ['ok' => false, 'text' => 'Lease ' . $lease . ' not found.'];
            $st = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE l=? AND m=?'); $st->execute([$lease, $month]);
            if ((int)$st->fetchColumn()) return ['ok' => false, 'text' => 'Invoice for ' . $lease . ' / ' . $month . ' already exists.'];
            $id = invoice_next_id($pdo);
            $st = $pdo->prepare('SELECT rent FROM leases WHERE id=?'); $st->execute([$lease]);
            $rent = (int)$st->fetchColumn();
            $gross = $rent; $tds = 0;
            $pdo->prepare('INSERT INTO invoices (id,l,m,gross,tds,net,status) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id, $lease, $month, $gross, $tds, $gross, 'Unpaid']);
            audit($u['name'], 'KR generated invoice', 'invoices', $id, $lease . ' ' . $month);
            return ['ok' => true, 'text' => 'Invoice ' . $id . ' generated for ' . $lease . ' (' . $month . ') — ৳' . number_format($gross) . ' net. It is now visible in Invoices and due for collection.', 'data' => ['id' => $id]];
        }
        case 'create_ticket': {
            if ($u['role'] === 'partner') return ['ok' => false, 'text' => 'Partners cannot raise tickets.'];
            $unit = trim($args['unit_id'] ?? ''); $desc = trim($args['description'] ?? '');
            $liab = trim($args['liability'] ?? 'Landlord');
            if (!$unit || !$desc) return ['ok' => false, 'text' => 'unit_id and description are required.'];
            if ($u['role'] === 'tenant') {
                $mine = my_units($u);
                if (!$mine || !in_array($unit, $mine, true)) return ['ok' => false, 'text' => 'You can only raise tickets for your own unit.'];
                $liab = 'Landlord';
            }
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'MT-','') AS INTEGER)) FROM tickets")->fetchColumn();
            $id = 'MT-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO tickets (id,u,desc,reported,liab,status,cost) VALUES (?,?,?,?,?,?,0)')
                ->execute([$id, $unit, $desc, gmdate('Y-m-d'), $liab, 'Open']);
            audit($u['name'], 'KR raised ticket', 'tickets', $id, $unit . ' ' . $liab);
            return ['ok' => true, 'text' => 'Ticket ' . $id . ' raised for ' . $unit . ' (' . $liab . ' liability): ' . $desc, 'data' => ['id' => $id]];
        }
        case 'check_lease_liability': {
            $issue = strtolower(trim($args['issue'] ?? ''));
            $unit = trim($args['unit_id'] ?? '');
            $hits = legal_search($issue ?: 'maintenance liability');
            $liab = 'Shared';
            if (preg_match('/roof|wall|structur|main line|main line|plumb|electric mains|foundation/', $issue)) $liab = 'Landlord';
            elseif (preg_match('/cosmetic|paint|fitting|furniture|appliance|faucet|glass|user/', $issue)) $liab = 'Tenant';
            elseif (preg_match('/lift|generator|pump|common|stair|security/', $issue)) $liab = 'Shared';
            $text = 'Liability for "' . $issue . '" on ' . ($unit ?: 'the unit') . ' is **' . $liab . '**.';
            if ($hits) $text .= ' ' . $hits[0]['body'];
            return ['ok' => true, 'text' => $text, 'data' => ['liability' => $liab]];
        }
        case 'calc_holding_tax': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot run holding-tax calculations.'];
            $pid = trim($args['property_id'] ?? '');
            $st = $pdo->prepare('SELECT * FROM properties WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Property not found.'];
            $gar = (int)$p['value'] * 0.06;            /* annual rental value ≈ 6% of property value */
            $nav = $gar * 0.75;                          /* 25% statutory deduction → NAV */
            $rate = 0.0565;                              /* city corporation rate (5.65% illustrative) */
            $tax = round($nav * $rate);
            return ['ok' => true, 'text' => 'Holding tax for ' . $p['name'] . ': GAR ৳' . number_format($gar) . ' → NAV ৳' . number_format($nav) . ' → tax ৳' . number_format($tax) . '/yr (' . ($rate * 100) . '% rate). Verify with your city corporation.', 'data' => ['gar' => $gar, 'nav' => $nav, 'tax' => $tax]];
        }
        case 'send_reminder': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'crm'], true))
                return ['ok' => false, 'text' => 'Your role cannot send reminders.'];
            $inv = trim($args['invoice_id'] ?? '');
            $st = $pdo->prepare('SELECT i.*, l.t AS tid, l.u AS uid, l.rent FROM invoices i JOIN leases l ON l.id=i.l WHERE i.id=?'); $st->execute([$inv]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok' => false, 'text' => 'Invoice not found.'];
            $st = $pdo->prepare('SELECT name,email,phone FROM tenants WHERE id=?'); $st->execute([$row['tid']]);
            $ten = $st->fetch(PDO::FETCH_ASSOC);
            $to = $ten['email'] ?? '';
            $ok = false;
            if ($to) {
                $st = $pdo->prepare('SELECT u.name AS uname, p.name AS pname FROM leases l JOIN units u ON u.id=l.u JOIN properties p ON p.id=u.p WHERE l.id=?');
                $st->execute([$row['l']]); $loc = $st->fetch(PDO::FETCH_ASSOC);
                list($subj, $html) = email_render('rent_reminder', [
                    'tenant_name' => $ten['name'] ?? 'Tenant', 'invoice_id' => $inv, 'month' => $row['m'],
                    'amount' => number_format((int)$row['rent']),
                    'property' => $loc['pname'] ?? 'your property', 'unit' => $loc['uname'] ?? '',
                ]);
                $ok = send_mail($to, $subj, $html);
            }
            $w = wa_link($ten['phone'] ?? '', 'Dear ' . ($ten['name'] ?? 'Tenant') . ', invoice ' . $inv . ' (' . $row['m'] . ') for ৳' . number_format($row['rent']) . ' is due. Please pay from your KRTaker tenant portal. Thank you.');
            audit($u['name'], 'KR sent reminder', 'payments', $inv, $to . ' ' . ($ok ? 'sent' : 'failed'));
            return ['ok' => true, 'text' => 'Reminder for ' . $inv . ' ' . ($ok ? 'sent to ' . $to : 'queued (no tenant email on file)') . ($w ? '. WhatsApp: ' . $w : '') . '.'];
        }
        /* ── Phase "demo complete": richer tools ── */
        case 'rent_roll': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view the rent roll.'];
            $pid = trim($args['property_id'] ?? '');
            $rows = ai_q('SELECT p.id AS pid, p.name AS pname, u.id AS uid, l.id AS lid, l.rent,
                          COALESCE((SELECT SUM(net) FROM invoices WHERE l=l.id),0) AS expected,
                          COALESCE((SELECT SUM(pm.amount) FROM payments pm JOIN invoices i ON i.id=pm.inv WHERE i.l=l.id),0) AS collected
                          FROM leases l JOIN units u ON u.id=l.u JOIN properties p ON p.id=u.p' . ($pid ? ' WHERE p.id=?' : ''), $pid ? [$pid] : []);
            if (!$rows) return ['ok' => false, 'text' => $pid ? 'No leases found for ' . $pid : 'No leases on file yet.'];
            $sumE = array_sum(array_column($rows, 'expected')); $sumC = array_sum(array_column($rows, 'collected'));
            $txt = $pid ? 'Rent roll for ' . $rows[0]['pname'] : 'Portfolio rent roll';
            $txt .= ': ' . count($rows) . ' lease(s), expected ৳' . number_format($sumE) . ', collected ৳' . number_format($sumC) . ', outstanding ৳' . number_format($sumE - $sumC) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['leases' => count($rows), 'expected' => $sumE, 'collected' => $sumC, 'outstanding' => $sumE - $sumC]];
        }
        case 'portfolio_summary': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view portfolio analytics.'];
            $props = (int)ai_q('SELECT COUNT(*) AS n FROM properties')[0]['n'] ?? 0;
            $units = (int)ai_q('SELECT COUNT(*) AS n FROM units')[0]['n'] ?? 0;
            $leased = (int)ai_q("SELECT COUNT(*) AS n FROM units WHERE status='Leased'")[0]['n'] ?? 0;
            $value = (int)ai_q('SELECT COALESCE(SUM(value),0) AS n FROM properties')[0]['n'] ?? 0;
            $mrr = (int)ai_q('SELECT COALESCE(SUM(rent),0) AS n FROM leases WHERE status IN (\'Active\',\'Pending Registration\')')[0]['n'] ?? 0;
            $unpaid = (int)ai_q("SELECT COUNT(*) AS n FROM invoices WHERE status!='Paid'")[0]['n'] ?? 0;
            $pct = $units ? round($leased / $units * 100) : 0;
            return ['ok' => true, 'text' => "Portfolio: **{$props}** properties / **{$units}** units (**{$pct}%** leased), asset value ৳" . number_format($value) . ', monthly rent ৳' . number_format($mrr) . ', **' . $unpaid . '** unpaid invoice(s).', 'data' => ['properties' => $props, 'units' => $units, 'occupancy_pct' => $pct, 'asset_value' => $value, 'mrr' => $mrr, 'unpaid' => $unpaid]];
        }
        case 'upcoming_renewals': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true))
                return ['ok' => false, 'text' => 'Your role cannot view renewal schedules.'];
            $months = max(1, (int)($args['months'] ?? 3));
            $end = gmdate('Y-m-d', strtotime("+$months months"));
            $rows = ai_q('SELECT id, u, t, start, "end", rent, status FROM leases WHERE status IN (\'Active\',\'Pending Registration\') AND "end" <= ? ORDER BY "end"', [$end]);
            if (!$rows) return ['ok' => true, 'text' => 'No leases renew within the next ' . $months . ' month(s). 🎉'];
            $txt = '**' . count($rows) . ' lease(s)** renew within ' . $months . ' month(s): ' . implode(', ', array_map(fn($r) => $r['id'] . ' (' . $r['u'] . ' → ' . $r['end'] . ')', array_slice($rows, 0, 6))) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['renewals' => $rows]];
        }
        case 'collection_summary': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view collection analytics.'];
            $month = trim($args['month'] ?? gmdate('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) return ['ok' => false, 'text' => 'month must be YYYY-MM.'];
            $inv = ai_q('SELECT id,net FROM invoices WHERE m=?', [$month]);
            $ids = array_column($inv, 'id');
            $collected = 0;
            if ($ids) {
                $collected = (int)(ai_q('SELECT COALESCE(SUM(amount),0) AS n FROM payments WHERE inv IN (' . ai_in_list($ids) . ')', $ids)[0]['n'] ?? 0);
            }
            $issued = array_sum(array_column($inv, 'net'));
            return ['ok' => true, 'text' => 'Collection for **' . $month . '**: ৳' . number_format($collected) . ' collected of ৳' . number_format($issued) . ' issued (' . count($inv) . ' invoice(s)) — outstanding ৳' . number_format($issued - $collected) . '.', 'data' => ['month' => $month, 'issued' => $issued, 'collected' => $collected, 'outstanding' => $issued - $collected]];
        }
        case 'capital_gains': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot run capital-gains estimates.'];
            $pid = trim($args['property_id'] ?? '');
            $st = $pdo->prepare('SELECT * FROM properties WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Property not found.'];
            $value = (int)$p['value'];
            $gain = round($value * 0.25);                       /* illustrative appreciation 25% */
            $tax = round($gain * 0.15);                         /* IT Act §128: 15% on gains */
            $surcharge = $value > 200000000 ? round($tax * 0.15) : ($value > 100000000 ? round($tax * 0.10) : 0);
            $net = $gain - $tax - $surcharge;
            return ['ok' => true, 'text' => 'Capital gains for ' . $p['name'] . ': est. gain ৳' . number_format($gain) . ' → tax ৳' . number_format($tax) . ' (15%, IT Act §128)' . ($surcharge ? ' + surcharge ৳' . number_format($surcharge) : '') . ' → net ৳' . number_format($net) . '. Verify with your tax advisor.', 'data' => ['gain' => $gain, 'tax' => $tax, 'surcharge' => $surcharge, 'net' => $net]];
        }
        case 'tds_summary': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view TDS reports.'];
            $month = trim($args['month'] ?? '');
            $q = 'SELECT m, COUNT(*) AS n, COALESCE(SUM(tds),0) AS tot FROM invoices' . ($month ? ' WHERE m=?' : '') . ' GROUP BY m ORDER BY m DESC LIMIT 6';
            $rows = ai_q($q, $month ? [$month] : []);
            if (!$rows) return ['ok' => true, 'text' => 'No TDS withheld yet.'];
            $txt = 'TDS withheld (IT Act §109): ' . implode(' · ', array_map(fn($r) => $r['m'] . ' → ৳' . number_format($r['tot']) . ' (' . $r['n'] . ' inv)', $rows)) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['by_month' => $rows]];
        }
        case 'property_details': {
            if ($u['role'] === 'partner') return ['ok' => false, 'text' => 'Partners cannot view property details.'];
            $pid = trim($args['property_id'] ?? '');
            $st = $pdo->prepare('SELECT * FROM properties WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Property not found.'];
            $units = ai_q('SELECT id,name,status FROM units WHERE p=?', [$pid]);
            $val = number_format($p['value']);
            $txt = "**{$p['name']}** ({$p['id']}) — {$p['type']} · {$p['jur']}, value ৳{$val}, {$p['status']}. Units: " . ($units ? implode(', ', array_map(fn($u2) => $u2['id'] . ' (' . $u2['status'] . ')', $units)) : 'none');
            return ['ok' => true, 'text' => $txt, 'data' => ['property' => $p, 'units' => $units]];
        }
        case 'lease_details': {
            if ($u['role'] === 'partner') return ['ok' => false, 'text' => 'Partners cannot view lease details.'];
            $lid = trim($args['lease_id'] ?? '');
            $st = $pdo->prepare('SELECT l.*, u.name AS uname, u.p AS pid, t.name AS tname FROM leases l JOIN units u ON u.id=l.u JOIN tenants t ON t.id=l.t WHERE l.id=?'); $st->execute([$lid]);
            $l = $st->fetch(PDO::FETCH_ASSOC);
            if (!$l) return ['ok' => false, 'text' => 'Lease not found.'];
            $txt = "Lease **{$l['id']}** — unit {$l['uname']} ({$l['u']}) → tenant {$l['tname']}, ৳" . number_format($l['rent']) . "/mo, {$l['start']} → {$l['end']}, status **{$l['status']}**" . ($l['res'] ? ', residential' : ', commercial');
            return ['ok' => true, 'text' => $txt, 'data' => ['lease' => $l]];
        }
        case 'tenant_details': {
            if ($u['role'] === 'partner') return ['ok' => false, 'text' => 'Partners cannot view tenant details.'];
            $tid = trim($args['tenant_id'] ?? '');
            $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) return ['ok' => false, 'text' => 'Tenant not found.'];
            $leases = ai_q('SELECT id, u, rent, status, "end" FROM leases WHERE t=?', [$tid]);
            $txt = "Tenant **{$t['name']}** ({$t['id']}) — {$t['kind']}" . ($t['nrb'] ? ' · NRB' : '') . ", {$t['phone']}" . ($t['email'] ? ', ' . $t['email'] : '') . ". Leases: " . ($leases ? implode(', ', array_map(fn($l) => $l['id'] . ' (' . $l['status'] . ')', $leases)) : 'none');
            return ['ok' => true, 'text' => $txt, 'data' => ['tenant' => $t, 'leases' => $leases]];
        }
        /* ── Phase 8–10: monthly digest, vacancies, notices, referrals ── */
        case 'monthly_digest': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view the monthly digest.'];
            $month = trim($args['month'] ?? gmdate('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) return ['ok' => false, 'text' => 'month must be YYYY-MM.'];
            $inv = ai_q('SELECT id, net, status FROM invoices WHERE m=?', [$month]);
            $ids = array_column($inv, 'id');
            $collected = $ids ? (int)(ai_q('SELECT COALESCE(SUM(amount),0) AS n FROM payments WHERE inv IN (' . ai_in_list($ids) . ')', $ids)[0]['n'] ?? 0) : 0;
            $issued = array_sum(array_column($inv, 'net'));
            $newLeases = (int)(ai_q("SELECT COUNT(*) AS n FROM leases WHERE start LIKE ?", [$month . '-%'])[0]['n'] ?? 0);
            $tickets = ai_q('SELECT status, COUNT(*) AS n FROM tickets WHERE reported LIKE ? GROUP BY status', [$month . '-%']);
            $openT = array_sum(array_map(fn($r) => $r['status'] === 'Open' || $r['status'] === 'In Progress' ? (int)$r['n'] : 0, $tickets));
            $ren = ai_q("SELECT COUNT(*) AS n FROM leases WHERE status IN ('Active','Pending Registration') AND \"end\" <= ?", [gmdate('Y-m-d', strtotime('+3 months'))]);
            $txt = "**Monthly digest — {$month}**\n• Issued: ৳" . number_format($issued) . ' (' . count($inv) . ' invoice(s))\n• Collected: ৳' . number_format($collected) . ' · Outstanding: ৳' . number_format($issued - $collected)
                . "\n• New leases: {$newLeases} · Maintenance tickets opened: {$openT}"
                . "\n• Renewals in next 3 months: " . ((int)($ren[0]['n'] ?? 0));
            $top = $ids ? ai_q('SELECT i.id, i.net FROM invoices i LEFT JOIN payments pm ON pm.inv=i.id WHERE i.m=? GROUP BY i.id HAVING i.net > COALESCE(SUM(pm.amount),0) ORDER BY (i.net - COALESCE(SUM(pm.amount),0)) DESC LIMIT 1', [$month]) : [];
            if ($top) $txt .= "\n• Top outstanding: " . $top[0]['id'] . ' (৳' . number_format((int)$top[0]['net'] - (int)(ai_q('SELECT COALESCE(SUM(amount),0) AS n FROM payments WHERE inv=?', [$top[0]['id']])[0]['n'] ?? 0)) . ')';
            return ['ok' => true, 'text' => $txt, 'data' => ['month' => $month, 'issued' => $issued, 'collected' => $collected, 'outstanding' => $issued - $collected, 'new_leases' => $newLeases, 'tickets_opened' => $openT]];
        }
        case 'list_vacancies': {
            if ($u['role'] === 'partner') return ['ok' => false, 'text' => 'Partners cannot view vacancy reports.'];
            $pid = trim($args['property_id'] ?? '');
            $q = "SELECT u.id, u.name, u.floor, u.sqft, u.p, p.name AS pname FROM units u JOIN properties p ON p.id=u.p WHERE u.status='Vacant'";
            $args2 = [];
            if ($pid) { $q .= ' AND u.p=?'; $args2[] = $pid; }
            if ($u['role'] === 'tenant') {
                $sc = ai_scope($u);
                $unitIds = $sc['units'];
                if (!$unitIds) return ['ok' => true, 'text' => 'No vacancies available to you right now.'];
                $props = ai_q('SELECT DISTINCT p FROM units WHERE id IN (' . ai_in_list($unitIds) . ')', $unitIds);
                $pids = array_column($props, 'p');
                $q .= ' AND u.p IN (' . ai_in_list($pids) . ')';
                $args2 = array_merge($args2, $pids);
            }
            $rows = ai_q($q . ' ORDER BY u.p, u.id', $args2);
            if (!$rows) return ['ok' => true, 'text' => 'No vacant units' . ($pid ? ' for ' . $pid : '') . ' right now. 🎉'];
            $txt = '**' . count($rows) . ' vacant unit(s)**: ' . implode(', ', array_map(fn($r) => $r['id'] . ' ' . $r['name'] . ' (' . $r['pname'] . ')', array_slice($rows, 0, 8))) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['vacancies' => $rows]];
        }
        case 'post_notice': {
            if (!can_post_notice($u)) return ['ok' => false, 'text' => 'Your role cannot post notices.'];
            $title = trim($args['title'] ?? '');
            $body = trim($args['body'] ?? '');
            if (!$title) return ['ok' => false, 'text' => 'title is required.'];
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)) FROM notices")->fetchColumn();
            $id = 'NTC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
                ->execute([$id, $title, $body, $u['name']]);
            audit($u['name'], 'KR posted notice', 'notices', $id, $title);
            return ['ok' => true, 'text' => 'Notice **' . $id . '** posted: ' . $title . ($body ? ' — ' . $body : '') . '. It is live on the notice board now.', 'data' => ['id' => $id]];
        }
        case 'referral_link': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'partner', 'crm'], true))
                return ['ok' => false, 'text' => 'Your role cannot generate referral links.'];
            $code = referral_code_for($u['email']);
            $bonus = (int)admin_cfg(db(), 'plat_referral_bonus', 5000);
            return ['ok' => true, 'text' => 'Your referral code is **' . $code . '**. Share link: https://krtaker.com/register.html?ref=' . $code . ' — earn ৳' . number_format($bonus) . ' per referral who subscribes.', 'data' => ['code' => $code, 'link' => 'https://krtaker.com/register.html?ref=' . $code]];
        }
        /* ── Phase 11: collections autopilot + reconciliation ── */
        case 'remind_overdue': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'crm'], true))
                return ['ok' => false, 'text' => 'Your role cannot run collections reminders.'];
            $rows = collections_data($pdo);
            $total = array_sum(array_column($rows, 'due'));
            if (!$rows) return ['ok' => true, 'text' => 'No unpaid invoices right now. 🎉 Nothing to remind.'];
            $send = !empty($args['send']);
            $sent = 0;
            if ($send) {
                $byEmail = [];
                foreach ($rows as $r) { $to = trim($r['email'] ?? ''); if (!$to) continue; $byEmail[$to][] = $r; }
                foreach ($byEmail as $to => $items) if (send_mail($to, 'KRTaker — ' . count($items) . ' unpaid rent invoice(s)', collections_email_html($items))) $sent++;
                $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('last_collections_run', ?)")
                    ->execute([gmdate('Y-m-d H:i:s') . ' sent=' . $sent . ' total=' . $total]);
                audit($u['name'], 'KR collections run', 'payments', 'bulk', 'sent=' . $sent . ' total=' . $total);
            }
            $top = array_slice($rows, 0, 5);
            $txt = '**' . count($rows) . ' unpaid invoice(s)**, ৳' . number_format($total) . ' outstanding: ' . implode(', ', array_map(fn($r) => $r['inv'] . ' (৳' . number_format($r['due']) . ', ' . $r['tenant'] . ')', $top)) . '.'
                . ($send ? ' **' . $sent . ' reminder email(s) sent.**' : ' Say "send them" and I will email the tenants.');
            return ['ok' => true, 'text' => $txt, 'data' => ['unpaid' => count($rows), 'total_due' => $total, 'sent' => $sent]];
        }
        case 'payment_recon': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view reconciliation.'];
            $totPay = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Success'")->fetchColumn();
            $payCount = (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
            $refunds = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status='Refunded'")->fetchColumn();
            $recCount = (int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
            $pend = (int)$pdo->query("SELECT COUNT(*) FROM gateway_tx WHERE status='pending'")->fetchColumn();
            $stale = (int)$pdo->query("SELECT COUNT(*) FROM gateway_tx WHERE status='pending' AND created_at < datetime('now','-24 hours')")->fetchColumn();
            $txt = 'Reconciliation: **' . $payCount . ' payment(s)** totaling ৳' . number_format($totPay) . ' (' . $recCount . ' receipts, ' . $refunds . ' refunded), ' . $pend . ' gateway session(s) pending (' . $stale . ' stale >24h).';
            return ['ok' => true, 'text' => $txt, 'data' => ['payments' => $payCount, 'total' => $totPay, 'receipts' => $recCount, 'refunds' => $refunds, 'pending' => $pend, 'stale' => $stale]];
        }
        case 'refund_payment': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot process refunds.'];
            $pid = trim($args['payment_id'] ?? '');
            $reason = trim($args['reason'] ?? '');
            if (!$pid) return ['ok' => false, 'text' => 'payment_id is required (e.g. PAY-003).'];
            $st = $pdo->prepare('SELECT * FROM payments WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Payment ' . $pid . ' not found.'];
            if ($p['status'] === 'Refunded') return ['ok' => false, 'text' => 'Payment already refunded.'];
            $pdo->prepare("UPDATE payments SET status='Refunded' WHERE id=?")->execute([$pid]);
            audit($u['name'], 'KR refunded payment', 'payments', $pid, $reason ?: 'no reason');
            return ['ok' => true, 'text' => 'Payment **' . $pid . '** (৳' . number_format($p['amount']) . ', ' . $p['method'] . ') marked refunded' . ($reason ? ' — ' . $reason : '') . '.', 'data' => ['id' => $pid]];
        }
        case 'gen_document': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot generate legal documents.'];
            $type = strtolower(trim($args['type'] ?? ''));
            $ref = trim($args['ref'] ?? '');
            $map = ['lease' => 'LSE-STD', 'agreement' => 'LSE-STD', 'tenancy' => 'LSE-STD', 'contract' => 'SRV-STD', 'service' => 'SRV-STD', 'receipt' => 'RCP-RENT', 'রসিদ' => 'RCP-RENT', 'লিজ' => 'LSE-STD', 'চুক্তি' => 'SRV-STD'];
            if (!isset($map[$type])) return ['ok' => false, 'text' => 'type must be one of: lease/agreement, contract/service, receipt (or রসিদ/লিজ/চুক্তি).'];
            $tplId = $map[$type];
            if (!$ref) return ['ok' => false, 'text' => 'ref is required — a lease id (L-001), partner id (SP-001) or payment id (PAY-001).'];
            $st = $pdo->prepare('SELECT * FROM doc_templates WHERE id=?'); $st->execute([$tplId]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) return ['ok' => false, 'text' => 'Template ' . $tplId . ' not found.'];
            $res = tpl_vars_for($u, $t['kind'], $ref);
            if (!$res) return ['ok' => false, 'text' => 'No ' . $t['kind'] . ' record found for ref ' . $ref . '.'];
            $merged = render_merge($t['body'], $res['vars']);
            audit($u['name'], 'KR generated document', 'templates', $tplId, $ref);
            return ['ok' => true, 'text' => '**' . $t['name'] . '** generated for ' . $ref . '. Open Templates → ' . ucfirst($t['kind']) . ' to preview/print with live linked fields.', 'data' => ['template' => $tplId, 'ref' => $ref, 'kind' => $t['kind']]];
        }
        case 'land_status': {
            $pid = trim($args['parcel'] ?? '');
            if (!$pid) return ['ok' => false, 'text' => 'A parcel id is required — try "status of LP-001".'];
            $st = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Parcel not found.'];
            if ($u['role'] === 'owner' && $p['owner_email'] !== $u['email'] && $p['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your parcel.'];
            $e = land_enrich($pdo, [$p])[0];
            $dueTxt = $e['next_due'] ? ' · next visit due ' . $e['next_due'] . ($e['due_days'] < 0 ? ' (' . abs($e['due_days']) . 'd overdue)' : ($e['due_days'] <= 30 ? ' (in ' . $e['due_days'] . 'd)' : '')) : ' · no monitoring';
            return ['ok' => true, 'text' => '🗺️ **' . $e['name'] . '** (' . $e['id'] . ') — ' . $e['status'] . ' · monitor: ' . $e['monitor'] . $dueTxt . ($e['last_visit'] ? ' · last ' . $e['last_visit_at'] . ' (' . $e['last_outcome'] . ')' : ''), 'data' => ['parcel' => $e['id'], 'status' => $e['status'], 'next_due' => $e['next_due']]];
        }
        case 'land_visit_due': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true)) return ['ok' => false, 'text' => 'Land Guard is for owners and ops staff.'];
            $rows = land_rows($pdo, land_all_ids($pdo, $u));
            $due = array_values(array_filter($rows, fn($p) => $p['due_days'] !== null && $p['due_days'] <= 30));
            if (!$due) return ['ok' => true, 'text' => 'No land visits due within 30 days — all clear 🎉', 'due' => []];
            $parts = [];
            foreach ($due as $p) $parts[] = '• **' . $p['name'] . '** (' . $p['id'] . ') — ' . ($p['due_days'] < 0 ? '⛔ ' . abs($p['due_days']) . 'd OVERDUE' : 'due in ' . $p['due_days'] . 'd') . ' · last ' . ($p['last_visit_at'] ?: '—');
            return ['ok' => true, 'text' => count($due) . ' land visit(s) due/overdue:\n' . implode("\n", $parts), 'due' => $due];
        }
        case 'report_encroachment': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true)) return ['ok' => false, 'text' => 'Only ops staff can flag encroachment.'];
            $pid = trim($args['parcel'] ?? ''); $note = trim($args['note'] ?? '');
            if (!$pid) return ['ok' => false, 'text' => 'parcel id required — try "flag encroachment on LP-003".'];
            $st = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Parcel not found.'];
            $pdo->prepare("UPDATE land_parcels SET status='Encroached', risk='high', updated_at=datetime('now') WHERE id=?")->execute([$pid]);
            land_event($pdo, $pid, 'encroachment', $note ?: 'Encroachment flagged via KR', 'KR');
            audit($u['name'], 'Encroachment flagged (KR)', 'land', $pid, $note);
            return ['ok' => true, 'text' => '🚨 **' . $p['name'] . '** (' . $pid . ') marked **Encroached** — field verification advised.' . ($note ? ' Note: ' . $note : ''), 'data' => ['parcel' => $pid, 'status' => 'Encroached']];
        }
        case 'land_summary': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true)) return ['ok' => false, 'text' => 'Land Guard is for owners and ops staff.'];
            $rows = land_rows($pdo, land_all_ids($pdo, $u));
            $secure = count(array_filter($rows, fn($p) => $p['status'] === 'Secure'));
            $enc = count(array_filter($rows, fn($p) => $p['status'] === 'Encroached'));
            $rev = count(array_filter($rows, fn($p) => $p['status'] === 'Needs Review'));
            $due = count(array_filter($rows, fn($p) => $p['due_days'] !== null && $p['due_days'] <= 30));
            return ['ok' => true, 'text' => '🛰️ **Land Guard** — ' . count($rows) . ' parcel(s): ✅ ' . $secure . ' secure · 🟠 ' . $rev . ' needs review · 🚨 ' . $enc . ' encroached · 📅 ' . $due . ' visit(s) due/overdue within 30d.', 'data' => ['total' => count($rows), 'secure' => $secure, 'review' => $rev, 'encroached' => $enc, 'due' => $due]];
        }

        case 'nrb_tax_status': {
            if (!in_array($u['role'], ['superadmin','owner','manager','accountant','svc_mgr','legal'], true)) return ['ok' => false, 'text' => 'NRB Hub is for owners and staff.'];
            $rows = nrb_tax_rows($pdo, $u);
            if (!$rows) return ['ok' => true, 'text' => 'No NRB tax returns on file yet.'];
            $parts = [];
            foreach ($rows as $t) $parts[] = '• **' . ($t['fy'] ?: '—') . '** — ' . $t['status'] . ' · income ৳' . number_format($t['rental_income']) . ' · TDS ৳' . number_format($t['tds_deducted']) . ' · est. ৳' . number_format($t['tax_estimate']) . ($t['ca_firm'] ? ' · ' . $t['ca_firm'] : '');
            return ['ok' => true, 'text' => '🧾 **NRB Tax returns** — ' . count($rows) . " on file:\n" . implode("\n", $parts), 'data' => ['total' => count($rows)]];
        }
        case 'nrb_repat_status': {
            if (!in_array($u['role'], ['superadmin','owner','manager','accountant','svc_mgr','legal'], true)) return ['ok' => false, 'text' => 'NRB Hub is for owners and staff.'];
            $rows = nrb_rp_rows($pdo, $u);
            if (!$rows) return ['ok' => true, 'text' => 'No repatriation requests yet.'];
            $parts = [];
            foreach ($rows as $r) $parts[] = '• **' . $r['id'] . '** — ' . $r['status'] . ' · ৳' . number_format($r['amount_bdt']) . ' → ' . $r['currency'] . ' ' . number_format($r['amount_fx'], 2) . ' · ' . $r['bank_channel'] . ($r['ref'] ? ' · ' . $r['ref'] : '');
            return ['ok' => true, 'text' => '🌍 **Repatriation** — ' . count($rows) . " request(s):\n" . implode("\n", $parts), 'data' => ['total' => count($rows)]];
        }
        case 'nrb_vacancy_status': {
            if (!in_array($u['role'], ['superadmin','owner','manager','accountant','svc_mgr','legal'], true)) return ['ok' => false, 'text' => 'NRB Hub is for owners and staff.'];
            $vid = trim($args['vacancy'] ?? '');
            if ($vid) {
                $st = $pdo->prepare('SELECT * FROM nrb_vacancies WHERE id=?'); $st->execute([$vid]);
                $v = $st->fetch(PDO::FETCH_ASSOC);
                if (!$v) return ['ok' => false, 'text' => 'Vacancy not found.'];
                if ($u['role'] === 'owner' && $v['owner_email'] !== $u['email'] && $v['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your vacancy.'];
                $e = nrb_vc_enrich($pdo, [$v])[0];
                $cand = $e['candidate'];
                $candTxt = !empty($cand['name']) ? ' · candidate ' . $cand['name'] . ' (vet: ' . $v['vet_status'] . ')' : '';
                return ['ok' => true, 'text' => '🏠 **' . ($e['unit_name'] ?: $e['unit']) . '** (' . $e['id'] . ') — ' . $e['status'] . ' · target ৳' . number_format($e['target_rent']) . ' · ' . $e['showings'] . ' showing(s)' . $candTxt . ($e['status'] === 'Awaiting_Approval' ? ' ⏳ awaiting owner approval' : ''), 'data' => ['id' => $e['id'], 'status' => $e['status']]];
            }
            $rows = nrb_vc_rows($pdo, $u);
            $rows = nrb_vc_enrich($pdo, $rows);
            if (!$rows) return ['ok' => true, 'text' => 'No vacancies tracked yet.'];
            $pending = array_values(array_filter($rows, fn($v) => $v['status'] === 'Awaiting_Approval'));
            $parts = [];
            foreach ($rows as $v) $parts[] = '• **' . ($v['unit_name'] ?: $v['unit']) . '** (' . $v['id'] . ') — ' . $v['status'] . ' · target ৳' . number_format($v['target_rent']) . ' · ' . $v['showings'] . ' showing(s)';
            $head = count($pending) ? '⏳ **' . count($pending) . ' vacancy(ies) awaiting your approval!**' : '🏠 **Vacancy pipeline** — ' . count($rows) . ' tracked';
            return ['ok' => true, 'text' => $head . ":\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'pending_approval' => count($pending)]];
        }
        case 'nrb_dispute_status': {
            if (!in_array($u['role'], ['superadmin','owner','manager','accountant','svc_mgr','legal'], true)) return ['ok' => false, 'text' => 'NRB Hub is for owners and staff.'];
            $did = trim($args['dispute'] ?? '');
            if ($did) {
                $st = $pdo->prepare('SELECT * FROM nrb_disputes WHERE id=?'); $st->execute([$did]);
                $d = $st->fetch(PDO::FETCH_ASSOC);
                if (!$d) return ['ok' => false, 'text' => 'Dispute not found.'];
                if ($u['role'] === 'owner' && $d['owner_email'] !== $u['email'] && $d['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your dispute.'];
                $tl = json_decode($d['timeline'] ?: '[]', true) ?: [];
                $tlTxt = '';
                foreach ($tl as $e) $tlTxt .= '• ' . $e['ts'] . ' — ' . $e['action'] . (!empty($e['by']) ? ' (' . $e['by'] . ')' : '') . "\n";
                return ['ok' => true, 'text' => '⚖️ **' . $d['id'] . '** — ' . $d['dtype'] . ' · ' . $d['status'] . ' · ' . ($d['tenant'] ?: '—') . ' · advocate ' . ($d['advocate'] ?: '—') . ($d['notice_ref'] ? ' · notice ' . $d['notice_ref'] : '') . "\nLast: " . ($d['last_update'] ?: '—') . "\nNext: " . ($d['next_action'] ?: '—') . "\n" . $tlTxt, 'data' => ['id' => $d['id'], 'status' => $d['status']]];
            }
            $rows = nrb_ds_rows($pdo, $u);
            if (!$rows) return ['ok' => true, 'text' => 'No disputes tracked.'];
            $open = count(array_filter($rows, fn($d) => !in_array($d['status'], ['Resolved', 'Closed'], true)));
            $parts = [];
            foreach ($rows as $d) $parts[] = '• **' . $d['id'] . '** — ' . $d['dtype'] . ' · ' . $d['status'] . ' · ' . ($d['tenant'] ?: '—') . ' · ' . ($d['advocate'] ?: 'no advocate');
            return ['ok' => true, 'text' => '⚖️ **NRB Disputes** — ' . count($rows) . ' tracked (' . $open . ' open):' . "\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'open' => $open]];
        }
        case 'concierge_status': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'accountant', 'crm'], true)) return ['ok' => false, 'text' => 'Legal Concierge is for owners and staff.'];
            $rid = trim($args['request'] ?? '');
            if ($rid) {
                $st = $pdo->prepare('SELECT * FROM concierge_requests WHERE id=?'); $st->execute([$rid]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) return ['ok' => false, 'text' => 'Request not found.'];
                if ($u['role'] === 'owner' && $r['owner_email'] !== $u['email'] && $r['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your request.'];
                $e = cr_enrich($pdo, [$r])[0];
                $tlTxt = '';
                foreach ($e['timeline'] as $ev) $tlTxt .= '• ' . $ev['ts'] . ' — ' . $ev['action'] . (!empty($ev['by']) ? ' (' . $ev['by'] . ')' : '') . "\n";
                return ['ok' => true, 'text' => '🗂️ **' . $r['id'] . '** — ' . str_replace('_', ' ', $r['service']) . ' · ' . $r['status'] . ' · agent ' . ($r['assigned_to'] ?: '—') . ' · fee ৳' . number_format($r['fee']) . ' (' . ($r['fee_status'] === 'paid' ? 'paid' : 'unpaid') . ')' . ($r['service_ref'] ? ' · ref ' . $r['service_ref'] : '') . "\nNext: " . ($r['notes'] ?: '—') . "\n" . $tlTxt, 'data' => ['id' => $r['id'], 'status' => $r['status']]];
            }
            $rows = cr_rows($pdo, $u);
            if (!$rows) return ['ok' => true, 'text' => 'No concierge requests yet.'];
            $active = count(array_filter($rows, fn($r) => !in_array($r['status'], ['Completed', 'Rejected', 'Cancelled'], true)));
            $parts = [];
            foreach ($rows as $r) $parts[] = '• **' . $r['id'] . '** — ' . str_replace('_', ' ', $r['service']) . ' · ' . $r['status'] . ' · ' . ($r['assigned_to'] ?: 'no agent') . ($r['fee_status'] !== 'paid' && $r['fee'] > 0 ? ' · fee ৳' . number_format($r['fee']) : '');
            return ['ok' => true, 'text' => '🗂️ **Legal Concierge** — ' . count($rows) . ' request(s) (' . $active . ' active):' . "\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'active' => $active]];
        }
        case 'holding_tax_status': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'accountant', 'crm'], true)) return ['ok' => false, 'text' => 'Legal Concierge is for owners and staff.'];
            $hid = trim($args['holding'] ?? '');
            if ($hid) {
                $st = $pdo->prepare('SELECT * FROM holding_taxes WHERE id=?'); $st->execute([$hid]);
                $h = $st->fetch(PDO::FETCH_ASSOC);
                if (!$h) return ['ok' => false, 'text' => 'Holding not found.'];
                if ($u['role'] === 'owner' && $h['owner_email'] !== $u['email'] && $h['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your holding.'];
                $e = holding_enrich($pdo, [$h])[0];
                return ['ok' => true, 'text' => '🏛️ **' . $h['id'] . '** — ' . ($h['city_corp'] ?: '—') . ' · ward ' . ($h['ward'] ?: '—') . ' · holding ' . ($h['holding_no'] ?: '—') . ' · FY ' . ($h['fy'] ?: '—') . ' · NAV ৳' . number_format($h['annual_value']) . ' @ ' . rtrim(rtrim(number_format($h['rate_pct'], 2), '0'), '.') . '% → ৳' . number_format($h['tax_amount']) . ' · ' . $e['status'] . ($h['due_date'] ? ' · due ' . $h['due_date'] : '') . ($h['receipt_no'] ? ' · receipt ' . $h['receipt_no'] : ''), 'data' => ['id' => $h['id'], 'status' => $e['status']]];
            }
            $rows = holding_rows($pdo, $u);
            if (!$rows) return ['ok' => true, 'text' => 'No holding taxes tracked.'];
            $rows = holding_enrich($pdo, $rows);
            $due = array_values(array_filter($rows, fn($h) => $h['status'] !== 'Paid'));
            $sum = array_sum(array_column($due, 'tax_amount'));
            $parts = [];
            foreach ($rows as $h) $parts[] = '• **' . $h['id'] . '** — ' . ($h['city_corp'] ?: '—') . ' · ' . ($h['holding_no'] ?: '—') . ' · ' . $h['status'] . ' · ৳' . number_format($h['tax_amount']) . ($h['due_date'] ? ' · due ' . $h['due_date'] : '');
            return ['ok' => true, 'text' => '🏛️ **Holding taxes** — ' . count($rows) . ' tracked, ৳' . number_format($sum) . " due:\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'due_total' => $sum]];
        }
        case 'concierge_summary': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'accountant', 'crm'], true)) return ['ok' => false, 'text' => 'Legal Concierge is for owners and staff.'];
            $rows = cr_rows($pdo, $u);
            $ht = holding_enrich($pdo, holding_rows($pdo, $u));
            $active = count(array_filter($rows, fn($r) => !in_array($r['status'], ['Completed', 'Rejected', 'Cancelled'], true)));
            $fees = 0; foreach ($rows as $r) if ($r['status'] === 'Awaiting_Fee' && $r['fee_status'] !== 'paid') $fees += (int)$r['fee'];
            $htDue = array_sum(array_map(fn($h) => $h['status'] === 'Paid' ? 0 : (int)$h['tax_amount'], $ht));
            return ['ok' => true, 'text' => '🗂️ **Legal Concierge** — ' . count($rows) . ' request(s) · ' . $active . ' active · ৳' . number_format($fees) . ' fees due · 🏛️ ' . count($ht) . ' holding tax(es) · ৳' . number_format($htDue) . ' due.', 'data' => ['requests' => count($rows), 'active' => $active, 'fees_due' => $fees, 'holdings' => count($ht), 'holding_due' => $htDue]];
        }
        case 'smart_lock_status': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm'], true)) return ['ok' => false, 'text' => 'Smart Home is for owners and ops staff.'];
            $lid = trim($args['lock'] ?? '');
            if ($lid) {
                $st = $pdo->prepare('SELECT * FROM smart_locks WHERE id=?'); $st->execute([$lid]);
                $l = $st->fetch(PDO::FETCH_ASSOC);
                if (!$l) return ['ok' => false, 'text' => 'Lock not found.'];
                if ($u['role'] === 'owner' && $l['owner_email'] !== $u['email'] && $l['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your lock.'];
                $e = sl_enrich($pdo, [$l])[0];
                return ['ok' => true, 'text' => '🔐 **' . ($e['lock_name'] ?: $e['id']) . '** (' . $e['id'] . ') · ' . $e['unit_name'] . ' · code ' . $e['code'] . ' · ' . $e['status'] . ' · purpose ' . str_replace('_', ' ', $e['purpose']) . ($e['grant_for'] ? ' · for ' . $e['grant_for'] : '') . ' · valid ' . $e['valid_from'] . ' → ' . $e['valid_until'] . ' · used ' . $e['used_count'] . 'x', 'data' => ['id' => $e['id'], 'status' => $e['status'], 'code' => $e['code']]];
            }
            $rows = sl_enrich($pdo, sl_rows($pdo, $u));
            if (!$rows) return ['ok' => true, 'text' => 'No smart locks yet.'];
            $active = count(array_filter($rows, fn($l) => $l['status'] === 'active'));
            $parts = [];
            foreach ($rows as $l) $parts[] = '• **' . ($l['unit_name'] ?: '—') . '** (' . $l['id'] . ') — ' . $l['status'] . ' · code ' . $l['code'] . ' · until ' . ($l['valid_until'] ?: '—') . ($l['grant_for'] ? ' · ' . $l['grant_for'] : '');
            return ['ok' => true, 'text' => '🔐 **Smart locks** — ' . count($rows) . ' total, ' . $active . " active:\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'active' => $active]];
        }
        case 'camera_status': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm'], true)) return ['ok' => false, 'text' => 'Smart Home is for owners and ops staff.'];
            $cid = trim($args['camera'] ?? '');
            if ($cid) {
                $st = $pdo->prepare('SELECT * FROM cctv_cameras WHERE id=?'); $st->execute([$cid]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if (!$c) return ['ok' => false, 'text' => 'Camera not found.'];
                if ($u['role'] === 'owner' && $c['owner_email'] !== $u['email'] && $c['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your camera.'];
                $e = cv_enrich($pdo, [$c])[0];
                return ['ok' => true, 'text' => '📹 **' . ($e['name'] ?: $e['id']) . '** (' . $e['id'] . ') · ' . ($e['prop_name'] ?: '—') . ' · ' . ($e['location'] ?: '—') . ' · ' . $e['status'] . ' · last seen ' . ($e['last_seen'] ?: '—'), 'data' => ['id' => $e['id'], 'status' => $e['status']]];
            }
            $rows = cv_enrich($pdo, cv_rows($pdo, $u));
            if (!$rows) return ['ok' => true, 'text' => 'No cameras registered yet.'];
            $online = count(array_filter($rows, fn($c) => $c['status'] === 'online'));
            $parts = [];
            foreach ($rows as $c) $parts[] = '• **' . ($c['name'] ?: $c['id']) . '** (' . $c['id'] . ') — ' . $c['status'] . ($c['status'] === 'offline' ? ' ⚠️' : '') . ' · ' . ($c['location'] ?: '') . ($c['last_seen'] ? ' · last ' . $c['last_seen'] : '');
            return ['ok' => true, 'text' => '📹 **CCTV** — ' . count($rows) . ' camera(s), ' . $online . " online:\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'online' => $online]];
        }
        case 'showing_code': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm'], true)) return ['ok' => false, 'text' => 'Smart Home is for owners and ops staff.'];
            $sid = trim($args['showing'] ?? '');
            if (!$sid) return ['ok' => false, 'text' => 'A showing id is required — try "code for SH-001".'];
            $st = $pdo->prepare('SELECT * FROM nrb_showings WHERE id=?'); $st->execute([$sid]);
            $sh = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sh) return ['ok' => false, 'text' => 'Showing not found.'];
            $vst = $pdo->prepare('SELECT * FROM nrb_vacancies WHERE id=?'); $vst->execute([$sh['vacancy']]);
            $vc = $vst->fetch(PDO::FETCH_ASSOC);
            if (!$vc) return ['ok' => false, 'text' => 'Vacancy not found.'];
            if ($u['role'] === 'owner' && $vc['owner_email'] !== $u['email'] && $vc['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your vacancy.'];
            $q = $pdo->prepare("SELECT id FROM smart_locks WHERE showing=? AND status='active'"); $q->execute([$sid]);
            if ($q->fetchColumn()) return ['ok' => false, 'text' => 'An active lock code already exists for this showing.'];
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $day = substr($sh['scheduled_at'] ?: date('Y-m-d'), 0, 10);
            $id = sl_next_id($pdo);
            $pdo->prepare('INSERT INTO smart_locks (id, owner_email, prop, unit, lock_name, model, code, status, purpose, grant_for, showing, valid_from, valid_until, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $vc['owner_email'], $vc['prop'], $vc['unit'], 'Showing code — ' . ($vc['unit'] ?: 'unit'), 'Yale YRD226', $code, 'active', 'showing', $sh['prospect'], $sid, $day . ' 08:00:00', $day . ' 20:00:00', 'Auto-generated for showing ' . $sid]);
            audit($u['name'], 'Showing lock code generated', 'smarthome', $id, $sid . ' ' . $code);
            return ['ok' => true, 'text' => '🔑 **Lock code generated** for ' . $sid . ' (' . $sh['prospect'] . ') — code **' . $code . '** · valid ' . $day . ' 08:00 → 20:00 · unit ' . $vc['unit'], 'data' => ['id' => $id, 'code' => $code, 'showing' => $sid]];
        }
        case 'health_plan_status': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm', 'accountant'], true)) return ['ok' => false, 'text' => 'Health Check is for owners and staff.'];
            $pid = trim($args['plan'] ?? '');
            if ($pid) {
                $st = $pdo->prepare('SELECT * FROM health_plans WHERE id=?'); $st->execute([$pid]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) return ['ok' => false, 'text' => 'Plan not found.'];
                if ($u['role'] === 'owner' && $p['owner_email'] !== $u['email'] && $p['owner_email'] !== '') return ['ok' => false, 'text' => 'Not your plan.'];
                $e = hp_enrich($pdo, [$p])[0];
                return ['ok' => true, 'text' => '🌦️ **' . $e['id'] . '** — ' . $e['season_label'] . ' · ' . $e['service_label'] . ' · ' . $e['prop_name'] . ($e['unit_name'] ? ' · ' . $e['unit_name'] : '') . ' · ' . $e['scheduled_for'] . ' · ' . $e['status'] . ' · ' . $e['tasks_done'] . '/' . $e['tasks_total'] . ' tasks' . ($e['assigned_to'] ? ' · ' . $e['assigned_to'] : '') . ($e['cost'] ? ' · ৳' . number_format($e['cost']) : ''), 'data' => ['id' => $e['id'], 'status' => $e['status'], 'pct' => $e['pct']]];
            }
            $rows = hp_enrich($pdo, hp_rows($pdo, $u));
            if (!$rows) return ['ok' => true, 'text' => 'No health check plans yet.'];
            $active = count(array_filter($rows, fn($p) => !in_array($p['status'], ['Completed', 'Skipped'], true)));
            $parts = [];
            foreach ($rows as $p) $parts[] = '• **' . $p['id'] . '** — ' . $p['season_label'] . ' · ' . $p['service_label'] . ' · ' . ($p['prop_name'] ?: '—') . ' · ' . $p['scheduled_for'] . ' · ' . $p['status'] . ' · ' . $p['pct'] . '%';
            return ['ok' => true, 'text' => '🌦️ **Health Check** — ' . count($rows) . ' plan(s), ' . $active . " active:\n" . implode("\n", $parts), 'data' => ['total' => count($rows), 'active' => $active]];
        }
        case 'health_summary': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm', 'accountant'], true)) return ['ok' => false, 'text' => 'Health Check is for owners and staff.'];
            $rows = hp_enrich($pdo, hp_rows($pdo, $u));
            $active = count(array_filter($rows, fn($p) => !in_array($p['status'], ['Completed', 'Skipped'], true)));
            $bySeason = [];
            foreach ($rows as $p) $bySeason[$p['season']] = ($bySeason[$p['season']] ?? 0) + 1;
            $cost = array_sum(array_map(fn($p) => (int)$p['cost'], $rows));
            $txt = '🌦️ **Health Check** — ' . count($rows) . ' plan(s) · ' . $active . ' active · pre-monsoon ' . ($bySeason['pre_monsoon'] ?? 0) . ' · pre-summer ' . ($bySeason['pre_summer'] ?? 0) . ' · quarterly ' . ($bySeason['quarterly'] ?? 0) . ' · planned spend ৳' . number_format($cost) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['total' => count($rows), 'active' => $active, 'by_season' => $bySeason]];
        }
        case 'build_status': {
            $pid = trim($args['project_id'] ?? '');
            $rows = bp_enrich($pdo, bp_rows($pdo, $u));
            if ($pid) {
                $hit = null;
                foreach ($rows as $p) if ($p['id'] === $pid) { $hit = $p; break; }
                if (!$hit) return ['ok' => false, 'text' => 'Project ' . $pid . ' not found in your scope.'] ;
                return ['ok' => true, 'text' => '🏗️ **' . $hit['title'] . '** (' . $hit['id'] . ') — ' . $hit['status'] . ' · progress **' . $hit['progress'] . '%** (' . $hit['milestones_done'] . '/' . $hit['milestones_total'] . ' milestones) · spent ৳' . number_format($hit['spent']) . ' of ৳' . number_format((int)$hit['budget_total']) . ' (' . $hit['budget_used_pct'] . '%)' . ($hit['milestones_overdue'] > 0 ? ' · ⚠️ ' . $hit['milestones_overdue'] . ' overdue milestone(s)' : '') . ($hit['days_left'] !== null ? ' · target end ' . $hit['target_end'] . ' (' . $hit['days_left'] . 'd left)' : '') . '.', 'data' => ['progress' => $hit['progress'], 'spent' => $hit['spent']]];
            }
            if (!$rows) return ['ok' => true, 'text' => 'No construction projects yet — start one in Build Watch.'] ;
            $txt = '🏗️ **Build Watch** — ' . count($rows) . ' project(s)';
            foreach ($rows as $p) $txt .= ' · ' . $p['id'] . ' ' . $p['title'] . ' (' . $p['status'] . ', ' . $p['progress'] . '%)';
            return ['ok' => true, 'text' => $txt . '.', 'data' => ['total' => count($rows)]];
        }
        case 'build_budget': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true)) return ['ok' => false, 'text' => 'Build budget is for owners and staff.'] ;
            $rows = bp_enrich($pdo, bp_rows($pdo, $u));
            $budget = array_sum(array_map(fn($p) => (int)$p['budget_total'], $rows));
            $spent = array_sum(array_map(fn($p) => (int)$p['spent'], $rows));
            $over = 0;
            foreach ($rows as $p) if (!empty($p['over_budget'])) $over++;
            $txt = '💰 **Build budgets** — ' . count($rows) . ' project(s) · total budget ৳' . number_format($budget) . ' · spent ৳' . number_format($spent) . ' (' . ($budget > 0 ? round($spent / $budget * 100, 1) : 0) . '%)' . ($over > 0 ? ' · ⚠️ ' . $over . ' over budget' : '') . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['budget' => $budget, 'spent' => $spent, 'over_budget' => $over]];
        }
        case 'build_milestone_due': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true)) return ['ok' => false, 'text' => 'Build milestones are for owners and staff.'] ;
            $rows = bp_enrich($pdo, bp_rows($pdo, $u));
            $soon = [];
            foreach ($rows as $p) {
                foreach (bm_rows($pdo, $p['id']) as $m) {
                    if (in_array($m['status'], ['Completed', 'Skipped'], true)) continue;
                    if ($m['target_date'] && $m['target_date'] <= date('Y-m-d', strtotime('+' . (int)(build_cfg($pdo, 'milestone_alert_days', 14)) . ' days')))
                        $soon[] = $p['id'] . ' · ' . $m['title'] . ' (' . $m['target_date'] . ')';
                }
            }
            if (!$soon) return ['ok' => true, 'text' => '✅ No build milestones due in the next ' . (int)(build_cfg($pdo, 'milestone_alert_days', 14)) . ' days.'] ;
            return ['ok' => true, 'text' => '📋 **Milestones due** — ' . count($soon) . ': ' . implode(' · ', array_slice($soon, 0, 5)) . '.', 'data' => ['due' => count($soon)]];
        }
        case 'gate_inside': {
            $rows = gate_enrich($pdo, gate_visit_rows($pdo, $u));
            $inside = array_values(array_filter($rows, fn($r) => $r['status'] === 'Inside'));
            if (!$inside) return ['ok' => true, 'text' => '🚪 No one is inside the gate right now.'];
            $lines = [];
            foreach ($inside as $r) {
                $lines[] = $r['id'] . ' · ' . ($r['name'] ?: '—') . ($r['vehicle_no'] ? ' · ' . $r['vehicle_no'] : '') . ($r['purpose'] ? ' · ' . $r['purpose'] : '') . ($r['inside_min'] > 0 ? ' · ' . floor($r['inside_min'] / 60) . 'h inside' : '') . ((int)$r['flagged'] === 1 ? ' · ⚠️ FLAGGED' : '');
            }
            return ['ok' => true, 'text' => '🚪 **Inside now (' . count($inside) . ')** — ' . implode('; ', $lines) . '.', 'data' => ['inside' => count($inside)]];
        }
        case 'gate_visit_status': {
            $vid = trim($args['visit_id'] ?? '');
            if (!$vid) return ['ok' => false, 'text' => 'visit_id is required (e.g. GV-001).'];
            $st = $pdo->prepare('SELECT * FROM gate_visits WHERE id=?'); $st->execute([$vid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return ['ok' => false, 'text' => 'Visit ' . $vid . ' not found.'];
            $en = gate_enrich($pdo, [$r])[0];
            return ['ok' => true, 'text' => '🚪 **' . $vid . '** — ' . ($en['name'] ?: '—') . ' (' . ucfirst($en['vtype']) . ') · ' . $en['status'] . ($en['status'] === 'Inside' && $en['inside_min'] > 0 ? ' · ' . floor($en['inside_min'] / 60) . 'h inside' : '') . ($en['check_out'] ? ' · out at ' . $en['check_out'] : '') . ((int)$en['flagged'] === 1 ? ' · ⚠️ FLAGGED' : '') . '.', 'data' => ['status' => $en['status'], 'flagged' => (int)$en['flagged']]];
        }
        case 'gate_vehicle_check': {
            $vno = strtoupper(trim($args['vehicle_no'] ?? ''));
            if ($vno === '') return ['ok' => false, 'text' => 'vehicle_no is required.'];
            $vs = $pdo->prepare("SELECT * FROM resident_vehicles WHERE vehicle_no=? AND status='active'"); $vs->execute([$vno]);
            $rv = $vs->fetch(PDO::FETCH_ASSOC);
            $ws = $pdo->prepare("SELECT * FROM gate_watchlist WHERE active=1 AND vehicle_no<>'' AND UPPER(vehicle_no)=?"); $ws->execute([$vno]);
            $wl = $ws->fetch(PDO::FETCH_ASSOC);
            $ls = $pdo->prepare('SELECT * FROM gate_visits WHERE vehicle_no=? ORDER BY ts DESC LIMIT 1'); $ls->execute([$vno]);
            $lv = $ls->fetch(PDO::FETCH_ASSOC);
            if ($wl) return ['ok' => false, 'text' => '🚨 **' . $vno . ' is on the WATCHLIST** — ' . ($wl['reason'] ?: 'no reason recorded') . '. Do not allow entry.', 'data' => ['watchlist' => true]];
            if ($rv) return ['ok' => true, 'text' => '✅ **' . $vno . ' is a registered resident vehicle** (' . $rv['label'] . ' · ' . $rv['owner_name'] . ' · ' . $rv['unit'] . ').', 'data' => ['resident' => true]];
            $last = $lv ? (' Last visit ' . $lv['id'] . ' on ' . $lv['check_in'] . ($lv['status'] === 'Inside' ? ' — still inside' : '') . '.') : '';
            return ['ok' => true, 'text' => '🚗 **' . $vno . '** is not on the registry or watchlist.' . $last, 'data' => ['resident' => false]];
        }
        case 'fire_safety_status': {
            $aid = trim($args['asset_id'] ?? '');
            $rows = fire_asset_enrich($pdo, fire_asset_rows($pdo, $u));
            if ($aid) {
                $hit = null;
                foreach ($rows as $a) if ($a['id'] === $aid) { $hit = $a; break; }
                if (!$hit) return ['ok' => false, 'text' => 'Asset ' . $aid . ' not found in your scope.'];
                $st = ucfirst($hit['status']);
                if ((int)$hit['expired']) $st .= ' — ⚠️ EXPIRED ' . $hit['expiry_date'];
                elseif ((int)$hit['expiring']) $st .= ' — ⚠️ expiring in ' . $hit['days_to_expiry'] . 'd';
                return ['ok' => true, 'text' => '🧯 **' . $aid . '** — ' . $hit['asset_type_label'] . ' · ' . ($hit['location'] ?: '—') . ' · ' . $st . ($hit['next_inspection'] ? ' · next inspection ' . $hit['next_inspection'] : '') . '.', 'data' => ['status' => $hit['status'], 'expired' => (int)$hit['expired']]];
            }
            $total = count($rows);
            $expired = count(array_filter($rows, fn($a) => (int)$a['expired'] === 1));
            $expiring = count(array_filter($rows, fn($a) => (int)$a['expiring'] === 1));
            $faulty = count(array_filter($rows, fn($a) => $a['status'] === 'faulty'));
            $inc = count(array_filter(fire_incident_rows($pdo, $u), fn($i) => !in_array($i['status'], ['Resolved', 'Closed'], true)));
            $txt = '🧯 **Fire safety** — ' . $total . ' asset(s) · ' . $expired . ' expired · ' . $expiring . ' expiring · ' . $faulty . ' faulty · ' . $inc . ' open incident(s).';
            return ['ok' => true, 'text' => $txt, 'data' => ['total' => $total, 'expired' => $expired, 'expiring' => $expiring, 'open_incidents' => $inc]];
        }
        case 'fire_incident_status': {
            $iid = trim($args['incident_id'] ?? '');
            $rows = fire_incident_enrich($pdo, fire_incident_rows($pdo, $u));
            if ($iid) {
                $hit = null;
                foreach ($rows as $i) if ($i['id'] === $iid) { $hit = $i; break; }
                if (!$hit) return ['ok' => false, 'text' => 'Incident ' . $iid . ' not found in your scope.'];
                return ['ok' => true, 'text' => '🔥 **' . $iid . '** — ' . ucfirst($hit['incident_type']) . ' · severity ' . $hit['severity'] . ' · ' . $hit['status'] . ($hit['location'] ? ' · ' . $hit['location'] : '') . ($hit['occurred_at'] ? ' · ' . $hit['occurred_at'] : '') . ' · ' . count($hit['timeline_arr']) . ' event(s).', 'data' => ['status' => $hit['status'], 'severity' => $hit['severity']]];
            }
            $open = array_values(array_filter($rows, fn($i) => !in_array($i['status'], ['Resolved', 'Closed'], true)));
            if (!$open) return ['ok' => true, 'text' => '🔥 No open fire/emergency incidents.'] ;
            $txt = '🔥 **Open incidents (' . count($open) . ')** — ' . implode('; ', array_map(fn($i) => $i['id'] . ' ' . ucfirst($i['incident_type']) . ' (' . $i['severity'] . ', ' . $i['status'] . ')', array_slice($open, 0, 5))) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['open' => count($open)]];
        }
        case 'fire_asset_check': {
            $aid = trim($args['asset_id'] ?? '');
            if (!$aid) return ['ok' => false, 'text' => 'asset_id is required (e.g. FA-001).'];
            $st = $pdo->prepare('SELECT * FROM fire_assets WHERE id=?'); $st->execute([$aid]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) return ['ok' => false, 'text' => 'Asset ' . $aid . ' not found.'];
            $en = fire_asset_enrich($pdo, [$a])[0];
            return ['ok' => true, 'text' => '🧯 **' . $aid . '** — ' . $en['asset_type_label'] . ' · ' . ($en['location'] ?: '—') . ' · status ' . $en['status'] . ((int)$en['expired'] ? ' · ⚠️ EXPIRED ' . $en['expiry_date'] : ((int)$en['expiring'] ? ' · expiring in ' . $en['days_to_expiry'] . 'd' : '')) . ($en['next_inspection'] ? ' · inspect by ' . $en['next_inspection'] : '') . '.', 'data' => ['status' => $en['status']]];
        }

        case 'systems_status': {
            $aid = trim($args['asset_id'] ?? '');
            if ($aid) {
                $st = $pdo->prepare('SELECT * FROM sys_assets WHERE id=?'); $st->execute([$aid]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                if (!$a) return ['ok' => false, 'text' => 'Asset ' . $aid . ' not found.'];
                $en = sys_asset_enrich($pdo, [$a])[0];
                return ['ok' => true, 'text' => '⚙️ **' . $aid . '** — ' . $en['asset_type_label'] . ' · ' . ($en['location'] ?: '—') . ' · status ' . $en['status'] . ((int)$en['service_overdue'] ? ' · ⚠️ SERVICE OVERDUE (' . $en['next_service'] . ')' : ((int)$en['service_due_soon'] ? ' · service due by ' . $en['next_service'] : '')) . ((int)$en['cert_overdue'] ? ' · ⚠️ CERT EXPIRED ' . $en['cert_expiry'] : ((int)$en['cert_expiring'] ? ' · cert expires ' . $en['cert_expiry'] : '')) . ($en['fuel_pct'] !== null ? ' · fuel ' . $en['fuel_pct'] . '%' . ((int)$en['fuel_low'] ? ' ⚠️ LOW' : '') : '') . '.', 'data' => ['status' => $en['status'], 'fuel_low' => (int)$en['fuel_low']]];
            }
            $rows = sys_asset_enrich($pdo, sys_asset_rows($pdo, $u));
            $due = array_values(array_filter($rows, fn($r) => (int)$r['service_overdue']));
            $low = array_values(array_filter($rows, fn($r) => (int)$r['fuel_low']));
            $txt = '⚙️ **Systems Watch** — ' . count($rows) . ' asset(s): ' . count(array_filter($rows, fn($r) => $r['status'] === 'operational')) . ' operational · ' . count($due) . ' service overdue · ' . count(array_filter($rows, fn($r) => (int)$r['cert_overdue'])) . ' cert expired · ' . count($low) . ' low fuel.';
            if ($due) $txt .= ' Overdue: ' . implode(', ', array_map(fn($r) => $r['id'] . ' ' . $r['asset_type_label'], array_slice($due, 0, 4))) . '.';
            if ($low) $txt .= ' Low fuel: ' . implode(', ', array_map(fn($r) => $r['id'] . ' (' . $r['fuel_pct'] . '%)', $low)) . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['assets' => count($rows), 'service_overdue' => count($due), 'fuel_low' => count($low)]];
        }
        case 'sys_service_due': {
            $rows = sys_asset_enrich($pdo, sys_asset_rows($pdo, $u));
            $due = array_values(array_filter($rows, fn($r) => (int)$r['service_overdue'] || (int)$r['service_due_soon']));
            if (!$due) return ['ok' => true, 'text' => '✅ No system services due in the next ' . (int)(sys_cfg($pdo, 'service_alert_days', 14)) . ' days.'] ;
            $lines = array_map(fn($r) => $r['id'] . ' ' . $r['asset_type_label'] . ' (' . $r['next_service'] . ((int)$r['service_overdue'] ? ' ⚠️' : '') . ')', $due);
            return ['ok' => true, 'text' => '🛠️ **Services due** — ' . count($due) . ': ' . implode(' · ', array_slice($lines, 0, 5)) . '.', 'data' => ['due' => count($due)]];
        }
        case 'sys_fuel_check': {
            $rows = sys_asset_enrich($pdo, sys_asset_rows($pdo, $u));
            $gens = array_values(array_filter($rows, fn($r) => (int)$r['fuel_capacity'] > 0));
            if (!$gens) return ['ok' => true, 'text' => '⛽ No fuel-tracked generators registered.'] ;
            $lines = [];
            foreach ($gens as $g) {
                $lines[] = $g['id'] . ' ' . $g['fuel_pct'] . '% (' . $g['fuel_level'] . '/' . $g['fuel_capacity'] . 'L)' . ((int)$g['fuel_low'] ? ' ⚠️LOW' : '') . ($g['last_service'] ? ' · svc ' . $g['last_service'] : '');
            }
            return ['ok' => true, 'text' => '⛽ **Generator fuel** — ' . implode('; ', $lines) . '.', 'data' => ['generators' => count($gens)]];
        }

        case 'staff_status': {
            $bid = trim($args['staff_id'] ?? '');
            if ($bid) {
                $st = $pdo->prepare('SELECT * FROM building_staff WHERE id=?'); $st->execute([$bid]);
                $b = $st->fetch(PDO::FETCH_ASSOC);
                if (!$b) return ['ok' => false, 'text' => 'Staff ' . $bid . ' not found.'];
                $en = staff_enrich($pdo, [$b])[0];
                return ['ok' => true, 'text' => '👷 **' . $bid . '** — ' . $en['name'] . ' · ' . $en['role_label'] . ' · ' . ($en['property_name'] ?: '—') . ' · ' . $en['status'] . ($en['today_status'] ? ' · today: ' . $en['today_status'] : ' · ⚠️ no mark today') . ($en['absent_pct'] > 0 ? ' · ' . $en['absent_pct'] . '% absent this month' : '') . '.', 'data' => ['status' => $en['status'], 'today' => $en['today_status']]];
            }
            $rows = staff_enrich($pdo, staff_rows($pdo, $u));
            $active = array_values(array_filter($rows, fn($r) => $r['status'] === 'active'));
            $onDuty = count(array_filter($active, fn($r) => in_array($r['today_status'], ['present', 'late'], true)));
            $absent = count(array_filter($active, fn($r) => $r['today_status'] === 'absent'));
            $due = count(array_filter($rows, fn($r) => (int)$r['payroll_unpaid_prev']));
            $txt = '👷 **Staff Watch** — ' . count($rows) . ' staff, ' . count($active) . ' active · ' . $onDuty . ' on duty today · ' . $absent . ' absent · ' . $due . ' salary due.';
            return ['ok' => true, 'text' => $txt, 'data' => ['staff' => count($rows), 'on_duty' => $onDuty, 'salary_due' => $due]];
        }
        case 'staff_attendance_today': {
            $rows = staff_enrich($pdo, staff_rows($pdo, $u));
            $active = array_values(array_filter($rows, fn($r) => $r['status'] === 'active'));
            $onDuty = array_values(array_filter($active, fn($r) => in_array($r['today_status'], ['present', 'late'], true)));
            $noMark = array_values(array_filter($active, fn($r) => $r['today_status'] === ''));
            $txt = '🕐 **On duty today (' . count($onDuty) . ')** — ' . (count($onDuty) ? implode('; ', array_map(fn($r) => $r['name'] . ' (' . $r['today_status'] . ')', array_slice($onDuty, 0, 6))) : 'none') . ($noMark ? '. ⚠️ No mark yet: ' . implode(', ', array_map(fn($r) => $r['name'], array_slice($noMark, 0, 5))) : '') . '.';
            return ['ok' => true, 'text' => $txt, 'data' => ['on_duty' => count($onDuty), 'no_mark' => count($noMark)]];
        }
        case 'payroll_due': {
            $rows = staff_enrich($pdo, staff_rows($pdo, $u));
            $due = array_values(array_filter($rows, fn($r) => (int)$r['payroll_unpaid_prev']));
            if (!$due) return ['ok' => true, 'text' => '✅ No unpaid salaries for ' . date('Y-m', strtotime('-1 month')) . '.'] ;
            $lines = array_map(fn($r) => $r['name'] . ' (' . $r['id'] . ')', $due);
            return ['ok' => true, 'text' => '💰 **Salary due** — ' . count($due) . ' for ' . date('Y-m', strtotime('-1 month')) . ': ' . implode(' · ', array_slice($lines, 0, 6)) . '.', 'data' => ['due' => count($due)]];
        }

        case 'samity_status': {
            $rows = samity_bill_rows($pdo, $u);
            $bills = array_map(fn($b) => samity_bill_enrich($pdo, $b), $rows);
            $col = samity_collection_rows($pdo, $u);
            $fund = samity_fund($pdo, $bills, $col);
            $bid = trim($args['bill_id'] ?? '');
            if ($bid) {
                $one = null;
                foreach ($bills as $b) if ($b['id'] === $bid) { $one = $b; break; }
                if (!$one) return ['ok' => false, 'text' => 'Bill ' . $bid . ' not found.'];
                return ['ok' => true, 'text' => '🧾 **' . $one['id'] . ' · ' . $one['unit_label'] . '** · ' . $one['month'] . ' — ৳' . number_format((int)$one['amount']) . ' billed, ৳' . number_format((int)$one['collected']) . ' collected, ৳' . number_format((int)$one['outstanding']) . ' outstanding · ' . ($one['overdue'] ? '⚠️ OVERDUE' : $one['computed_status']) . '.', 'data' => ['unit' => $one['unit'], 'outstanding' => $one['outstanding'], 'overdue' => (int)$one['overdue']]];
            }
            $overdue = array_values(array_filter($bills, fn($b) => $b['overdue']));
            $txt = '🏘️ **Kalyan Samity** — billed ৳' . number_format($fund['total_billed']) . ' · collected ৳' . number_format($fund['total_collected']) . ' · outstanding ৳' . number_format($fund['outstanding']) . ' (' . $fund['collection_rate'] . '%). ' . count($overdue) . ' overdue bill(s).';
            return ['ok' => true, 'text' => $txt, 'data' => ['outstanding' => $fund['outstanding'], 'overdue' => count($overdue), 'rate' => $fund['collection_rate']]];
        }
        case 'samity_bills_due': {
            $rows = samity_bill_rows($pdo, $u);
            $bills = array_map(fn($b) => samity_bill_enrich($pdo, $b), $rows);
            $due = array_values(array_filter($bills, fn($b) => $b['outstanding'] > 0 && $b['computed_status'] !== 'Waived'));
            if (!$due) return ['ok' => true, 'text' => '✅ No outstanding service charges.'];
            $lines = array_map(fn($b) => $b['unit_label'] . ' · ' . $b['month'] . ' — ৳' . number_format($b['outstanding']) . ($b['overdue'] ? ' ⚠️' : ''), array_slice($due, 0, 8));
            return ['ok' => true, 'text' => '🧾 **Service charges outstanding** (' . count($due) . '): ' . implode('; ', $lines) . '.', 'data' => ['due' => count($due), 'amount' => array_sum(array_map(fn($b) => $b['outstanding'], $due))]];
        }
        case 'samity_arrears': {
            $rows = samity_bill_rows($pdo, $u);
            $bills = array_map(fn($b) => samity_bill_enrich($pdo, $b), $rows);
            $byUnit = [];
            foreach ($bills as $b) {
                if ($b['computed_status'] === 'Waived') continue;
                $byUnit[$b['unit']] = ($byUnit[$b['unit']] ?? 0) + $b['outstanding'];
            }
            arsort($byUnit);
            if (!$byUnit) return ['ok' => true, 'text' => '✅ No arrears — all units are settled.'];
            $lines = [];
            foreach (array_slice($byUnit, 0, 8) as $u => $amt) $lines[] = samity_unit_label($pdo, $u) . ' — ৳' . number_format($amt);
            return ['ok' => true, 'text' => '💰 **Arrears by unit** — ' . implode('; ', $lines) . '.', 'data' => ['units' => count($byUnit), 'total' => array_sum($byUnit)]];
        }
        case 'tenant_score': {
            $tid = trim($args['tenant_id'] ?? '');
            if (!$tid) return ['ok' => false, 'text' => 'tenant_id is required (e.g. T-002).'];
            $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
            if (!$st->fetch()) return ['ok' => false, 'text' => 'Tenant ' . $tid . ' not found.'];
            $sc = tenant_scorecard($pdo, $tid);
            $b = $sc['band'];
            return ['ok' => true, 'text' => '**' . $sc['tenant']['name'] . '** — renter score **' . $sc['score'] . '/100** (' . $b . '). Payment ' . $sc['factors']['payment'] . ' · Stability ' . $sc['factors']['stability'] . ' · Care ' . $sc['factors']['care'] . ' · Compliance ' . $sc['factors']['compliance'] . '. ' . ($sc['tips'][0] ?? ''), 'data' => ['score' => $sc['score']]];
        }
        case 'vendor_status': {
            $pid = trim($args['partner_id'] ?? '');
            if (!$pid) return ['ok' => false, 'text' => 'partner_id is required (e.g. SP-001).'];
            $st = $pdo->prepare('SELECT * FROM partners WHERE id=?'); $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['ok' => false, 'text' => 'Partner ' . $pid . ' not found.'];
            $jst = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE vendor=? AND status!='Closed'"); $jst->execute([$p['name']]);
            $open = (int)$jst->fetchColumn();
            $vst = $pdo->prepare('SELECT COALESCE(AVG(rating),0), COUNT(*) FROM vendor_ratings WHERE partner=?'); $vst->execute([$pid]);
            $vr = $vst->fetch(PDO::FETCH_NUM);
            $ist = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_invoices WHERE partner=? AND status='Approved'"); $ist->execute([$pid]);
            $pst = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_payouts WHERE partner=? AND status='Paid'"); $pst->execute([$pid]);
            return ['ok' => true, 'text' => '**' . $p['name'] . '** (' . $pid . ') — ' . $p['trade'] . ' · Rating ★' . number_format((float)$vr[0], 1) . ' (' . (int)$vr[1] . ' ratings) · ' . $open . ' open job(s) · Approved ৳' . number_format((int)$ist->fetchColumn()) . ' · Paid ৳' . number_format((int)$pst->fetchColumn()), 'data' => ['rating' => round((float)$vr[0], 1), 'open_jobs' => $open]];
        }
        case 'remit_status': {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
                return ['ok' => false, 'text' => 'Your role cannot view remittance status.'];
            $month = trim($args['month'] ?? '') ?: date('Y-m');
            $sql = 'SELECT * FROM remittances WHERE month=?'; $args = [$month];
            if ($u['role'] === 'owner') { $sql .= ' AND owner_email=?'; $args[] = $u['email']; }
            $st = $pdo->prepare($sql . ' ORDER BY ts DESC'); $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return ['ok' => true, 'text' => 'No remittances for ' . $month . '.'];
            $sent = $conf = $total = 0;
            foreach ($rows as $r) { $r['status'] === 'Confirmed' ? $conf++ : $sent++; $total += (int)$r['amount_fx']; }
            return ['ok' => true, 'text' => count($rows) . ' remittance(s) for ' . $month . ': ' . $sent . ' sent, ' . $conf . ' confirmed · ≈' . $total . ' FX units. First: ' . $rows[0]['id'] . ' (' . $rows[0]['method'] . ').', 'data' => ['count' => count($rows), 'sent' => $sent, 'confirmed' => $conf]];
        }
        case 'list_alerts': {
            $uk = user_key_for($u);
            $st = $pdo->prepare("SELECT * FROM kr_alerts WHERE user_key=? AND status='open' ORDER BY ts DESC LIMIT 15");
            $st->execute([$uk]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return ['ok' => true, 'text' => 'No open KR alerts. You\'re all clear 🎉', 'alerts' => []];
            $parts = [];
            foreach ($rows as $r) $parts[] = '🔔 **' . $r['title'] . '** (' . $r['type'] . ' · ' . $r['severity'] . ') — ' . $r['body'];
            return ['ok' => true, 'text' => count($rows) . ' open alert(s):\n' . implode("\n", $parts), 'alerts' => $rows];
        }
        case 'wa_send': {
            $phone = preg_replace('/[^0-9+]/', '', trim($args['phone'] ?? ''));
            $msg = trim($args['message'] ?? '');
            if (!$phone || !$msg) return ['ok' => false, 'text' => 'phone and message are required.'];
            $uk = user_key_for($u);
            $st = $pdo->prepare('SELECT id FROM wa_channels WHERE user_key=? AND phone=? AND status="active"');
            $st->execute([$uk, $phone]);
            $ch = $st->fetchColumn();
            if (!$ch) {
                $pdo->prepare('INSERT INTO wa_channels (id, user_key, phone, provider) VALUES (?,?,?,?)')
                    ->execute(['WA-' . substr(md5($uk . $phone), 0, 8), $uk, $phone, 'whatsapp']);
                $ch = $pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO kr_wa_msgs (user_key, direction, body) VALUES (?,?,?)')->execute([$uk, 'out', $msg]);
            audit($u['name'], 'KR WhatsApp send', 'ai', $ch, $phone . ' — ' . mb_substr($msg, 0, 60, 'UTF-8'));
            return ['ok' => true, 'text' => '✅ WhatsApp message sent to **' . $phone . '** (logged on your KR channel). In production this routes via the Twilio gateway.', 'data' => ['phone' => $phone, 'msg' => $msg]];
        }
        case 'nid_check': {
            $nid = preg_replace('/[^0-9]/', '', trim($args['nid'] ?? ''));
            if (!$nid) return ['ok' => false, 'text' => 'A NID number is required — try "check NID 1990123456789".'];
            $v = nid_validate($nid);
            $parts = ['🪪 **NID check**: `' . $nid . '` (' . ($v['len'] === 10 ? 'legacy 10-digit' : '17-digit') . ')'];
            $parts[] = $v['ok'] ? '✅ Checksum valid — structurally sound.' : '❌ ' . $v['reason'];
            $tid = null;
            $st = $pdo->prepare('SELECT id, name FROM tenants WHERE nid=? LIMIT 1'); $st->execute([$nid]);
            $hit = $st->fetch(PDO::FETCH_ASSOC);
            if ($hit) {
                $parts[] = '👤 Matches tenant **' . $hit['name'] . '** (' . $hit['id'] . ').';
                $st = $pdo->prepare("SELECT status FROM nid_verifications WHERE tenant=? ORDER BY ts DESC LIMIT 1"); $st->execute([$hit['id']]);
                $status = (string)$st->fetchColumn();
                $parts[] = $status ? 'Verification status: **' . $status . '**.' : 'No verification record yet — staff can save one in Trust Engine.';
            } else {
                $parts[] = 'No tenant on file with this NID yet.';
            }
            return ['ok' => true, 'text' => implode("\n", $parts), 'data' => ['nid' => $nid, 'valid' => $v['ok'], 'reason' => $v['reason'], 'tenant' => $hit ? $hit['id'] : null]];
        }
        case 'gen_notice': {
            $ntype = trim($args['ntype'] ?? ''); $leaseId = trim($args['lease'] ?? '');
            if (!$ntype || !$leaseId) return ['ok' => false, 'text' => 'Both ntype (eviction|rent_hike|termination|tds_alert) and lease (L-001) are required.'];
            $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$leaseId]);
            $lease = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lease) return ['ok' => false, 'text' => 'Lease not found.'];
            $b = legal_notice_build($pdo, $ntype, $lease);
            $meta = legal_notice_meta($ntype);
            return ['ok' => true, 'text' => '⚖️ **' . $meta['en'] . '** drafted for ' . $leaseId . ' — ' . $b['days'] . ' days notice, effective ' . $b['effective'] . ".
" . $b['body'], 'data' => ['ntype' => $ntype, 'lease' => $leaseId, 'days' => $b['days'], 'effective' => $b['effective']]];
        }
        case 'lease_audit': {
            $leaseId = trim($args['lease'] ?? '');
            if (!$leaseId) return ['ok' => false, 'text' => 'A lease id is required — try "audit lease L-004".'];
            $a = lease_audit($pdo, $leaseId);
            if (!$a) return ['ok' => false, 'text' => 'Lease not found.'];
            $lines = ['🛡️ **Compliance audit — ' . $leaseId . '**: score ' . $a['score'] . '/100 (' . count($a['findings']) . ' finding(s)).'];
            foreach ($a['findings'] as $f) $lines[] = '  ' . ($f['sev'] === 'high' ? '🔴' : ($f['sev'] === 'med' ? '🟠' : '🔵')) . ' ' . $f['label'] . ' — ' . $f['detail'];
            if (!$a['findings']) $lines[] = '  ✅ No red flags.';
            return ['ok' => true, 'text' => implode("
", $lines), 'data' => $a];
        }
        case 'tds_due': {
            if ($u['role'] === 'partner' || $u['role'] === 'tenant') return ['ok' => false, 'text' => 'TDS view is for staff.'];
            $t = tds_due($pdo, trim($args['month'] ?? date('Y-m')));
            $lines = ['🏛️ **TDS due (' . $t['month'] . ')** at ' . $t['rate'] . '% — total ৳' . number_format($t['total']) . ' across ' . count($t['items']) . ' commercial lease(s).'];
            foreach ($t['items'] as $i) $lines[] = '  ' . $i['lease'] . ' · ' . $i['tenant'] . ' · ৳' . number_format($i['tds']) . '/mo';
            return ['ok' => true, 'text' => implode("
", $lines), 'data' => $t];
        }
        case 'case_status': {
            $id = trim($args['case_id'] ?? '');
            if (!$id) return ['ok' => false, 'text' => 'A case id is required — try "case status CASE-001".'];
            $st = $pdo->prepare('SELECT * FROM cases WHERE id=?'); $st->execute([$id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) return ['ok' => false, 'text' => 'Case not found.'];
            $lines = ['⚖️ **' . $c['title'] . '** (' . $c['id'] . ')', '  Stage: **' . $c['stage'] . '** · Status: ' . $c['status'] . ' · Type: ' . $c['type'] . ($c['lawyer'] ? ' · Counsel: ' . $c['lawyer'] : '') . ($c['next_hearing'] ? ' · Next hearing: ' . $c['next_hearing'] : '')];
            $st = $pdo->prepare('SELECT * FROM case_events WHERE case_id=? ORDER BY ts DESC LIMIT 5'); $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $lines[] = '  • ' . substr($e['ts'], 0, 10) . ' — ' . $e['body'];
            return ['ok' => true, 'text' => implode("
", $lines), 'data' => $c];
        }
        case 'portfolio_forecast': {
            if ($u['role'] === 'partner' || $u['role'] === 'tenant')
                return ['ok' => false, 'text' => 'Forecasting is for owners and management.'];
            $f = analytics_forecast($pdo);
            return ['ok' => true, 'text' => '📈 **12-month forecast**: expected collections ≈ ৳' . number_format($f['total_forecast']) . ' (avg ৳' . number_format($f['avg_month']) . '/mo at ' . $f['collection_rate'] . '% rate). Occupancy ' . $f['occupancy'] . '% (' . $f['leased'] . '/' . $f['units'] . '). Vacancy loss ≈ ৳' . number_format($f['vacancy_loss']) . '/mo. Renewals due: ' . $f['renewals_due'] . '. Risk: ' . $f['top_risk'], 'data' => $f];
        }
    }
    return ['ok' => false, 'text' => 'Unknown tool: ' . $name];
}

/* DeepSeek chat completions + function calling (OpenAI-compatible) */
function ai_llm_call($messages, $pdo = null) {
    $cfg = AI_CONFIG($pdo);
    $payload = [
        'model' => $cfg['model'],
        'messages' => $messages,
        'tools' => ai_tool_defs(),
        'tool_choice' => 'auto',
        'temperature' => 0.3,
        'max_tokens' => 1200,
    ];
    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 50,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['error' => $err];
    $j = json_decode($resp, true);
    if (!isset($j['choices'][0]['message'])) {
        $msg = $j['error']['message'] ?? substr($resp, 0, 300);
        return ['error' => $msg];
    }
    return $j['choices'][0]['message'];
}
function ai_tool_defs() {
    $f = function ($name, $desc, $props, $req = []) {
        return ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc, 'parameters' => ['type' => 'object', 'properties' => $props, 'required' => $req]]];
    };
    return [
        $f('generate_invoice', 'Create a monthly rent invoice for a lease. Use when asked to generate/invoice/raise a bill.', ['lease_id' => ['type' => 'string', 'description' => 'Lease id like L-003'], 'month' => ['type' => 'string', 'description' => 'Month as YYYY-MM']], ['lease_id', 'month']),
        $f('create_ticket', 'Raise a maintenance ticket for a unit.', ['unit_id' => ['type' => 'string', 'description' => 'Unit id like U-002'], 'description' => ['type' => 'string'], 'liability' => ['type' => 'string', 'enum' => ['Landlord', 'Tenant', 'Shared']]], ['unit_id', 'description']),
        $f('check_lease_liability', 'Determine who pays for a repair: landlord, tenant or shared.', ['unit_id' => ['type' => 'string'], 'issue' => ['type' => 'string', 'description' => 'The repair/issue, e.g. leaking roof']], ['issue']),
        $f('calc_holding_tax', 'Compute annual holding tax for a property (GAR → NAV → rate).', ['property_id' => ['type' => 'string', 'description' => 'Property id like P-001']], ['property_id']),
        $f('send_reminder', 'Email a rent reminder for an unpaid invoice to the tenant.', ['invoice_id' => ['type' => 'string', 'description' => 'Invoice id like INV-2026-008']], ['invoice_id']),
        $f('ask_legal', 'Search the Bangladeshi property-law knowledge base (PRCA 1991, TPA 1882, IT Act 2023, holding tax, NRB).', ['query' => ['type' => 'string', 'description' => 'Plain-language legal question']], ['query']),
        $f('rent_roll', 'Per-property or portfolio rent roll: expected, collected, outstanding.', ['property_id' => ['type' => 'string', 'description' => 'Optional property id like P-001 (omit for whole portfolio)']], []),
        $f('portfolio_summary', 'Portfolio analytics: properties, units, occupancy, asset value, monthly rent, unpaid count.', [], []),
        $f('upcoming_renewals', 'Leases that renew within N months.', ['months' => ['type' => 'integer', 'description' => 'Lookahead in months (default 3)']], []),
        $f('collection_summary', 'Rent collected vs issued for a month.', ['month' => ['type' => 'string', 'description' => 'Month as YYYY-MM (default current)']], []),
        $f('capital_gains', 'Estimate capital gains tax on a property (IT Act §128).', ['property_id' => ['type' => 'string', 'description' => 'Property id like P-003']], ['property_id']),
        $f('tds_summary', 'TDS withheld by month (IT Act §109).', ['month' => ['type' => 'string', 'description' => 'Optional month YYYY-MM']], []),
        $f('property_details', 'Property overview with its units.', ['property_id' => ['type' => 'string', 'description' => 'Property id like P-001']], ['property_id']),
        $f('lease_details', 'Lease details: unit, tenant, rent, term, status.', ['lease_id' => ['type' => 'string', 'description' => 'Lease id like L-003']], ['lease_id']),
        $f('tenant_details', 'Tenant profile with their leases.', ['tenant_id' => ['type' => 'string', 'description' => 'Tenant id like T-002']], ['tenant_id']),
        $f('monthly_digest', 'Generate the monthly owner digest: issued, collected, outstanding, new leases, tickets, renewals, top outstanding invoice.', ['month' => ['type' => 'string', 'description' => 'Month as YYYY-MM (default current)']], []),
        $f('list_vacancies', 'List vacant units (optionally per property).', ['property_id' => ['type' => 'string', 'description' => 'Optional property id like P-001']], []),
        $f('post_notice', 'Post an announcement to the notice board (staff roles only).', ['title' => ['type' => 'string'], 'body' => ['type' => 'string']], ['title']),
        $f('referral_link', 'Generate the user\'s referral code and share link (owners/partners/CRM).', [], []),
        $f('remind_overdue', 'Summarize or send rent reminders for ALL unpaid/overdue invoices (dry-run by default; send=true emails tenants).', ['send' => ['type' => 'boolean', 'description' => 'Set true to actually email the tenants']], []),
        $f('payment_recon', 'Payment reconciliation summary: payments vs receipts vs refunds vs gateway sessions.', [], []),
        $f('refund_payment', 'Mark a payment as refunded (finance roles only).', ['payment_id' => ['type' => 'string', 'description' => 'Payment id like PAY-003'], 'reason' => ['type' => 'string']], ['payment_id']),
        $f('gen_document', 'Generate a customizable legal document from templates: tenancy/lease agreement, service contract, or money receipt (rent/advance/service/general).', ['type' => ['type' => 'string', 'description' => 'lease/agreement/tenancy OR contract/service OR receipt'], 'ref' => ['type' => 'string', 'description' => 'Lease id (L-001) for agreements, partner id (SP-001) for contracts, payment/invoice id (PAY-001) for receipts']], ['type', 'ref']),
        $f('tenant_score', 'Fetch a tenant\'s renter scorecard (payment, stability, care, compliance).', ['tenant_id' => ['type' => 'string', 'description' => 'Tenant id like T-002']], ['tenant_id']),
        $f('vendor_status', 'Service partner profile: jobs, QC, ratings, payout totals.', ['partner_id' => ['type' => 'string', 'description' => 'Partner id like SP-001']], ['partner_id']),
        $f('remit_status', 'NRB remittance status for a month (owner/manager/accountant).', ['month' => ['type' => 'string', 'description' => 'Month as YYYY-MM (default current)']], []),
        $f('list_alerts', 'List my open proactive KR alerts (SLA breach, compliance expiry, arrears, renewals).', [], []),
        $f('wa_send', 'Send a WhatsApp-style message to a bound phone on your channel.', ['phone' => ['type' => 'string', 'description' => 'Phone number with country code'], 'message' => ['type' => 'string', 'description' => 'Message text (EN or বাংলা)']], ['phone', 'message']),
        $f('portfolio_forecast', '12-month portfolio forecast: collections, occupancy, vacancy loss, risks.', [], []),
        $f('nid_check', 'Validate a Bangladesh NID (10/17-digit, check-digit) and look up any matching tenant.', ['nid' => ['type' => 'string', 'description' => 'NID number, e.g. 1990123456789']], ['nid']),
        $f('gen_notice', 'Generate a statutory legal notice (eviction, rent hike, lease termination, or TDS advisory) for a lease.', ['ntype' => ['type' => 'string', 'description' => 'eviction|rent_hike|termination|tds_alert'], 'lease' => ['type' => 'string', 'description' => 'Lease id like L-004']], ['ntype', 'lease']),
        $f('lease_audit', 'Run a compliance audit on a lease (deposit cap, TPA §107 registration, commercial TDS, expiry).', ['lease' => ['type' => 'string', 'description' => 'Lease id like L-004']], ['lease']),
        $f('tds_due', 'Monthly TDS obligation on commercial leases (ITA 2023 §109).', ['month' => ['type' => 'string', 'description' => 'Month YYYY-MM (default current)']], []),
        $f('case_status', 'Status of a legal dispute case (stage, lawyer, next hearing, timeline).', ['case_id' => ['type' => 'string', 'description' => 'Case id like CASE-001']], ['case_id']),
        $f('land_status', 'Land/plot guard status for a parcel (status, last visit, next monitoring visit due).', ['parcel' => ['type' => 'string', 'description' => 'Parcel id like LP-001']], ['parcel']),
        $f('land_visit_due', 'List land parcels whose monitoring visit is due or overdue within 30 days.', [], []),
        $f('report_encroachment', 'Flag a land parcel as encroached (anti-encroachment alert for NRB owners).', ['parcel' => ['type' => 'string', 'description' => 'Parcel id like LP-003'], 'note' => ['type' => 'string', 'description' => 'Observation note']], ['parcel']),
        $f('land_summary', 'Land guard portfolio summary: parcels, secure/needs-review/encroached, visits due.', [], []),
        $f('nrb_tax_status', 'NRB tax return status per financial year (income, TDS deducted, estimated tax, CA firm).', [], []),
        $f('nrb_repat_status', 'NRB repatriation pipeline status (Bangladesh Bank / NRP channel requests).', [], []),
        $f('nrb_vacancy_status', 'Vacancy pipeline + end-to-end lease approvals awaiting the owner.', ['vacancy' => ['type' => 'string', 'description' => 'Vacancy id like VC-002 (optional)']], []),
        $f('nrb_dispute_status', 'Virtual dispute resolution status (eviction/damages/arrears, advocate, timeline).', ['dispute' => ['type' => 'string', 'description' => 'Dispute id like DS-001 (optional)']], []),
        $f('concierge_status', 'Legal Concierge service request status (namjari/e-porcha/khatian/holding tax/registration).', ['request' => ['type' => 'string', 'description' => 'Request id like CR-001 (optional)']], []),
        $f('holding_tax_status', 'Holding tax ledger for a property/parcel (city corp, annual value, tax due, status).', ['holding' => ['type' => 'string', 'description' => 'Holding id like HT-001 (optional)']], []),
        $f('concierge_summary', 'Legal Concierge portfolio summary: active requests, fees due, holding taxes due.', [], []),
        $f('smart_lock_status', 'Smart lock status for a unit (code, valid window, used count, revoked?).', ['lock' => ['type' => 'string', 'description' => 'Lock id like SL-001 (optional)']], []),
        $f('camera_status', 'CCTV camera status per property (online/offline, location, last seen).', ['camera' => ['type' => 'string', 'description' => 'Camera id like CV-001 (optional)']], []),
        $f('showing_code', 'Generate a temporary smart-lock code for a vacancy showing (VAS-D flow).', ['showing' => ['type' => 'string', 'description' => 'Showing id like SH-001']], ['showing']),
        $f('health_plan_status', 'Property health check plan status (seasonal AC/roof/drainage/deep-clean).', ['plan' => ['type' => 'string', 'description' => 'Plan id like HP-001 (optional)']], []),
        $f('health_summary', 'Property health check portfolio: plans by season/status, due and overdue.', [], []),
        $f('build_status', 'Build Watch: construction/renovation project status (progress, budget spent, overdue milestones).', ['project_id' => ['type' => 'string', 'description' => 'Project id like BP-001 (optional)']], []),
        $f('gate_inside', 'Gate Watch: who is inside the building right now (visitors, deliveries, workers, vehicles).', [], []),
        $f('gate_visit_status', 'Gate Watch: status of a gate visit (check-in/out, flagged).', ['visit_id' => ['type' => 'string', 'description' => 'Visit id like GV-001']], []),
        $f('gate_vehicle_check', 'Gate Watch: check a vehicle number against the resident registry and watchlist.', ['vehicle_no' => ['type' => 'string', 'description' => 'Vehicle plate like DHAKA-METRO-1234']], []),
        $f('fire_safety_status', 'Fire Safety: asset and incident summary (expired/expiring/faulty assets, open incidents).', ['asset_id' => ['type' => 'string', 'description' => 'Asset id like FA-001 (optional)']], []),
        $f('fire_incident_status', 'Fire Safety: status of a fire/emergency incident.', ['incident_id' => ['type' => 'string', 'description' => 'Incident id like FI-001 (optional)']], []),
        $f('fire_asset_check', 'Fire Safety: check a specific fire asset (expiry + inspection).', ['asset_id' => ['type' => 'string', 'description' => 'Asset id like FA-001']], []),
        $f('systems_status', 'Systems Watch: summary of essential systems (lift/generator/pumps) or one asset.', ['asset_id' => ['type' => 'string', 'description' => 'Asset id like SA-001 (optional)']], []),
        $f('sys_service_due', 'Systems Watch: which systems have service due or overdue.', [], []),
        $f('sys_fuel_check', 'Systems Watch: generator fuel levels, low-fuel flags and last refills.', [], []),
        $f('staff_status', 'Staff Watch: building staff roster summary or one staff member.', ['staff_id' => ['type' => 'string', 'description' => 'Staff id like BS-001 (optional)']], []),
        $f('staff_attendance_today', 'Staff Watch: who is on duty today and who has not marked attendance.', [], []),
        $f('samity_status', 'Kalyan Samity: building society fund summary or one service-charge bill.', ['bill_id' => ['type' => 'string', 'description' => 'Bill id like SB-001 (optional)']], []),
        $f('samity_bills_due', 'Kalyan Samity: which units have outstanding service charges.', [], []),
        $f('samity_arrears', 'Kalyan Samity: arrears owed by unit (outstanding service charge).', [], []),
        $f('payroll_due', 'Staff Watch: unpaid salaries for the previous month.', [], []),
        $f('build_budget', 'Build Watch: total construction budget vs spent across projects, over-budget flags.', [], []),
        $f('build_milestone_due', 'Build Watch: milestones due within the alert window across projects.', [], []),




    ];
}
function ai_system_prompt($u) {
    $pdo = db();
    $sc = ai_scope($u);
    if ($sc && isset($sc['tickets'])) {
        $unpaid = 0; $pend = 0; $open = count($sc['tickets']);
    } elseif ($sc) {
        $li = ai_in_list($sc['invoices']); $ll = ai_in_list($sc['leases']); $lu = ai_in_list($sc['units']);
        $unpaid = (int)ai_q("SELECT COUNT(*) AS n FROM invoices WHERE status != 'Paid' AND id IN ($li)", $sc['invoices'])[0]['n'] ?? 0;
        $pend = (int)ai_q("SELECT COUNT(*) AS n FROM leases WHERE status='Pending Registration' AND id IN ($ll)", $sc['leases'])[0]['n'] ?? 0;
        $open = (int)ai_q("SELECT COUNT(*) AS n FROM tickets WHERE status IN ('Open','In Progress') AND u IN ($lu)", $sc['units'])[0]['n'] ?? 0;
    } else {
        $unpaid = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status != 'Paid'")->fetchColumn();
        $pend = (int)$pdo->query("SELECT COUNT(*) FROM leases WHERE status='Pending Registration'")->fetchColumn();
        $open = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open','In Progress')")->fetchColumn();
    }
    $scope_note = $sc ? " The user sees ONLY their own records — never reveal or reference other tenants'/partners' data." : '';
    return "You are KR, KRTaker's AI property caretaker for Bangladesh. Today is " . gmdate('d M Y') . "."
        . " You are assisting {$u['name']} (role: {$u['role']})."
        . " Portfolio snapshot: {$unpaid} unpaid invoices, {$pend} leases pending registration, {$open} open maintenance tickets."
        . $scope_note
        . " Rules: answer concisely; use the provided tools to take real actions (invoices, tickets, reminders, tax calcs);"
        . " the caller's role is enforced server-side on every tool — never claim an action you did not successfully run;"
        . " support both English and Bengali; cite the law (PRCA 1991, TPA 1882, IT Act 2023) where relevant.";
}
function ai_offline($u, $q) {
    $s = strtolower($q);
    $out = ['ok' => true, 'mode' => 'offline', 'reply' => '', 'actions' => [], 'tool' => ''];
    $done = function ($tool, $text, $actions = []) use (&$out) {
        $out['tool'] = $tool; $out['reply'] = $text; $out['actions'] = $actions; return $out;
    };
    /* Phase 12 intents: document generation (agreement/contract/receipt) — BEFORE invoice intent so "generate agreement" wins */
    if (preg_match('/(agreement|tenancy|lease contract|lease agreement|লিজ)/i', $s) && preg_match('/(L-\d+)/', $q, $m)) {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant'], true))
            return $done('', 'Your role cannot generate legal documents.');
        $r = ai_execute_tool($u, 'gen_document', ['type' => 'lease', 'ref' => $m[1]]);
        return $done('gen_document', $r['text']);
    }
    if (preg_match('/(service contract|contract|চুক্তি)/i', $s) && preg_match('/(SP-\d+)/', $q, $m)) {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant'], true))
            return $done('', 'Your role cannot generate legal documents.');
        $r = ai_execute_tool($u, 'gen_document', ['type' => 'contract', 'ref' => $m[1]]);
        return $done('gen_document', $r['text']);
    }
    if (preg_match('/(receipt|money receipt|রসিদ|রিসিট)/i', $s) && preg_match('/(PAY-\d+|INV-\d+)/', $q, $m)) {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant'], true))
            return $done('', 'Your role cannot generate receipts.');
        $r = ai_execute_tool($u, 'gen_document', ['type' => 'receipt', 'ref' => $m[1]]);
        return $done('gen_document', $r['text']);
    }
    /* Phase 44: legal engine intents — MUST run before generate-invoice (matches 'generate') and the tds catch-all */
    if (preg_match('/(notice|eviction|rent ?hike|termination|উচ্ছেদ|নোটিশ|ভাড়া বৃদ্ধি|লিজ সমাপ্ত)/i', $s) && preg_match('/(L-\d+)/', $q, $m)) {
        $ntype = preg_match('/(eviction|উচ্ছেদ)/i', $s) ? 'eviction' : (preg_match('/(rent ?hike|ভাড়া বৃদ্ধি)/i', $s) ? 'rent_hike' : (preg_match('/(termination|সমাপ্ত)/i', $s) ? 'termination' : (preg_match('/(tds|টিডিএস)/i', $s) ? 'tds_alert' : 'eviction')));
        $r = ai_execute_tool($u, 'gen_notice', ['ntype' => $ntype, 'lease' => $m[1]]);
        return $done('gen_notice', $r['text']);
    }
    if (preg_match('/(audit|compliance check|অডিট|কমপ্লায়েন্স চেক)/i', $s) && preg_match('/(L-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'lease_audit', ['lease' => $m[1]]);
        return $done('lease_audit', $r['text']);
    }
    if (preg_match('/(tds|টিডিএস)/i', $s) && preg_match('/(due|obligation|বকেয়া|কর্তন|owed)/i', $s)) {
        if ($u['role'] === 'partner' || $u['role'] === 'tenant') return $done('', 'TDS view is for staff.');
        $r = ai_execute_tool($u, 'tds_due', []);
        return $done('tds_due', $r['text']);
    }
    if (preg_match('/(case|dispute|মামলা)/i', $s) && preg_match('/(CASE-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'case_status', ['case_id' => $m[1]]);
        return $done('case_status', $r['text']);
    }
    /* Phase 45: land guard intents — anti-encroachment monitoring (NRB owners) */
    if (preg_match('/(LP-\d+)/', $q, $m)) {
        if (preg_match('/(encroach|occup|দখল)/i', $s)) {
            $r = ai_execute_tool($u, 'report_encroachment', ['parcel' => $m[1], 'note' => $q]);
            return $done('report_encroachment', $r['text'], $r['ok'] ? ['refresh'] : []);
        }
        $r = ai_execute_tool($u, 'land_status', ['parcel' => $m[1]]);
        return $done('land_status', $r['text']);
    }
    if (preg_match('/(land|plot|জমি|প্লট)/i', $s) && preg_match('/(visit|due|overdue|পরিদর্শন|বাকি|ওভারডিউ)/i', $s)) {
        $r = ai_execute_tool($u, 'land_visit_due', []);
        return $done('land_visit_due', $r['text']);
    }
    if (preg_match('/(land guard|land summary|জমির সারাংশ|ভূমি গার্ড)/i', $s)) {
        $r = ai_execute_tool($u, 'land_summary', []);
        return $done('land_summary', $r['text']);
    }

    /* Phase 46: NRB Hub intents — tax, repatriation, vacancy approvals, virtual disputes (before generate-invoice) */
    if (preg_match('/(DS-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'nrb_dispute_status', ['dispute' => $m[1]]);
        return $done('nrb_dispute_status', $r['text']);
    }
    if (preg_match('/(VC-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'nrb_vacancy_status', ['vacancy' => $m[1]]);
        return $done('nrb_vacancy_status', $r['text']);
    }
    if (preg_match('/(TX-\d+)|(tax return|ট্যাক্স রিটার্ন|কর রিটার্ন)/i', $q)) {
        $r = ai_execute_tool($u, 'nrb_tax_status', []);
        return $done('nrb_tax_status', $r['text']);
    }
    if (preg_match('/(RP-\d+)|(repatriat|রেমিট্যান্স|দেশে পাঠানো)/i', $q)) {
        $r = ai_execute_tool($u, 'nrb_repat_status', []);
        return $done('nrb_repat_status', $r['text']);
    }
    if (preg_match('/(dispute|বিরোধ|মামলা|evict|উচ্ছেদ|নোটিশ পাওয়া)/i', $s)) {
        $r = ai_execute_tool($u, 'nrb_dispute_status', []);
        return $done('nrb_dispute_status', $r['text']);
    }
    if (preg_match('/(vacanc|ভ্যাক্যান্সি|approval|অনুমোদন|লিজ সই)/i', $s)) {
        $r = ai_execute_tool($u, 'nrb_vacancy_status', []);
        return $done('nrb_vacancy_status', $r['text']);
    }
    /* Phase 47: legal concierge intents — namjari/e-porcha/khatian/holding-tax tracking (before generate-invoice) */
    if (preg_match('/(CR-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'concierge_status', ['request' => $m[1]]);
        return $done('concierge_status', $r['text']);
    }
    if (preg_match('/(HT-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'holding_tax_status', ['holding' => $m[1]]);
        return $done('holding_tax_status', $r['text']);
    }
    if (preg_match('/(namjari|মিউটেশন|mutation|e-porcha|ই-পর্চা|পর্চা|khatian|খতিয়ান|holding tax|হোল্ডিং ট্যাক্স|concierge|কনসিয়ার্জ)/i', $s)) {
        $r = ai_execute_tool($u, 'concierge_summary', []);
        return $done('concierge_summary', $r['text']);
    }
    /* Phase 48: smart home + health check intents (before generate-invoice) */
    if (preg_match('/(SL-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'smart_lock_status', ['lock' => $m[1]]);
        return $done('smart_lock_status', $r['text']);
    }
    if (preg_match('/(CV-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'camera_status', ['camera' => $m[1]]);
        return $done('camera_status', $r['text']);
    }
    if (preg_match('/(SH-\\d+)/', $q, $m) && preg_match('/(lock|code|access|স্মার্ট লক|কোড|প্রবেশ)/i', $s)) {
        $r = ai_execute_tool($u, 'showing_code', ['showing' => $m[1]]);
        return $done('showing_code', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    if (preg_match('/(HP-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'health_plan_status', ['plan' => $m[1]]);
        return $done('health_plan_status', $r['text']);
    }
    if (preg_match('/(health check|health plan|health summary|হেলথ চেক|হেলথ প্ল্যান|স্বাস্থ্য পরীক্ষা)/i', $s)) {
        $r = ai_execute_tool($u, 'health_summary', []);
        return $done('health_summary', $r['text']);
    }
    if (preg_match('/(smart lock|স্মার্ট লক)/i', $s)) {
        $r = ai_execute_tool($u, 'smart_lock_status', []);
        return $done('smart_lock_status', $r['text']);
    }
    if (preg_match('/(camera|cctv|ক্যামেরা|সিসিটিভি)/i', $s)) {
        $r = ai_execute_tool($u, 'camera_status', []);
        return $done('camera_status', $r['text']);
    }
    if (preg_match('/(BP-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'build_status', ['project_id' => $m[1]]);
        return $done('build_status', $r['text']);
    }
    if (preg_match('/(BM-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'build_milestone_due', []);
        return $done('build_milestone_due', $r['text']);
    }
    if (preg_match('/(milestone|মাইলস্টোন)/i', $s)) {
        $r = ai_execute_tool($u, 'build_milestone_due', []);
        return $done('build_milestone_due', $r['text']);
    }
    if (preg_match('/(budget|বাজেট)/i', $s) && preg_match('/(construction|renovation|build|বিল্ডিং|নির্মাণ|সংস্কার|project|প্রকল্প)/i', $s)) {
        $r = ai_execute_tool($u, 'build_budget', []);
        return $done('build_budget', $r['text']);
    }
    if (preg_match('/(inside now|who.*inside|কে কে আছে|ভেতরে|গেট|gate|visitor|দর্শনার্থী|আগন্তুক)/i', $s)) {
        $r = ai_execute_tool($u, 'gate_inside', []);
        return $done('gate_inside', $r['text']);
    }
    if (preg_match('/(build|construction|renovation|বিল্ডিং|নির্মাণ|সংস্কার)/i', $s)) {
        $r = ai_execute_tool($u, 'build_status', []);
        return $done('build_status', $r['text']);
    }
    /* gate watch — visitor/vehicle queries (before generate-invoice) */
    if (preg_match('/(GV-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'gate_visit_status', ['visit_id' => $m[1]]);
        return $done('gate_visit_status', $r['text']);
    }
    if (preg_match('/(vehicle|গাড়ি|গাড়ির|রেজিস্ট্রেশন)/i', $s) && preg_match('/([A-Z]{2,12}(?:[- ][A-Z]{2,12})?[- ]?\d{4,6})/', $q, $vm)) {
        $r = ai_execute_tool($u, 'gate_vehicle_check', ['vehicle_no' => $vm[1]]);
        return $done('gate_vehicle_check', $r['text']);
    }
    /* fire safety — asset/incident queries (before generate-invoice) */
    if (preg_match('/(FA-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'fire_asset_check', ['asset_id' => $m[1]]);
        return $done('fire_asset_check', $r['text']);
    }
    if (preg_match('/(FI-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'fire_incident_status', ['incident_id' => $m[1]]);
        return $done('fire_incident_status', $r['text']);
    }
    if (preg_match('/(fire safety|fire extinguisher|অগ্নি|ফায়ার|আগুন|fire|safety drill|ড্রিল)/i', $s)) {
        $r = ai_execute_tool($u, 'fire_safety_status', []);
        return $done('fire_safety_status', $r['text']);
    }
    if (preg_match('/(incident|accident|দুর্ঘটনা|ঘটনা)/i', $s)) {
        $r = ai_execute_tool($u, 'fire_incident_status', []);
        return $done('fire_incident_status', $r['text']);
    }

    /* systems watch — lift/generator/pumps (before generate-invoice) */
    if (preg_match('/(SA-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'systems_status', ['asset_id' => $m[1]]);
        return $done('systems_status', $r['text']);
    }
    if (preg_match('/(services? due|সার্ভিস ডিউ|service overdue)/i', $s)) {
        $r = ai_execute_tool($u, 'sys_service_due', []);
        return $done('sys_service_due', $r['text']);
    }
    if (preg_match('/(fuel|ডিজেল|জ্বালানি|refill|রিফিল)/i', $s)) {
        $r = ai_execute_tool($u, 'sys_fuel_check', []);
        return $done('sys_fuel_check', $r['text']);
    }
    if (preg_match('/(systems watch|system status|systems status|lift|লিফট|generator|জেনারেটর|water pump|পাম্প|রক্ষণাবেক্ষণ)/i', $s)) {
        $r = ai_execute_tool($u, 'systems_status', []);
        return $done('systems_status', $r['text']);
    }

    /* staff watch — roster/attendance/payroll (before generate-invoice) */
    if (preg_match('/(BS-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'staff_status', ['staff_id' => $m[1]]);
        return $done('staff_status', $r['text']);
    }
    if (preg_match('/(payroll|salary|বেতন|মাইনে|পে-রোল)/i', $s)) {
        $r = ai_execute_tool($u, 'payroll_due', []);
        return $done('payroll_due', $r['text']);
    }
    if (preg_match('/(attendance|উপস্থিতি|on duty|duty|ডিউটি)/i', $s)) {
        $r = ai_execute_tool($u, 'staff_attendance_today', []);
        return $done('staff_attendance_today', $r['text']);
    }
    if (preg_match('/(staff watch|staff|কর্মচারী|স্টাফ|guard|গার্ড|cleaner|পিয়ন|রক্ষী)/i', $s)) {
        $r = ai_execute_tool($u, 'staff_status', []);
        return $done('staff_status', $r['text']);
    }
    /* kalyan samity — service charges/arrears/fund (before generate-invoice) */
    if (preg_match('/(SB-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'samity_status', ['bill_id' => $m[1]]);
        return $done('samity_status', $r['text']);
    }
    if (preg_match('/(arrears|বকেয়া|পাওনা|outstanding)/i', $s) && preg_match('/(service charge|সার্ভিস চার্জ|কাল্যান সমিতি|সমিতি|samity|society|society bill|বিল)/i', $s)) {
        $r = ai_execute_tool($u, 'samity_arrears', []);
        return $done('samity_arrears', $r['text']);
    }
    if (preg_match('/(charges? due|bill due|বিল বাকি|দেয়)/i', $s)) {
        $r = ai_execute_tool($u, 'samity_bills_due', []);
        return $done('samity_bills_due', $r['text']);
    }
    if (preg_match('/(service charge|সার্ভিস চার্জ|কাল্যান সমিতি|সমিতি|samity|society|kalyan)/i', $s)) {
        $r = ai_execute_tool($u, 'samity_status', []);
        return $done('samity_status', $r['text']);
    }
    /* generate invoice */

    if (preg_match('/invoice|generate|bill/', $s) && preg_match('/(L-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'generate_invoice', ['lease_id' => $m[1], 'month' => gmdate('Y-m')]);
        return $done('generate_invoice', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    /* create ticket */
    if (preg_match('/ticket|raise|report issue|repair/', $s) && preg_match('/(U-\d+)/', $q, $m)) {
        $desc = preg_replace('/.*?(?:ticket|issue|repair)[:\s]*/i', '', $q);
        $r = ai_execute_tool($u, 'create_ticket', ['unit_id' => $m[1], 'description' => $desc ?: 'Reported issue via KR']);
        return $done('create_ticket', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    /* liability */
    if (preg_match('/liab|who pays|who pay|repair cost/', $s)) {
        $issue = preg_replace('/.*?(?:liability|who pays|who pay)[:\s]*/i', '', $q) ?: 'maintenance';
        $issue = preg_replace('/^(?:for a|for the|for|a|the)\s+/i', '', $issue);
        $r = ai_execute_tool($u, 'check_lease_liability', ['unit_id' => '', 'issue' => $issue]);
        return $done('check_lease_liability', $r['text']);
    }
    /* holding tax */
    if (preg_match('/holding tax/', $s) && preg_match('/(P-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'calc_holding_tax', ['property_id' => $m[1]]);
        return $done('calc_holding_tax', $r['text']);
    }
    /* reminder */
    if (preg_match('/remind|follow up/', $s) && preg_match('/(INV-\d+-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'send_reminder', ['invoice_id' => $m[1]]);
        return $done('send_reminder', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    /* portfolio questions answered from live DB (Phase 6: row-scoped per role) */
    $sc = ai_scope($u);   /* null = org-wide; tenant: ['leases','units','invoices']; partner: ['tickets','org'] */
    /* Phase 9/10 intents: digest, vacancies, referrals — before generic intents */
    if (preg_match('/digest|monthly report|সারসংক্ষেপ|মাসিক রিপোর্ট|মাসিক প্রতিবেদন|ডাইজেস্ট/i', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $month = ai_month($q);
        $r = ai_execute_tool($u, 'monthly_digest', ['month' => $month]);
        return $done('monthly_digest', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    if (preg_match('/vacan|খালি|কোন ইউনিট|empty unit|available unit/i', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $pid = preg_match('/(P-\d+)/', $q, $m) ? $m[1] : '';
        $r = ai_execute_tool($u, 'list_vacancies', ['property_id' => $pid]);
        return $done('list_vacancies', $r['text']);
    }
    if (preg_match('/referral|রেফার|share link|share code|invite/i', $s)) {
        $r = ai_execute_tool($u, 'referral_link', []);
        return $done('referral_link', $r['text']);
    }
    /* Phase 11 intents: collections autopilot + reconciliation */
    if (preg_match('/remind all|remind everyone|send reminders|send reminder|overdue|বকেয়া|রিমাইন্ডার/i', $s)) {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'crm'], true))
            return $done('', 'Your role cannot run collections reminders.');
        $send = preg_match('/send|পাঠাও|পাঠান/i', $s) && !preg_match('/^.*(how many|list|show|summary).*$/i', $s);
        $r = ai_execute_tool($u, 'remind_overdue', ['send' => $send]);
        return $done('remind_overdue', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    if (preg_match('/recon|reconciliation|রিকনসিল|মিল/i', $s)) {
        if ($u['role'] === 'partner' || $u['role'] === 'tenant') return $done('', 'Your role cannot view reconciliation.');
        $r = ai_execute_tool($u, 'payment_recon', []);
        return $done('payment_recon', $r['text']);
    }
    if (preg_match('/refund|রিফান্ড/i', $s) && preg_match('/(PAY-\d+)/', $q, $m)) {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
            return $done('', 'Your role cannot process refunds.');
        $reason = preg_replace('/.*?(?:refund|রিফান্ড)[:\s]*/i', '', $q);
        $reason = preg_replace('/(PAY-\d+)\s*/i', '', $reason);
        $r = ai_execute_tool($u, 'refund_payment', ['payment_id' => $m[1], 'reason' => trim($reason)]);
        return $done('refund_payment', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    /* richer demo-mode intents (EN + বাংলা) — before generic intents so month/analytics queries win */
    if (preg_match('/rent roll|রেন্ট রোল|রেন্টাল|collection report/i', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $pid = preg_match('/(P-\d+)/', $q, $m) ? $m[1] : '';
        $r = ai_execute_tool($u, 'rent_roll', ['property_id' => $pid]);
        return $done('rent_roll', $r['text'], $r['ok'] && !$pid ? ['refresh'] : []);
    }
    if (preg_match('/renew|নবায়ন|রিনিউ/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $months = (preg_match('/(\d+)\s*months?/i', $q, $m) || preg_match('/(\d+)\s*মাস/', $q, $m)) ? (int)$m[1] : 3;
        $r = ai_execute_tool($u, 'upcoming_renewals', ['months' => $months]);
        return $done('upcoming_renewals', $r['text']);
    }
    if (preg_match('/portfolio|total value|asset value|মোট মূল্য|পোর্টফোলিও|সম্পত্তির মূল্য/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $r = ai_execute_tool($u, 'portfolio_summary', []);
        return $done('portfolio_summary', $r['text']);
    }
    if (preg_match('/collected|collection|আদায় হয়েছে|কালেকশন|কত ভাড়া|কত টাকা/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $r = ai_execute_tool($u, 'collection_summary', ['month' => ai_month($q)]);
        return $done('collection_summary', $r['text'], $r['ok'] ? ['refresh'] : []);
    }
    if (preg_match('/capital gain|মুনাফা|লাভ|নিট প্রাপ্তি/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $pid = preg_match('/(P-\d+)/', $q, $m) ? $m[1] : '';
        if (!$pid) return $done('', 'Which property? Include a property id like P-003 — or say "capital gains for P-003".');
        $r = ai_execute_tool($u, 'capital_gains', ['property_id' => $pid]);
        return $done('capital_gains', $r['text']);
    }
    if (preg_match('/property details|show property|সম্পত্তি|প্রোপার্টি/', $s) && preg_match('/(P-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'property_details', ['property_id' => $m[1]]);
        return $done('property_details', $r['text']);
    }
    if (preg_match('/lease details|show lease|লিজ|লীজ/', $s) && preg_match('/(L-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'lease_details', ['lease_id' => $m[1]]);
        return $done('lease_details', $r['text']);
    }
    if (preg_match('/tenant details|show tenant|ভাড়াটে|টেন্যান্ট/', $s) && preg_match('/(T-\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'tenant_details', ['tenant_id' => $m[1]]);
        return $done('tenant_details', $r['text']);
    }
    if (preg_match('/occupancy/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $units = $sc ? $sc['units'] : null;
        $where = $units !== null ? ' WHERE id IN (' . ai_in_list($units) . ')' : '';
        $total = (int)ai_q('SELECT COUNT(*) AS n FROM units' . $where, $units ?: [])[0]['n'] ?? 0;
        $leased = (int)(ai_q("SELECT COUNT(*) AS n FROM units" . $where . " AND status='Leased'", $units ?: [])[0]['n'] ?? 0);
        $pct = $total ? round($leased / $total * 100) : 0;
        return $done('', "Portfolio occupancy is **{$pct}%** — {$leased} of {$total} units leased.");
    }
    if (preg_match('/unpaid|overdue|outstanding|arrears/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $inv = $sc ? $sc['invoices'] : null;
        $q = "SELECT id,net,status FROM invoices WHERE status!='Paid'";
        $args = [];
        if ($inv !== null) { $q .= ' AND id IN (' . ai_in_list($inv) . ')'; $args = $inv; }
        $rows = ai_q($q, $args);
        if (!$rows) return $done('', 'All invoices are paid 🎉 — nothing outstanding.');
        $sum = array_sum(array_column($rows, 'net'));
        return $done('', count($rows) . ' unpaid invoice(s), total ৳' . number_format($sum) . ' — ' . implode(', ', array_slice(array_column($rows, 'id'), 0, 5)) . '.');
    }
    if (preg_match('/registration|pending/', $s)) {
        if ($sc && ($sc['org'] ?? '')) return $done('', 'Partners cannot view lease registration status.');
        $ls = $sc ? $sc['leases'] : null;
        $q = "SELECT id FROM leases WHERE status='Pending Registration'";
        $args = [];
        if ($ls !== null) { $q .= ' AND id IN (' . ai_in_list($ls) . ')'; $args = $ls; }
        $rows = ai_q($q, $args);
        $rows = array_map(fn($r) => $r['id'], $rows);
        return $done('', $rows ? 'Pending Registration (TPA §107): ' . implode(', ', $rows) . ' — want the deed checklist?' : 'No leases pending registration.');
    }
    if (preg_match('/tds/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'As a service partner you can view only your assigned jobs — ask me about tickets or maintenance.');
        $r = ai_execute_tool($u, 'tds_summary', ['month' => preg_match('/\d{4}-\d{2}/', $q) ? ai_month($q) : '']);
        return $done('tds_summary', $r['text']);
    }
    if (preg_match('/ticket|maintenance|mt-/', $s)) {
        if ($sc && isset($sc['tickets'])) {
            $tks = $sc['tickets'];
        } elseif ($sc) {
            $rows = ai_q('SELECT id FROM tickets WHERE u IN (' . ai_in_list($sc['units']) . ')', $sc['units']);
            $tks = array_map(fn($r) => $r['id'], $rows);
        } else {
            $tks = null;
        }
        $q = 'SELECT id,desc,status FROM tickets';
        $args = [];
        if ($tks !== null) { $q .= ' WHERE id IN (' . ai_in_list($tks) . ')'; $args = $tks; }
        $q .= ' ORDER BY id DESC LIMIT 4';
        $rows = ai_q($q, $args);
        return $done('', $rows ? 'Latest tickets: ' . implode(' · ', array_map(fn($r) => $r['id'] . ' (' . $r['status'] . ') ' . $r['desc'], $rows)) . '.' : 'No tickets yet.');
    }
    /* Phase 40 intents: WhatsApp send - BEFORE Bengali intents so bhasa messages route here */
    if (preg_match('/whatsapp|হোয়াটসঅ্যাপ|wa send/i', $s)) {
        $phone = preg_match('/(\\+?\\d{9,15})/', $q, $m) ? $m[1] : '';
        if (!$phone) return $done('', 'Send a WhatsApp message like: "WhatsApp +880****0000: ভাড়ার রিমাইন্ডার পাঠান"');
        $msg = preg_replace('/.*?whatsapp\\s*\\+?\\d{9,15}\\s*[:：]?\\s*/i', '', $q);
        $r = ai_execute_tool($u, 'wa_send', ['phone' => $phone, 'message' => $msg ?: 'Hello from KR 🏠']);
        return $done('wa_send', $r['text']);
    }
    /* Bengali intents */
    if (preg_match('/আদায়|ভাড়া(?!টে)|বাকি|অপরিশোধিত/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'সার্ভিস পার্টনার হিসেবে আপনি শুধু আপনার অ্যাসাইন করা কাজ দেখতে পারেন — টিকিট বা মেরামত সম্পর্কে জিজ্ঞেস করুন।');
        $inv = $sc ? $sc['invoices'] : null;
        $q = "SELECT id,net FROM invoices WHERE status!='Paid'";
        $args = [];
        if ($inv !== null) { $q .= ' AND id IN (' . ai_in_list($inv) . ')'; $args = $inv; }
        $rows = ai_q($q, $args);
        if (!$rows) return $done('', 'সব ইনভয়েস পরিশোধিত হয়েছে 🎉 — কোনো বকেয়া নেই।');
        $sum = array_sum(array_column($rows, 'net'));
        return $done('', count($rows) . 'টি অপরিশোধিত ইনভয়েস, মোট ৳' . number_format($sum) . ' — ' . implode(', ', array_slice(array_column($rows, 'id'), 0, 5)) . '। চাইলে রিমাইন্ডার পাঠাতে পারি।');
    }
    if (preg_match('/রেজিস্ট্রেশন|নিবন্ধন/', $s)) {
        $ls = $sc ? $sc['leases'] : null;
        $q = "SELECT id FROM leases WHERE status='Pending Registration'";
        $args = [];
        if ($ls !== null) { $q .= ' AND id IN (' . ai_in_list($ls) . ')'; $args = $ls; }
        $rows = array_map(fn($r) => $r['id'], ai_q($q, $args));
        return $done('', $rows ? 'রেজিস্ট্রেশন বাকি (TPA §107): ' . implode(', ', $rows) . ' — ডিড চেকলিস্ট তৈরি করে দেব?' : 'কোনো লিজ রেজিস্ট্রেশন-বাকি নেই।');
    }
    if (preg_match('/টিডিএস|কর|ট্যাক্স/', $s)) {
        if ($u['role'] === 'partner') return $done('', 'সার্ভিস পার্টনার হিসেবে আপনি শুধু আপনার অ্যাসাইন করা কাজ দেখতে পারেন — টিকিট বা মেরামত সম্পর্কে জিজ্ঞেস করুন।');
        $r = ai_execute_tool($u, 'tds_summary', ['month' => preg_match('/\d{4}-\d{2}/', $q) ? ai_month($q) : '']);
        return $done('tds_summary', $r['text']);
    }
    if (preg_match('/মেরামত|টিকিট|মিস্ত্রি/', $s)) {
        if ($sc && isset($sc['tickets'])) {
            $tks = $sc['tickets'];
        } elseif ($sc) {
            $rows = ai_q('SELECT id FROM tickets WHERE u IN (' . ai_in_list($sc['units']) . ')', $sc['units']);
            $tks = array_map(fn($r) => $r['id'], $rows);
        } else {
            $tks = null;
        }
        $q = 'SELECT id,desc,status FROM tickets';
        $args = [];
        if ($tks !== null) { $q .= ' WHERE id IN (' . ai_in_list($tks) . ')'; $args = $tks; }
        $q .= ' ORDER BY id DESC LIMIT 4';
        $rows = ai_q($q, $args);
        return $done('', $rows ? 'সাম্প্রতিক টিকিট: ' . implode(' · ', array_map(fn($r) => $r['id'] . ' (' . $r['status'] . ') ' . $r['desc'], $rows)) . '।' : 'কোনো টিকিট নেই।');
    }
    /* Phase 40 intents: score, vendor, remittance, alerts, forecast */
    if (preg_match('/score|স্কোর|রেটিং|rating/i', $s) && preg_match('/(T-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'tenant_score', ['tenant_id' => $m[1]]);
        return $done('tenant_score', $r['text']);
    }
    if (preg_match('/vendor|partner|ভেন্ডর|পার্টনার/i', $s) && preg_match('/(SP-\\d+)/', $q, $m)) {
        $r = ai_execute_tool($u, 'vendor_status', ['partner_id' => $m[1]]);
        return $done('vendor_status', $r['text']);
    }
    if (preg_match('/remit|remittance|রেমিট/i', $s)) {
        if ($u['role'] === 'partner' || $u['role'] === 'tenant') return $done('', 'Remittance tracking is for owners and finance staff only.');
        $r = ai_execute_tool($u, 'remit_status', ['month' => ai_month($q)]);
        return $done('remit_status', $r['text']);
    }
    if (preg_match('/alert|সতর্ক|অ্যালার্ট|notify|নোটিফিকেশন/i', $s)) {
        if (preg_match('/dismiss|close|বন্ধ|মুছ/i', $s)) {
            $r = ai_execute_tool($u, 'list_alerts', []);
            $pdo2 = db();
            $pdo2->prepare("UPDATE kr_alerts SET status='dismissed', resolved_at=datetime('now') WHERE user_key=? AND status='open'")->execute([user_key_for($u)]);
            return $done('list_alerts', 'All ' . count($r['alerts'] ?? []) . ' open alert(s) dismissed. ✅');
        }
        $r = ai_execute_tool($u, 'list_alerts', []);
        return $done('list_alerts', $r['text']);
    }
    if (preg_match('/forecast|পূর্বাভাস|অনুমান|projection|projected/i', $s)) {
        if ($u['role'] === 'partner' || $u['role'] === 'tenant') return $done('', 'Forecasting is for owners and management.');
        $r = ai_execute_tool($u, 'portfolio_forecast', []);
        return $done('portfolio_forecast', $r['text']);
    }
    if (preg_match('/nid|এনআইডি|জাতীয় পরিচয়/i', $s) && preg_match('/(\\d{10,17})/', $q, $m)) {
        $r = ai_execute_tool($u, 'nid_check', ['nid' => $m[1]]);
        return $done('nid_check', $r['text']);
    }
    /* legal KB */
    $hits = legal_search($q);
    if ($hits) return $done('ask_legal', implode("\n\n", array_map(fn($h) => '**' . $h['title'] . '** — ' . $h['body'], $hits)));
    return $done('', 'I can help with leases, registration gates (TPA §107), TDS/holding tax, PRCA rules, maintenance, payments and reports. Try: "generate invoice for L-003", "how much collected in June?", "rent roll", "which leases renew?", "portfolio summary", "capital gains for P-003", "who pays for a leaking roof?", or "send reminder for INV-2026-008". বাংলায়ও জিজ্ঞেস করতে পারেন — ভাড়া আদায়, রেন্ট রোল, রেজিস্ট্রেশন, কর, মেরামত।');
}
