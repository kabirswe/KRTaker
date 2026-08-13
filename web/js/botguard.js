/* KRTaker bot guard (v3.88) — zero-dependency honeypot + time-trap + proof-of-work.
   Exposes window.krBG:
     prove()    → Promise<{hp:'', ft:<ms>, pow:<hex nonce>}>  (WebCrypto SHA-256)
     recaptcha(action) → Promise<token|''>  Google reCAPTCHA v3 — '' when the
                         site key is not configured (bot guard still active).
   Turnstile-ready: if a widget rendered [name="cf-turnstile-response"], its token
   is appended automatically. Must load BEFORE the form scripts that call krBG.
   Difficulty MUST match the API admin_cfg bot_pow_bits (default 12). */
(function () {
  'use strict';
  var DIFFICULTY = 12; // leading zero bits
  /* Time-trap anchor: captured at SCRIPT LOAD (= page load). The API rejects
     submissions where (server_time - ft) < 2000ms — a human always takes longer
     than 2s to fill a form; instant bot POSTs get rejected. */
  var _FT = Date.now();
  function sha256Hex(str) {
    return crypto.subtle.digest('SHA-256', new TextEncoder().encode(str))
      .then(function (buf) {
        var s = '';
        new Uint8Array(buf).forEach(function (b) { s += b.toString(16).padStart(2, '0'); });
        return s;
      });
  }
  function leadingZeroBits(hex) {
    var bits = 0;
    for (var i = 0; i < hex.length; i++) {
      var n = parseInt(hex[i], 16);
      if (n === 0) { bits += 4; continue; }
      for (var b = 3; b >= 0; b--) { if (n & (1 << b)) break; bits++; }
      break;
    }
    return bits;
  }
  function prove() {
    var now = Date.now();
    var win = Math.floor(now / 1000 / 300); // 5-min window at SUBMIT time (API checks ±1)
    var chal = String(win);
    var nonce = 0;
    function tryNonce() {
      return sha256Hex(chal + ':' + nonce.toString(16)).then(function (hex) {
        if (leadingZeroBits(hex) >= DIFFICULTY || nonce >= 400000) {
          var fields = { hp: '', ft: _FT, pow: nonce.toString(16) };
          try {
            var tok = document.querySelector('[name="cf-turnstile-response"]');
            if (tok && tok.value) fields['cf-turnstile-response'] = tok.value;
          } catch (e) {}
          return fields;
        }
        nonce++;
        return tryNonce();
      });
    }
    return tryNonce();
  }

  /* ── Google reCAPTCHA v3 (optional) ── */
  var _siteKey;
  function getSiteKey() {
    if (_siteKey !== undefined) return Promise.resolve(_siteKey);
    return fetch('/api/app-theme').then(function (r) { return r.json(); }).then(function (d) {
      _siteKey = (d && d.theme && d.theme.recaptcha_site_key) || '';
      return _siteKey;
    }).catch(function () { _siteKey = ''; return ''; });
  }
  function loadScript(src) {
    return new Promise(function (res, rej) {
      if (document.querySelector('script[src="' + src + '"]')) return res();
      var s = document.createElement('script');
      s.src = src; s.onload = res; s.onerror = rej; document.head.appendChild(s);
    });
  }
  function recaptcha(action) {
    return getSiteKey().then(function (key) {
      if (!key) return ''; // not configured → skip (bot guard still active)
      return loadScript('https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(key)).then(function () {
        return new Promise(function (resolve) {
          try {
            window.grecaptcha.ready(function () {
              window.grecaptcha.execute(key, { action: action || 'submit' })
                .then(function (tok) { resolve(tok); })
                .catch(function () { resolve(''); });
            });
          } catch (e) { resolve(''); }
        });
      }).catch(function () { return ''; });
    });
  }
  /* attach(): run both layers and merge into the payload object */
  function attach(action) {
    return Promise.all([prove(), recaptcha(action)]).then(function (r) {
      var fields = r[0];
      if (r[1]) fields['g-recaptcha-response'] = r[1];
      return fields;
    });
  }

  window.krBG = { prove: prove, recaptcha: recaptcha, attach: attach };
})();
