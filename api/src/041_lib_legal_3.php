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
            ['lease_id','Lease ref'],['lease_start','Start date'],['lease_end','End date'],['rent','Rent (৳)'],['rent_words','Rent in words'],['rent_words_bn','Rent in words (বাংলা)'],['advance','Advance (৳)'],['advance_words','Advance in words'],['advance_words_bn','Advance in words (বাংলা)'],['reg_office','Reg office'],['reg_deed','Reg deed'],['reg_note','Registration note'],
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
'RCP-BN' => <<<'HTML'
টাকা প্রাপ্তি রসিদ
==================
রসিদ নং: {{receipt_id}}                        তারিখ: {{date}}

{{tenant_name}} (মোবাইল: {{tenant_phone}}) এর নিকট থেকে BDT {{amount}} (কথায়: {{amount_words_bn}}) টাকা সদয় প্রাপ্ত হইলাম — {{property_name}} — {{unit_name}} ({{unit_id}}), {{property_address}} ঠিকানার ভাড়া/বকেয়া বাবদ, Invoice {{invoice_id}} ({{month}}) এর বিপরীতে।

পেমেন্ট মাধ্যম: {{method}}    রেফারেন্স/ট্রাঅ্যাকশন: {{ref}}

{{org_name}} এর পক্ষে
অনুমোদিত স্বাক্ষর: _______________________

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি রসিদ। krtaker.com · {{org_phone}} · {{org_email}}
HTML,
'NTC-ARR' => <<<'HTML'
NOTICE OF ARREARS — {{property_name}}
=====================================
Date: {{today}}

To,
{{tenant_name}}
{{unit_name}} ({{unit_id}}), Floor {{floor}}
{{property_address}}
Phone: {{tenant_phone}}

Dear Sir/Madam,

Re: Overdue rent — Lease {{lease_id}} ({{lease_start}} to {{lease_end}})

This is to notify you that the rent for the above premises is overdue. As per the terms of your tenancy agreement, monthly rent of BDT {{rent}} (in words: {{rent_words}}) is payable in advance by the 7th day of each month.

Kindly settle the outstanding amount within 7 (seven) days of receipt of this notice. If payment is not received within the stipulated period, we shall be constrained to take appropriate action in accordance with the Premises Rent Control Act, 1991 and the terms of the agreement, including termination of the tenancy.

For payment via bKash/Nagad or the tenant portal, or for any clarification, please contact {{org_phone}} or {{org_email}}.

Thanking you,
For {{org_name}}
Authorized Signatory

This is a system-generated notice from KRTaker. Verify at krtaker.com
HTML,
'NTC-ARR-BN' => <<<'HTML'
বকেয়া ভাড়া সংক্রান্ত নোটিশ — {{property_name}}
===============================================
তারিখ: {{today}}

প্রাপক,
{{tenant_name}}
{{unit_name}} ({{unit_id}}), তলা: {{floor}}
{{property_address}}
মোবাইল: {{tenant_phone}}

জনাব/জনাবা,

বিষয়: বকেয়া ভাড়া — চুক্তি {{lease_id}} ({{lease_start}} হতে {{lease_end}})

আপনার ভাড়া বকেয়া থাকায় আপনাকে জানানো যাচ্ছে যে, আপনার চুক্তি অনুযায়ী প্রতি মাসের ৭ তারিখের মধ্যে BDT {{rent}} (কথায়: {{rent_words_bn}}) টাকা অগ্রিম পরিশোধযোগ্য।

অনুরোধ করা যাচ্ছে, এই নোটিশ পাওয়ার ৭ (সাত) দিনের মধ্যে বকেয়া পরিশোধ করুন। নির্ধারিত সময়ের মধ্যে পরিশোধ না করলে ১৯৯১ সনের ভাড়া নিয়ন্ত্রণ আইন ও চুক্তির শর্তানুযায়ী প্রয়োজনীয় ব্যবস্থা গ্রহণ করা হবে।

bKash/Nagad অথবা টেন্যান্ট পোর্টালের মাধ্যমে পেমেন্ট করতে বা যেকোনো প্রয়োজনে যোগাযোগ করুন: {{org_phone}} অথবা {{org_email}}।

ধন্যবাদান্তে,
{{org_name}} এর পক্ষে
অনুমোদিত স্বাক্ষরকারী

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি নোটিশ। krtaker.com
HTML,
'NTC-MOVE' => <<<'HTML'
NOTICE OF MOVE-OUT / VACATION — {{property_name}}
=================================================
Date: {{today}}

To,
{{tenant_name}}
{{unit_name}} ({{unit_id}}), Floor {{floor}}
{{property_address}}
Phone: {{tenant_phone}}

Dear Sir/Madam,

Re: Vacating the premises — Lease {{lease_id}} ({{lease_start}} to {{lease_end}})

This is to notify you that your tenancy in respect of the above premises shall expire on {{lease_end}} and will not be renewed. You are therefore requested to hand over vacant and peaceful possession of the premises on or before {{lease_end}}.

You are also requested to clear all outstanding dues (rent, service charges, utilities) and hand over all keys, cards and equipment belonging to the premises on the date of vacation. The advance/security deposit, after lawful deductions (if any), will be refunded within 30 days of vacating.

If you have any dispute regarding the settlement, please contact {{org_phone}} or {{org_email}} at your earliest convenience.

Thanking you,
For {{org_name}}
Authorized Signatory

This is a system-generated notice from KRTaker. Verify at krtaker.com
HTML,
'NTC-MOVE-BN' => <<<'HTML'
খালি করার নোটিশ — {{property_name}}
===================================
তারিখ: {{today}}

প্রাপক,
{{tenant_name}}
{{unit_name}} ({{unit_id}}), তলা: {{floor}}
{{property_address}}
মোবাইল: {{tenant_phone}}

জনাব/জনাবা,

বিষয়: বাসস্থান ত্যাগ — চুক্তি {{lease_id}} ({{lease_start}} হতে {{lease_end}})

আপনাকে জানানো যাচ্ছে যে, উপরোক্ত বাসস্থানের জন্য আপনার ভাড়া চুক্তি {{lease_end}} তারিখে শেষ হইবে এবং নবায়ন করা হবে না। তাই অনুরোধ করা যাচ্ছে, {{lease_end}} তারিখের মধ্যে অথবা তার পূর্বে বাসস্থানটি খালি করে হস্তান্তর করুন।

খালি করার সময় সকল বকেয়া (ভাড়া, সার্ভিস চার্জ, ইউটিলিটি) পরিশোধ করুন এবং সকল চাবি, কার্ড ও জিনিসপত্র বুঝিয়ে দিন। জমাকৃত অগ্রিম/জামানত, যথাযথ কর্তন (যদি থাকে) সাপেক্ষে, খালি করার ৩০ দিনের মধ্যে ফেরত দেওয়া হবে।

যেকোনো প্রকার জিজ্ঞাসার জন্য যোগাযোগ করুন: {{org_phone}} অথবা {{org_email}}।

ধন্যবাদান্তে,
{{org_name}} এর পক্ষে
অনুমোদিত স্বাক্ষরকারী

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি নোটিশ। krtaker.com
HTML,
'NTC-UTIL' => <<<'HTML'
NOTICE OF UTILITY / SERVICE CHARGES — {{property_name}}
=======================================================
Date: {{today}}

To,
{{tenant_name}}
{{unit_name}} ({{unit_id}}), Floor {{floor}}
{{property_address}}
Phone: {{tenant_phone}}

Dear Sir/Madam,

Re: Utility / service charges — {{month}}

As per the terms of your tenancy (Lease {{lease_id}}), please find below the utility/service charges billed for {{month}} in respect of {{property_name}} — {{unit_name}} ({{unit_id}}):

The charges comprise electricity, gas, water, common-area maintenance and other recoverable expenses. Kindly pay the amount within 7 days of receipt of this notice through bKash/Nagad, the tenant portal, or at the management office.

For any discrepancy in the billing, please contact {{org_phone}} or {{org_email}} within 3 days.

Thanking you,
For {{org_name}}
Authorized Signatory

This is a system-generated notice from KRTaker. Verify at krtaker.com
HTML,
'NTC-UTIL-BN' => <<<'HTML'
ইউটিলিটি/সার্ভিস চার্জ নোটিশ — {{property_name}}
=================================================
তারিখ: {{today}}

প্রাপক,
{{tenant_name}}
{{unit_name}} ({{unit_id}}), তলা: {{floor}}
{{property_address}}
মোবাইল: {{tenant_phone}}

জনাব/জনাবা,

বিষয়: ইউটিলিটি/সার্ভিস চার্জ — {{month}}

আপনার চুক্তি ({{lease_id}}) অনুযায়ী, {{month}} মাসের জন্য {{property_name}} — {{unit_name}} ({{unit_id}}) এর ইউটিলিটি/সার্ভিস চার্জ নিম্নরূপ ধার্য করা হইল:

এই চার্জে বিদ্যুৎ, গ্যাস, পানি, সাধারণ এলাকার রক্ষণাবেক্ষণ ও অন্যান্য পুনরুদ্ধারযোগ্য খরচ অন্তর্ভুক্ত। নোটিশ পাওয়ার ৭ দিনের মধ্যে bKash/Nagad, টেন্যান্ট পোর্টাল অথবা ব্যবস্থাপনা অফিসে পরিশোধের অনুরোধ করা যাচ্ছে।

বিল সংক্রান্ত যেকোনো ভুলের ক্ষেত্রে ৩ দিনের মধ্যে যোগাযোগ করুন: {{org_phone}} অথবা {{org_email}}।

ধন্যবাদান্তে,
{{org_name}} এর পক্ষে
অনুমোদিত স্বাক্ষরকারী

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি নোটিশ। krtaker.com
HTML,
'WRK-ORD' => <<<'HTML'
WORK ORDER — {{org_name}}
=========================
Work Order No: {{today}} / {{partner_name}}
Date: {{today}}

To,
{{partner_name}}
Trade: {{partner_trade}}
Email: {{partner_email}}

Dear Sir/Madam,

Re: Work order for maintenance / service work

You are hereby engaged by {{org_name}} ({{org_address}}, Phone: {{org_phone}}) to carry out the following work at the premises:

Scope of work: _________________________________________________________________
Materials / supplies: __________________________________________________________
Completion deadline: __________________________________________________________

Terms & conditions:
1. The work shall be carried out with due care and in accordance with professional standards.
2. The rate/price shall be as mutually agreed; payment shall be released after satisfactory completion and verification.
3. Any damage caused during the work shall be made good by you at your own cost.
4. This work order does not constitute a contract of employment.

Kindly confirm acceptance of this work order by return. For clarification, contact {{org_phone}} or {{org_email}}.

Thanking you,
For {{org_name}}
Authorized Signatory

This is a system-generated work order from KRTaker. Verify at krtaker.com
HTML,
'WRK-ORD-BN' => <<<'HTML'
ওয়ার্ক অর্ডার — {{org_name}}
==============================
ওয়ার্ক অর্ডার নং: {{today}} / {{partner_name}}
তারিখ: {{today}}

প্রাপক,
{{partner_name}}
পেশা: {{partner_trade}}
ইমেইল: {{partner_email}}

জনাব/জনাবা,

বিষয়: মেরামত/সার্ভিস কাজের ওয়ার্ক অর্ডার

{{org_name}} ({{org_address}}, ফোন: {{org_phone}}) এর পক্ষ থেকে আপনাকে নিম্নোক্ত কাজ সম্পাদনের জন্য নিযুক্ত করা হইল:

কাজের বিবরণ: _________________________________________________________________
সামগ্রী/সরবরাহ: ______________________________________________________________
সমাপ্তির সময়সীমা: __________________________________________________________

শর্তাবলী:
১. কাজটি যথাযথ যত্নসহ পেশাদার মান অনুযায়ী সম্পন্ন করতে হবে।
২. মূল্য পারস্পরিক সম্মতিমতো; সন্তোষজনকভাবে কাজ সম্পন্ন ও যাচাইয়ের পর পেমেন্ট প্রদান করা হবে।
৩. কাজ চলাকালীন যেকোনো ক্ষতির জন্য আপনিই দায়ী থাকবেন।
৪. এই ওয়ার্ক অর্ডারটি চাকরির চুক্তি নয়।

ওয়ার্ক অর্ডারটি গ্রহণের বিষয়টি নিশ্চিত করার জন্য অনুরোধ করা যাচ্ছে। প্রয়োজনে যোগাযোগ: {{org_phone}} অথবা {{org_email}}।

ধন্যবাদান্তে,
{{org_name}} এর পক্ষে
অনুমোদিত স্বাক্ষরকারী

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি ওয়ার্ক অর্ডার। krtaker.com
HTML,
'NOC-STD' => <<<'HTML'
NO OBJECTION CERTIFICATE (NOC)
==============================
Date: {{today}}
NOC No: {{lease_id}} / {{today}}

This is to certify that {{tenant_name}} (Phone: {{tenant_phone}}, NID: {{tenant_nid}}) is a bonafide tenant of {{property_name}}, {{property_address}}, occupying {{unit_name}} ({{unit_id}}), Floor {{floor}}, under Lease {{lease_id}} ({{lease_start}} to {{lease_end}}), paying monthly rent of BDT {{rent}} (in words: {{rent_words}}).

As of {{today}}, there are no arrears or dues outstanding against the said tenancy, and {{org_name}} has no objection to the following (as applicable):

1. Issuance of trade license / business permit in the name of {{tenant_name}} at the above premises.
2. Connection of electricity / gas / water / internet in the name of {{tenant_name}}.
3. Visa / bank / official purposes requiring proof of residence.

This certificate is issued on the request of the tenant and is valid for 90 days from the date of issue. This does not transfer or affect any right, title or interest in the premises.

For {{org_name}}
Authorized Signatory
{{org_address}} · {{org_phone}} · {{org_email}}

This is a system-generated certificate from KRTaker. Verify at krtaker.com
HTML,
'NOC-BN' => <<<'HTML'
আপত্তি-মুক্ত সনদ (এনওসি)
========================
তারিখ: {{today}}
এনওসি নং: {{lease_id}} / {{today}}

এতদ্বারা প্রত্যয়ন করা যাচ্ছে যে, {{tenant_name}} (মোবাইল: {{tenant_phone}}, জাতীয় পরিচয়পত্র: {{tenant_nid}}) {{property_name}}, {{property_address}} এর {{unit_name}} ({{unit_id}}), তলা {{floor}} এর বৈধ ভাড়াটিয়া — চুক্তি নং {{lease_id}} ({{lease_start}} হতে {{lease_end}}), মাসিক ভাড়া BDT {{rent}} (কথায়: {{rent_words_bn}})।

{{today}} তারিখ পর্যন্ত উক্ত ভাড়ার বিপরীতে কোনো বকেয়া নেই এবং নিম্নলিখিত বিষয়ে {{org_name}} এর কোনো আপত্তি নাই (প্রযোজ্য ক্ষেত্রে):

১. {{tenant_name}} এর নামে উপরোক্ত ঠিকানায় ট্রেড লাইসেন্স/ব্যবসার অনুমতি প্রদান।
২. {{tenant_name}} এর নামে বিদ্যুৎ/গ্যাস/পানি/ইন্টারনেট সংযোগ।
৩. ভিসা/ব্যাংক/সরকারি প্রয়োজনে বসবাসের প্রমাণ।

এই সনদটি ভাড়াটিয়ার অনুরোধে প্রদান করা হইল এবং ইস্যুর তারিখ হতে ৯০ দিন পর্যন্ত বলবৎ থাকিবে। এই সনদ বাসস্থানের উপর কোনো স্বত্ব/স্বার্থ হস্তান্তর করে না।

{{org_name}} এর পক্ষে
অনুমোদিত স্বাক্ষরকারী
{{org_address}} · {{org_phone}} · {{org_email}}

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি সনদ। krtaker.com
HTML,
'LSE-BN' => <<<'HTML'
ভাড়া চুক্তি (Tenancy Agreement)
================================
আজ {{today}} তারিখে {{org_name}} (ঠিকানা: {{org_address}}, ফোন: {{org_phone}}, ইমেইল: {{org_email}}) — যাহাকে এখানে "ভাড়াদাতা" বলা হইবে — এবং {{tenant_name}} (মোবাইল: {{tenant_phone}}, জাতীয় পরিচয়পত্র: {{tenant_nid}}) — যাহাকে এখানে "ভাড়াটিয়া" বলা হইবে — এর মধ্যে নিম্নলিখিত শর্তে ভাড়া চুক্তি সম্পাদিত হইল:

১. প্রাঙ্গণ (PREMISES): ভাড়াদাতা নিম্নবর্ণিত প্রাঙ্গণ ভাড়াটিয়ার নিকট ভাড়া প্রদান করিলেন — {{property_name}}, {{property_address}} এর {{unit_name}} (ইউনিট কোড: {{unit_id}}), তলা: {{floor}}।

২. মেয়াদ (TERM): এই ভাড়া {{lease_start}} তারিখে শুরু হইয়া {{lease_end}} তারিখে শেষ হইবে (চুক্তি নং: {{lease_id}})। উভয় পক্ষের সম্মতি ব্যতীত মেয়াদ শেষে ভাড়াটিয়াকে প্রাঙ্গণ খালি করিতে হইবে।

৩. ভাড়া (RENT): মাসিক ভাড়া BDT {{rent}} (কথায়: {{rent_words_bn}}) টাকা, প্রতি মাসের ৭ তারিখের মধ্যে অগ্রিম পরিশোধযোগ্য। আংশিক মাসের ভাড়া আনুপাতিক হারে গণনা করা হইবে।

৪. অগ্রিম/জামানত (ADVANCE / SECURITY DEPOSIT): ভাড়াটিয়া BDT {{advance}} (কথায়: {{advance_words_bn}}) টাকা অগ্রিম/জামানত হিসাবে প্রদান করিয়াছেন, যাহা প্রাঙ্গণ খালি করার সময় বকেয়া বা ক্ষতিপূরণ বাদ দিয়া ফেরতযোগ্য।

৫. ব্যবহার (USE): প্রাঙ্গণ শুধুমাত্র বাংলাদেশের আইন ও বিধি অনুযায়ী আবাসিক/বাণিজ্যিক উদ্দেশ্যে ব্যবহার করা যাইবে। ভাড়াদাতার লিখিত সম্মতি ব্যতীত উপ-ভাড়া প্রদান নিষিদ্ধ।

৬. ইউটিলিটি ও রক্ষণাবেক্ষণ (UTILITIES & MAINTENANCE): বিদ্যুৎ, গ্যাস, পানি ও অন্যান্য ইউটিলিটি বিল ভাড়াটিয়া বহন করিবেন। কাঠামোগত মেরামত ভাড়াদাতা করিবেন; দৈনন্দিন মেরামত ১৯৯১ সনের ভাড়া নিয়ন্ত্রণ আইন অনুযায়ী ভাড়াটিয়ার দায়িত্ব।

৭. নিবন্ধন (REGISTRATION): {{reg_note}} (নিবন্ধন অফিস: {{reg_office}} / দলিল: {{reg_deed}})

৮. সমাপ্তি (TERMINATION): আইনানুযায়ী নোটিশ প্রদানপূর্বক যেকোনো পক্ষ এই ভাড়া সমাপ্ত করিতে পারিবে। মেয়াদ শেষে ভাড়াটিয়া প্রাঙ্গণ খালি করিয়া দিবেন।

৯. governing আইন (GOVERNING LAW): এই চুক্তি গণপ্রজাতন্ত্রী বাংলাদেশ সরকারের আইন দ্বারা পরিচালিত হইবে।

উপরোক্ত শর্তাবলীতে উভয় পক্ষ সাক্ষ্যদান করিলেন।

ভাড়াদাতার স্বাক্ষর: ______________________   ভাড়াটিয়ার স্বাক্ষর: ______________________
সাক্ষী ১: ______________________   সাক্ষী ২: ______________________

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি চুক্তি। krtaker.com · {{org_phone}} · {{org_email}}
HTML,
'SRV-BN' => <<<'HTML'
সার্ভিস চুক্তি (Service Contract)
==================================
আজ {{today}} তারিখে {{org_name}} (ঠিকানা: {{org_address}}, ফোন: {{org_phone}}, ইমেইল: {{org_email}}) — যাহাকে "সেবাগ্রহীতা" বলা হইবে — এবং {{partner_name}} (পেশা: {{partner_trade}}, ইমেইল: {{partner_email}}) — যাহাকে "সেবা প্রদানকারী" বলা হইবে — এর মধ্যে নিম্নলিখিত শর্তে সার্ভিস চুক্তি সম্পাদিত হইল:

১. সেবার বিবরণ: সেবা প্রদানকারী {{org_name}} এর জন্য নিম্নলিখিত সেবা প্রদান করিবেন: ______________________________________________________________

২. মান ও সময়: সেবা পেশাদার মান অনুযায়ী এবং নির্ধারিত সময়সীমার মধ্যে সম্পন্ন করিতে হইবে।

৩. পারিশ্রমিক: পারিশ্রমিক পারস্পরিক সম্মতিমতো; কাজ সন্তোষজনকভাবে সম্পন্ন ও যাচাইয়ের পর পরিশোধযোগ্য।

৪. দায়িত্ব: কাজ চলাকালীন যেকোনো ক্ষতির জন্য সেবা প্রদানকারী দায়ী থাকিবেন।

৫. মেয়াদ: এই চুক্তি উভয় পক্ষের সম্মতিতে যেকোনো সময় সমাপ্ত করা যাইবে।

উপরোক্ত শর্তাবলীতে উভয় পক্ষ সাক্ষ্যদান করিলেন।

সেবাগ্রহীতার স্বাক্ষর: ______________________   সেবা প্রদানকারীর স্বাক্ষর: ______________________
সাক্ষী ১: ______________________   সাক্ষী ২: ______________________

এটি KRTaker কর্তৃক স্বয়ংক্রিয়ভাবে তৈরি চুক্তি। krtaker.com · {{org_phone}} · {{org_email}}
HTML,
    ];
    return $bodies[$id] ?? '';
}
