/* KRTaker bot guard (v3.87) — zero-dependency honeypot + time-trap + proof-of-work.
   Exposes window.krBG.prove() → { hp:'', ft:<ms>, pow:<hex nonce> }.
   Turnstile-ready: if a widget rendered [name="cf-turnstile-response"], its token
   is appended automatically. Must load BEFORE the form scripts that call krBG.
   Difficulty MUST match the API admin_cfg bot_pow_bits (default 12). */
(function () {
  'use strict';
  var DIFFICULTY = 12; // leading zero bits
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
    var ft = Date.now();
    var win = Math.floor(ft / 1000 / 300); // 5-min window (matches API)
    var chal = String(win);
    var nonce = 0;
    function tryNonce() {
      return sha256Hex(chal + ':' + nonce.toString(16)).then(function (hex) {
        if (leadingZeroBits(hex) >= DIFFICULTY || nonce >= 400000) {
          var fields = { hp: '', ft: ft, pow: nonce.toString(16) };
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
  window.krBG = { prove: prove };
})();
