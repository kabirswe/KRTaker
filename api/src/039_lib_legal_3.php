function num_to_words_en($n) {
    if ($n === 0) return 'Zero';
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $en1000 = function($x) use ($ones, $tens) {
        $out = '';
        if ($x >= 100) { $out .= $ones[intdiv($x, 100)] . ' Hundred'; $x %= 100; if ($x) $out .= ' '; }
        if ($x >= 20) { $out .= $tens[intdiv($x, 10)]; if ($x % 10) $out .= '-' . $ones[$x % 10]; }
        elseif ($x > 0) $out .= $ones[$x];
        return $out;
    };
    $groups = [10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'];
    $out = '';
    foreach ($groups as $g => $name) {
        if ($n >= $g) { $out .= $en1000(intdiv($n, $g)) . ' ' . $name . ' '; $n %= $g; }
    }
    if ($n) $out .= $en1000($n);
    return trim($out);
}
function num_to_words_bn($n) {
    if ($n === 0) return 'শূন্য';
    $ones = ['','এক','দুই','তিন','চার','পাঁচ','ছয়','সাত','আট','নয়','দশ','এগারো','বারো','তেরো','চৌদ্দ','পনেরো','ষোল','সতেরো','আঠারো','উনিশ'];
    $tens = ['','','বিশ','ত্রিশ','চল্লিশ','পঞ্চাশ','ষাট','সত্তর','আশি','নব্বই'];
    $teens = [
        21=>'একুশ',22=>'বাইশ',23=>'তেইশ',24=>'চব্বিশ',25=>'পঁচিশ',26=>'ছাব্বিশ',27=>'সাতাশ',28=>'আটাশ',29=>'ঊনত্রিশ',
        31=>'একত্রিশ',32=>'বত্রিশ',33=>'তেত্রিশ',34=>'চৌত্রিশ',35=>'পঁয়ত্রিশ',36=>'ছত্রিশ',37=>'সাঁইত্রিশ',38=>'আটত্রিশ',39=>'ঊনচল্লিশ',
        41=>'একচল্লিশ',42=>'বিয়াল্লিশ',43=>'তেতাল্লিশ',44=>'চুয়াল্লিশ',45=>'পঁয়তাল্লিশ',46=>'ছেচল্লিশ',47=>'সাতচল্লিশ',48=>'আটচল্লিশ',49=>'ঊনপঞ্চাশ',
        51=>'একান্ন',52=>'বাহান্ন',53=>'তিপ্পান্ন',54=>'চুয়ান্ন',55=>'পঞ্চান্ন',56=>'ছাপ্পান্ন',57=>'সাতান্ন',58=>'আটান্ন',59=>'ঊনষাট',
        61=>'একষট্টি',62=>'বাষট্টি',63=>'তেষট্টি',64=>'চৌষট্টি',65=>'পঁয়ষট্টি',66=>'ছেষট্টি',67=>'সাতষট্টি',68=>'আটষট্টি',69=>'ঊনসত্তর',
        71=>'একাত্তর',72=>'বাহাত্তর',73=>'তিয়াত্তর',74=>'চুয়াত্তর',75=>'পঁচাত্তর',76=>'ছিয়াত্তর',77=>'সাতাত্তর',78=>'আটাত্তর',79=>'ঊনআশি',
        81=>'একাশি',82=>'বিরাশি',83=>'তিরাশি',84=>'চুরাশি',85=>'পঁচাশি',86=>'ছিয়াশি',87=>'সাতাশি',88=>'আটাশি',89=>'ঊননব্বই',
        91=>'একানব্বই',92=>'বিরানব্বই',93=>'তিরানব্বই',94=>'চুরানব্বই',95=>'পঁচানব্বই',96=>'ছিয়ানব্বই',97=>'সাতানব্বই',98=>'আটানব্বই',99=>'নিরানব্বই',
    ];
    $hundreds = ['','একশ','দুইশ','তিনশ','চারশ','পাঁচশ','ছয়শ','সাতশ','আটশ','নয়শ'];
    $bn1000 = function($x) use ($ones, $tens, $teens, $hundreds) {
        $out = '';
        if ($x >= 100) { $out .= $hundreds[intdiv($x, 100)]; $x %= 100; if ($x) $out .= ' '; }
        if ($x >= 21 && $x <= 99) $out .= $teens[$x];
        elseif ($x >= 20) { $out .= $tens[intdiv($x, 10)]; if ($x % 10) $out .= ' ' . $ones[$x % 10]; }
        elseif ($x > 0) $out .= $ones[$x];
        return $out;
    };
    $groups = [10000000 => 'কোটি', 100000 => 'লাখ', 1000 => 'হাজার'];
    $out = '';
    foreach ($groups as $g => $name) {
        if ($n >= $g) { $out .= $bn1000(intdiv($n, $g)) . ' ' . $name . ' '; $n %= $g; }
    }
    if ($n) $out .= $bn1000($n);
    return trim($out);
}
function render_merge($body, $vars) {
    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
        $k = $m[1];
        return array_key_exists($k, $vars) ? (string)$vars[$k] : $m[0];
    }, $body);
}
function TPL_PALETTES() {
    $org = [['org_name','Your org'],['org_phone','Org phone'],['org_email','Org email'],['org_address','Org address'],['today','Today']];
    return [
        'lease' => array_merge([
            ['lease_id','Lease ref'],['lease_start','Start date'],['lease_end','End date'],['rent','Rent (৳)'],['rent_words','Rent in words'],['advance','Advance (৳)'],['advance_words','Advance in words'],['reg_office','Reg office'],['reg_deed','Reg deed'],['reg_note','Registration note'],
            ['tenant_name','Tenant name'],['tenant_phone','Tenant phone'],['tenant_nid','Tenant NID'],['tenant_email','Tenant email'],
            ['property_name','Property name'],['property_address','Property address'],
            ['unit_name','Unit name'],['unit_id','Unit code'],['floor','Floor'],
        ], $org),
        'service' => array_merge([
            ['partner_name','Partner name'],['partner_trade','Trade'],['partner_rating','Rating'],['partner_jobs','Jobs done'],['partner_status','Status'],['partner_email','Partner email'],
        ], $org),
        'receipt' => array_merge([
            ['receipt_id','Receipt no'],['date','Date'],['amount','Amount (৳)'],['amount_words_en','Amount words (EN)'],['amount_words_bn','Amount words (বাংলা)'],['method','Method'],['ref','Reference'],
            ['invoice_id','Invoice'],['month','Month'],['lease_id','Lease'],
            ['tenant_name','Tenant name'],['tenant_phone','Tenant phone'],
            ['property_name','Property name'],['property_address','Property address'],
            ['unit_name','Unit name'],['unit_id','Unit code'],['floor','Floor'],
        ], $org),
    ];
}
function seed_tpl_body($id) {
    $bodies = [
'LSE-STD' => <<<'HTML'
TENANCY AGREEMENT
=================
THIS TENANCY AGREEMENT made this {{today}} BETWEEN {{org_name}} (hereinafter called the "Lessor"), whose address is {{org_address}}, phone {{org_phone}}, email {{org_email}} — AND — {{tenant_name}} (hereinafter called the "Lessee"), phone {{tenant_phone}}, NID {{tenant_nid}}.

WHEREAS the Lessor is the owner of the premises described below and the Lessee has agreed to take the same on lease upon the terms and conditions hereinafter contained:

1. PREMISES: The Lessor lets and the Lessee takes on lease the premises situated at {{property_name}}, {{property_address}}, comprising {{unit_name}} (Unit Code: {{unit_id}}), Floor: {{floor}}.

2. TERM: The tenancy shall commence on {{lease_start}} and expire on {{lease_end}} (Lease Reference: {{lease_id}}). The Lessee shall vacate the premises on expiry unless the lease is renewed by mutual agreement.

3. RENT: The monthly rent is BDT {{rent}} (in words: {{rent_words}}) payable in advance on or before the 7th day of each month. Rent for a part of a month shall be calculated pro-rata.

4. ADVANCE / SECURITY DEPOSIT: The Lessee has paid BDT {{advance}} (in words: {{advance_words}}) as advance/security deposit, refundable upon vacating the premises subject to lawful deductions for arrears or damage.

5. USE: The premises shall be used only for residential/commercial purposes consistent with the laws and by-laws of Bangladesh. Sub-letting without the Lessor's written consent is prohibited.

6. UTILITIES & MAINTENANCE: The Lessee shall pay electricity, gas, water and other utility charges. The Lessor shall bear structural repairs and replacement of major installations; day-to-day repairs are the Lessee's responsibility as per the Premises Rent Control Act 1991.

7. REGISTRATION: {{reg_note}} (Registration Office: {{reg_office}} / Deed: {{reg_deed}})

8. TERMINATION: Either party may terminate this tenancy by giving notice as required by law. The Lessee shall hand over vacant possession on or before the expiry of the term.

9. GOVERNING LAW: This agreement is governed by the laws of the People's Republic of Bangladesh.

IN WITNESS WHEREOF the parties have hereunto set their hands and seals on the date first above written.

Lessor: _____________________  ({{org_name}})
Lessee: _____________________  ({{tenant_name}})
Witness 1: ___________________  Witness 2: ___________________
HTML,
'SRV-STD' => <<<'HTML'
SERVICE CONTRACT
================
THIS SERVICE CONTRACT made this {{today}} BETWEEN {{org_name}} ("Client"), whose address is {{org_address}}, phone {{org_phone}}, email {{org_email}} — AND — {{partner_name}} ("Service Provider"), trade: {{partner_trade}}, email: {{partner_email}}.

WHEREAS the Client manages rental properties and wishes to engage the Service Provider for maintenance and repair services upon the following terms:

1. SCOPE OF WORK: The Service Provider shall execute maintenance, repair and renovation work for the Client's properties as directed through work orders raised on the KRTaker platform, including electrical, plumbing, civil, and other trade work as agreed per job.

2. RATE & PAYMENT: Work shall be billed per approved quotation or agreed rate card. Invoices must be submitted within 7 days of job completion and are settled within 7 days of quality-control approval. TDS under IT Act 2023 §109 applies to all professional payments.

3. QUALITY & SLA: All work shall meet KRTaker quality-control standards. Any defective work shall be rectified at the Provider's cost. The Provider shall respond to emergency call-outs within 24 hours and normal requests within 48 hours.

4. MATERIALS: Materials may be supplied by the Client or procured by the Provider with prior approval; material costs are reimbursed at cost with supporting bills.

5. TERM & TERMINATION: This contract shall remain in force until terminated by either party with 30 days' written notice. Outstanding works shall be completed or billed fairly upon termination.

6. COMPLIANCE: The Provider warrants compliance with all applicable labour, safety, fire and tax laws of Bangladesh and shall maintain its own insurance cover for its workers.

IN WITNESS WHEREOF the parties have executed this contract on the date first above written.

Client: _____________________  ({{org_name}})
Service Provider: _____________  ({{partner_name}})
Witness 1: ___________________  Witness 2: ___________________
HTML,
'RCP-RENT' => <<<'HTML'
MONEY RECEIPT — RENT
====================
Receipt No: {{receipt_id}}                    Date: {{date}}

Received with thanks from {{tenant_name}} (Phone: {{tenant_phone}}) the sum of BDT {{amount}} (in words: Taka {{amount_words_en}} / {{amount_words_bn}}) on account of RENT for the month of {{month}} in respect of the premises {{property_name}} — {{unit_name}} ({{unit_id}}), {{property_address}}, against Invoice {{invoice_id}} under Lease {{lease_id}}.

Payment method: {{method}}    Reference/TrxID: {{ref}}

For {{org_name}}
Authorized Signature: _______________________

This is a system-generated receipt from KRTaker. Verify at krtaker.com · {{org_phone}} · {{org_email}}
HTML,
'RCP-ADV' => <<<'HTML'
MONEY RECEIPT — ADVANCE / SECURITY DEPOSIT
==========================================
Receipt No: {{receipt_id}}                    Date: {{date}}

Received with thanks from {{tenant_name}} (Phone: {{tenant_phone}}) the sum of BDT {{amount}} (in words: Taka {{amount_words_en}} / {{amount_words_bn}}) on account of ADVANCE / SECURITY DEPOSIT under Lease {{lease_id}} in respect of the premises {{property_name}} — {{unit_name}} ({{unit_id}}), {{property_address}}.

Payment method: {{method}}    Reference/TrxID: {{ref}}

For {{org_name}}
Authorized Signature: _______________________

This is a system-generated receipt from KRTaker. Verify at krtaker.com · {{org_phone}} · {{org_email}}
HTML,
'RCP-SVC' => <<<'HTML'
MONEY RECEIPT — SERVICE CHARGES
===============================
Receipt No: {{receipt_id}}                    Date: {{date}}

Received with thanks from {{tenant_name}} (Phone: {{tenant_phone}}) the sum of BDT {{amount}} (in words: Taka {{amount_words_en}} / {{amount_words_bn}}) on account of SERVICE CHARGES / UTILITY RECOVERY for {{month}} in respect of the premises {{property_name}} — {{unit_name}} ({{unit_id}}), {{property_address}}, against Invoice {{invoice_id}}.

Payment method: {{method}}    Reference/TrxID: {{ref}}

For {{org_name}}
Authorized Signature: _______________________

This is a system-generated receipt from KRTaker. Verify at krtaker.com · {{org_phone}} · {{org_email}}
HTML,
'RCP-GEN' => <<<'HTML'
MONEY RECEIPT — PAYMENT RECEIVED
================================
Receipt No: {{receipt_id}}                    Date: {{date}}

Received with thanks from {{tenant_name}} (Phone: {{tenant_phone}}) the sum of BDT {{amount}} (in words: Taka {{amount_words_en}} / {{amount_words_bn}}) on account of payment against Invoice {{invoice_id}} ({{month}}) in respect of {{property_name}} — {{unit_name}} ({{unit_id}}), {{property_address}}.

Payment method: {{method}}    Reference/TrxID: {{ref}}

For {{org_name}}
Authorized Signature: _______________________

This is a system-generated receipt from KRTaker. Verify at krtaker.com · {{org_phone}} · {{org_email}}
HTML,
    ];
    return $bodies[$id] ?? '';
}
