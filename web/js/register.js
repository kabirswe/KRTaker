/* KRTaker landing — register wizard (role → account → OTP → done, plan chosen later in dashboard) */
(function () {
  const API = '../api/';
  let state = { role: 'owner', email: '', timer: null, resendIn: 0 };

  const $ = (id) => document.getElementById(id);
  const steps = ['pane1', 'pane2', 'pane3', 'pane4'];
  const dict = () => (window.KR_I18N ? (KR_I18N[krLang()] || KR_I18N.en) : null);
  /* GA4 conversion tracking (safe — no-op if the tag is blocked/not loaded) */
  const ga = (event, params) => { try { if (window.gtag) window.gtag('event', event, params || {}); } catch (e) {} };

  /* ── Phone rules per country code: {cc: {name, len, prefix}} — len = exact national
     digits, prefix = required leading digits (regex). Countries not listed fall back
     to generic 5–12 digit national numbers. ── */
  const CC_RULES = {
    '+880': { name: 'Bangladesh', len: 10, prefix: /^1/ },
    '+91':  { name: 'India',      len: 10, prefix: /^[6-9]/ },
    '+92':  { name: 'Pakistan',   len: 10, prefix: /^3/ },
    '+94':  { name: 'Sri Lanka',  len: 9,  prefix: /^7/ },
    '+977': { name: 'Nepal',      len: 10, prefix: /^9/ },
    '+975': { name: 'Bhutan',     len: 8,  prefix: /^[17]/ },
    '+960': { name: 'Maldives',   len: 7,  prefix: /^[79]/ },
    '+966': { name: 'Saudi Arabia', len: 9, prefix: /^5/ },
    '+971': { name: 'UAE',        len: 9,  prefix: /^5/ },
    '+974': { name: 'Qatar',      len: 8,  prefix: /^[3-7]/ },
    '+965': { name: 'Kuwait',     len: 8,  prefix: /^[569]/ },
    '+968': { name: 'Oman',       len: 8,  prefix: /^[79]/ },
    '+973': { name: 'Bahrain',    len: 8,  prefix: /^[3-9]/ },
    '+1':   { name: 'US/Canada',  len: 10, prefix: /^[2-9]/ },
    '+44':  { name: 'United Kingdom', len: 10, prefix: /^[17]/ },
    '+61':  { name: 'Australia',  len: 9,  prefix: /^4/ },
    '+65':  { name: 'Singapore',  len: 8,  prefix: /^[689]/ },
    '+60':  { name: 'Malaysia',   len: 9,  prefix: /^1/ },
    '+81':  { name: 'Japan',      len: 10, prefix: /^[3-9]/ },
    '+82':  { name: 'South Korea', len: 10, prefix: /^1/ },
    '+86':  { name: 'China',      len: 11, prefix: /^1/ },
    '+852': { name: 'Hong Kong',  len: 8,  prefix: /^[5-9]/ },
    '+20':  { name: 'Egypt',      len: 10, prefix: /^1/ },
    '+27':  { name: 'South Africa', len: 9, prefix: /^[678]/ },
    '+33':  { name: 'France',     len: 9,  prefix: /^[1-9]/ },
    '+49':  { name: 'Germany',    len: 10, prefix: /^1/ },
    '+31':  { name: 'Netherlands', len: 9, prefix: /^6/ },
    '+39':  { name: 'Italy',      len: 10, prefix: /^[3]/ },
    '+34':  { name: 'Spain',      len: 9,  prefix: /^[6-9]/ },
    '+351': { name: 'Portugal',   len: 9,  prefix: /^9/ },
    '+7':   { name: 'Russia',     len: 10, prefix: /^[349]/ },
    '+90':  { name: 'Turkey',     len: 10, prefix: /^5/ },
    '+62':  { name: 'Indonesia',  len: 9,  prefix: /^8/ },
    '+63':  { name: 'Philippines', len: 10, prefix: /^9/ },
    '+66':  { name: 'Thailand',   len: 9,  prefix: /^[689]/ },
    '+84':  { name: 'Vietnam',    len: 9,  prefix: /^[3-9]/ },
    '+55':  { name: 'Brazil',     len: 11, prefix: /^[1-9]/ },
    '+52':  { name: 'Mexico',     len: 10, prefix: /^[1-9]/ },
    '+234': { name: 'Nigeria',    len: 10, prefix: /^[789]/ },
    '+254': { name: 'Kenya',      len: 9,  prefix: /^7/ },
  };
  const WEAK_PASSWORDS = ['password', 'password1', '123456', '1234567', '12345678', '123456789', '1234567890',
    'qwerty', 'qwerty123', 'abc123', 'iloveyou', 'admin', 'admin123', 'letmein', 'welcome', 'monkey',
    'dragon', '111111', '000000', '123123', '654321', '666666', '888888', 'krtaker', 'krtaker123', 'changeme', 'passw0rd'];

  function validPhone() {
    const cc = $('rCountry').value;
    const raw = $('rPhone').value.replace(/[\s\-()]/g, '');
    if (!raw) return false;
    if (!/^\d+$/.test(raw)) return false;             // digits only
    const rule = CC_RULES[cc];
    if (rule) return raw.length === rule.len && rule.prefix.test(raw);
    return raw.length >= 5 && raw.length <= 12;        // generic fallback
  }
  /* Build E.164-ish "+<cc><national>" (drop trunk leading 0, e.g. 017… → 17…) */
  function fullPhone() {
    const cc = $('rCountry').value.replace('+', '');
    const raw = $('rPhone').value.replace(/[\s\-()]/g, '');
    return '+' + cc + raw.replace(/^0+/, '');
  }

  function go(n) {
    steps.forEach((p, i) => $(p).classList.toggle('active', i === n - 1));
    [1, 2, 3, 4].forEach(i => {
      const seg = $('seg' + i), lab = $('lab' + i);
      if (!seg || !lab) return;
      seg.className = 'seg' + (i < n ? ' done' : i === n ? ' cur' : '');
      lab.classList.toggle('cur', i === n);
    });
    if (n === 2) $('rName').focus();
  }

  // Role selection
  document.querySelectorAll('.role-opt').forEach(opt => {
    opt.addEventListener('click', () => {
      document.querySelectorAll('.role-opt').forEach(o => o.classList.remove('selected'));
      opt.classList.add('selected');
      state.role = opt.dataset.role;
    });
  });

  // Navigation
  $('goStep2').addEventListener('click', () => go(2));
  $('backTo1').addEventListener('click', () => go(1));
  $('backTo2').addEventListener('click', () => go(2));

  // Account submit → register API (plan NOT collected — chosen later in dashboard)
  const refCode = new URLSearchParams(location.search).get('ref') || '';
  if (refCode) { try { localStorage.setItem('krtaker_ref', refCode); } catch (x) {} }
  $('acctForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = dict() || {};
    const name = $('rName').value.trim(), email = $('rEmail').value.trim().toLowerCase(), pass = $('rPass').value;
    const phoneRaw = $('rPhone').value.trim();
    const hasPhone = phoneRaw !== '';
    const hasEmail = email !== '';
    const show = (el, on) => el.classList.toggle('show', on);
    let ok = true;

    // At least one of email / phone is required
    if (!hasEmail && !hasPhone) {
      show($('errEmail'), true); show($('errPhone'), true);
      ok = false;
    } else {
      // Email validation (only if provided)
      if (hasEmail && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { show($('errEmail'), true); ok = false; }
      else show($('errEmail'), false);
      // Phone validation (only if provided)
      if (hasPhone && !validPhone()) { show($('errPhone'), true); ok = false; }
      else show($('errPhone'), false);
    }

    // Password: not weak (min 6, letter + number, not on blacklist)
    const weak = pass.length < 6 || !/[A-Za-z]/.test(pass) || !/[0-9]/.test(pass) || WEAK_PASSWORDS.includes(pass.toLowerCase());
    if (weak) { show($('errPass'), true); ok = false; } else show($('errPass'), false);

    // Terms & Privacy checkbox
    if (!$('rTerms').checked) {
      show($('errTerms'), true);
      $('termsWrap') && $('termsWrap').classList.add('err');
      ok = false;
    } else {
      show($('errTerms'), false);
      $('termsWrap') && $('termsWrap').classList.remove('err');
    }

    if (!ok) return;

    const btn = $('regSubmit');
    btn.disabled = true; btn.textContent = d['reg.working'] || 'Please wait…';
    try {
      const bg = window.krBG ? await window.krBG.attach('register') : {};
      const body = Object.assign({
        name, org: $('rOrg').value.trim(),
        email: hasEmail ? email : '',
        phone: hasPhone ? fullPhone() : '',
        role: state.role, pass, agree: '1',
      }, bg);
      const ref = refCode || (function(){ try { return localStorage.getItem('krtaker_ref') || ''; } catch (x) { return ''; } })();
      if (ref) body.ref = ref;
      const res = await fetch(API + 'register', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const data = await res.json();
      if (data.ok) {
        ga('sign_up', { method: 'email', role: state.role });
        state.email = email || '';
        $('otpEmail').textContent = email || (data.sent_to || '');
        startTimer(60);
        go(3);
        $('otpRow').querySelector('input').focus();
      } else {
        krToast(data.error || (d['reg.err.server'] || 'Something went wrong.'));
      }
    } catch (err) {
      krToast(d['reg.err.server'] || 'Network error — please try again.');
    }
    btn.disabled = false; btn.textContent = d['reg.next'] || 'Continue';
  });

  // OTP inputs: auto-advance + paste
  const otpInputs = () => Array.from($('otpRow').querySelectorAll('input'));
  otpInputs().forEach((inp, i) => {
    inp.addEventListener('input', () => {
      inp.value = inp.value.replace(/\D/g, '');
      if (inp.value && i < 5) otpInputs()[i + 1].focus();
      $('errOtp').classList.remove('show');
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && i > 0) otpInputs()[i - 1].focus();
    });
    inp.addEventListener('paste', (e) => {
      const txt = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
      if (txt) { otpInputs().forEach((o, j) => { o.value = txt[j] || ''; }); otpInputs()[Math.min(txt.length, 5)].focus(); e.preventDefault(); }
    });
  });

  function startTimer(secs) {
    state.resendIn = secs;
    clearInterval(state.timer);
    const render = () => {
      if (state.resendIn <= 0) {
        $('otpTimer').innerHTML = '<a id="otpResend" data-i18n="reg.otpResend">' + (dict() ? dict()['reg.otpResend'] : 'Resend code') + '</a>';
        const rl = $('otpResend');
        if (rl) rl.addEventListener('click', resendOtp);
        clearInterval(state.timer);
      } else {
        $('otpTimer').textContent = state.resendIn + 's · ';
        const a = document.createElement('a'); a.id = 'otpResend'; a.dataset.i18n = 'reg.otpResend';
        a.textContent = dict() ? dict()['reg.otpResend'] : 'Resend code';
        a.addEventListener('click', resendOtp);
        $('otpTimer').appendChild(a);
      }
    };
    render();
    state.timer = setInterval(() => { state.resendIn--; render(); }, 1000);
  }

  async function resendOtp() {
    try {
      const bg = window.krBG ? await window.krBG.attach('resend') : {};
      const res = await fetch(API + 'resend-otp', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ email: state.email }, bg)) });
      const data = await res.json();
      if (data.ok) { startTimer(60); krToast(dict() ? dict()['reg.otpSent'] : 'Code sent ✓'); }
      else krToast(data.error || 'Try again.');
    } catch (e) { krToast('Network error.'); }
  }

  $('otpVerify').addEventListener('click', async () => {
    const code = otpInputs().map(i => i.value).join('');
    if (code.length !== 6) { $('errOtp').classList.add('show'); return; }
    const btn = $('otpVerify');
    btn.disabled = true; btn.textContent = (dict() && dict()['reg.working']) || 'Please wait…';
    try {
      const res = await fetch(API + 'verify-otp', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: state.email, otp: code }) });
      const data = await res.json();
      if (data.ok) {
        ga('trial_started', { trial_days: data.trial_days || 14 });
        $('trialNote').textContent = (dict() ? dict()['reg.successSub'] : '') + ' ' + (data.trial_end || '');
        go(4);
      } else {
        $('errOtp').classList.add('show');
        otpInputs().forEach(i => { i.value = ''; });
        otpInputs()[0].focus();
      }
    } catch (e) { $('errOtp').classList.add('show'); }
    btn.disabled = false; btn.textContent = (dict() && dict()['reg.otpBtn']) || 'Verify & activate trial';
  });
})();
