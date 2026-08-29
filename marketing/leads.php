<?php
/* Mall Manager — Demo Leads Dashboard (mall.krtaker.com/leads.php)
   Password-gated; reads leads from the appvaley API (key-gated). */
session_start();
define('LEADS_PASS', 'mall2026');
define('LEAD_KEY', 'df7432135af83ddc');
define('API_URL', 'https://appvaley.com/mall/api/mall-leads');
define('API_DEL', 'https://appvaley.com/mall/api/mall-lead-del');

function api_post($url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    if (hash_equals(LEADS_PASS, (string)($_POST['pass'] ?? ''))) {
        $_SESSION['mall_leads_ok'] = 1;
    } else {
        $err = 'Wrong password.';
    }
}
if (isset($_GET['logout'])) { unset($_SESSION['mall_leads_ok']); header('Location: leads.php'); exit; }

$authed = !empty($_SESSION['mall_leads_ok']);

/* JSON modes (authed) */
if ($authed && ($_GET['mode'] ?? '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $d = api_post(API_URL, ['key' => LEAD_KEY]);
    if (!$d) { echo json_encode(['ok' => false, 'error' => 'Gateway unreachable.']); exit; }
    echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
}
if ($authed && ($_GET['mode'] ?? '') === 'del') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $d = api_post(API_DEL, ['key' => LEAD_KEY, 'id' => $id]);
    echo json_encode($d ?: ['ok' => false, 'error' => 'Gateway unreachable.'], JSON_UNESCAPED_UNICODE); exit;
}
if ($authed && ($_GET['mode'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mall_leads.csv');
    $d = api_post(API_URL, ['key' => LEAD_KEY]);
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel Bangla
    fputcsv($out, ['ID', 'Name', 'Mobile', 'Email', 'Source', 'Creds Sent', 'Created']);
    foreach (($d['leads'] ?? []) as $r) {
        fputcsv($out, [$r['id'], $r['name'], $r['mobile'], $r['email'], $r['source'], $r['creds_sent'], $r['created_at']]);
    }
    fclose($out); exit;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mall Manager — Leads Dashboard</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Tahoma,'Noto Sans Bengali',sans-serif;background:#f1f5f9;color:#111827;line-height:1.6}
.top{background:#7f1d1d;color:#fff;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.top h1{font-size:18px}
.top a{color:#fde8e8;font-size:13px;text-decoration:none;margin-left:14px}
.wrap{max-width:1080px;margin:22px auto;padding:0 16px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px}
.stats{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 20px;flex:1;min-width:140px;text-align:center}
.stat b{display:block;font-size:24px;color:#7f1d1d}
.stat span{font-size:12px;color:#64748b}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{background:#f8fafc;text-align:left;padding:10px 12px;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0}
td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700}
.ok{background:#dcfce7;color:#166534}
.no{background:#fef3c7;color:#92400e}
.del{background:none;border:none;color:#dc2626;cursor:pointer;font-size:13px}
.wa{color:#059669;text-decoration:none;font-weight:700;font-size:13px}
.actions{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.btn{display:inline-block;padding:10px 18px;border-radius:10px;border:none;font-weight:800;font-size:13px;cursor:pointer;text-decoration:none;font-family:inherit}
.btn-p{background:#7f1d1d;color:#fff}
.btn-o{background:#fff;color:#7f1d1d;border:1.5px solid #e2e8f0}
.login{max-width:360px;margin:80px auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:30px;text-align:center}
.login input{width:100%;padding:12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;margin:14px 0 6px}
.login .err{color:#dc2626;font-size:13px;margin-top:8px}
.empty{text-align:center;padding:40px;color:#64748b}
</style>
</head>
<body>
<?php if (!$authed): ?>
<div class="login">
  <div style="font-size:30px">🔐</div>
  <h2 style="margin-top:6px">Leads Dashboard</h2>
  <form method="POST">
    <input type="hidden" name="do" value="login">
    <input type="password" name="pass" placeholder="Password" required autofocus>
    <button class="btn btn-p" style="width:100%;margin-top:10px" type="submit">লগ ইন</button>
    <?php if (!empty($err)): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  </form>
</div>
<?php else: ?>
<div class="top">
  <h1>🏬 Mall Manager — Demo Leads</h1>
  <div><a href="leads.php?mode=csv">⬇ CSV Export</a><a href="leads.php?logout=1">লগ আউট</a></div>
</div>
<div class="wrap">
  <div class="stats">
    <div class="stat"><b id="stTotal">—</b><span>মোট লিড</span></div>
    <div class="stat"><b id="stToday">—</b><span>আজ</span></div>
    <div class="stat"><b id="stSms">—</b><span>SMS পাঠানো হয়েছে</span></div>
  </div>
  <div class="card">
    <div class="actions">
      <button class="btn btn-p" onclick="loadLeads()">🔄 রিফ্রেশ</button>
      <span style="font-size:12.5px;color:#64748b;align-self:center">লিড দেখতে ও WhatsApp-এ ফলো-আপ করতে এই পেজটি ব্যবহার করুন।</span>
    </div>
    <div class="tbl" id="tbl"><div class="empty">লোড হচ্ছে…</div></div>
  </div>
</div>
<script>
async function loadLeads(){
  var tb=document.getElementById('tbl');
  tb.innerHTML='<div class="empty">লোড হচ্ছে…</div>';
  try{
    var r=await fetch('leads.php?mode=list');var j=await r.json();
    if(!j.ok){tb.innerHTML='<div class="empty">'+ (j.error||'Error') +'</div>';return;}
    var rows=j.leads||[];
    document.getElementById('stTotal').textContent=rows.length;
    var today=new Date().toISOString().slice(0,10);
    document.getElementById('stToday').textContent=rows.filter(x=>(x.created_at||'').slice(0,10)===today).length;
    document.getElementById('stSms').textContent=rows.filter(x=>+x.creds_sent===1).length;
    if(!rows.length){tb.innerHTML='<div class="empty">কোনো লিড নেই — পেজ থেকে ডেমো রিকোয়েস্ট এলে এখানে আসবে।</div>';return;}
    var h='<table><thead><tr><th>ID</th><th>নাম</th><th>মোবাইল</th><th>ইমেইল</th><th>সোর্স</th><th>SMS</th><th>তারিখ</th><th>ফলো-আপ</th><th></th></tr></thead><tbody>';
    rows.forEach(function(x){
      var wa='https://wa.me/88'+x.mobile.replace(/[^0-9]/g,'')+'?text='+encodeURIComponent('আসসালামু আলাইকুম '+x.name+' ভাই, Mall Manager ডেমো নিয়ে কথা বলা যাবে?');
      h+='<tr><td>'+x.id+'</td><td><b>'+x.name+'</b></td><td>'+x.mobile+'</td><td>'+(x.email||'—')+'</td><td>'+x.source+'</td>'+
         '<td>'+(+x.creds_sent===1?'<span class="badge ok">হ্যাঁ</span>':'<span class="badge no">না</span>')+'</td><td style="white-space:nowrap">'+x.created_at+'</td>'+
         '<td><a class="wa" href="'+wa+'" target="_blank">💬 WhatsApp</a></td>'+
         '<td><button class="del" onclick="delLead('+x.id+',this)">🗑</button></td></tr>';
    });
    tb.innerHTML=h+'</tbody></table>';
  }catch(e){tb.innerHTML='<div class="empty">নেটওয়ার্ক সমস্যা</div>';}
}
async function delLead(id,btn){
  if(!confirm('লিড #'+id+' ডিলিট করবেন?'))return;
  var r=await fetch('leads.php?mode=del&id='+id);var j=await r.json();
  if(j.ok){btn.closest('tr').remove();loadLeads();}
}
loadLeads();
</script>
<?php endif; ?>
</body>
</html>
